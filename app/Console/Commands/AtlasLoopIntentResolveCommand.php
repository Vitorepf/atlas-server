<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityClarifierProposer;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityFollowUpScheduler;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityResolutionLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator CLI for the intent-ambiguity resolution loop. A thin veneer over three injected
 * services: the proposer (builds canonical question packets), the ledger (append-only
 * resolution chain), and the follow-up scheduler (the open-pending set). NEVER calls a
 * provider, NEVER schedules anything, NEVER originates an ambiguity. Fail-closed on every
 * service throw (non-zero exit).
 *
 * Intents source: container binding {@see INTENTS_SOURCE_BINDING} — a callable returning
 *   list<array{intent_id:string, text:string, capture_at_utc:string,
 *     enumerated_tokens?:list<string>,
 *     ambiguities:list<array{ambiguity_finding_id:string, dimension:string, source_span:string,
 *       question_hash:string}>}>
 */
final class AtlasLoopIntentResolveCommand extends Command
{
    public const INTENTS_SOURCE_BINDING = 'atlas.loop.intent_resolver.intents_source';

    protected $signature = 'atlas:loop:intent:resolve {action : propose|resolve|pending} {--intent= : intent_id} {--question= : question_hash} {--choice= : candidate string or __unknown__} {--json}';

    protected $description = 'Operator surface for the intent ambiguity resolution loop (propose | pending | resolve).';

    public function __construct(
        private readonly AtlasLoopIntentAmbiguityClarifierProposer $proposer,
        private readonly AtlasLoopIntentAmbiguityResolutionLedger $ledger,
        private readonly AtlasLoopIntentAmbiguityFollowUpScheduler $scheduler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        try {
            return match ($action) {
                'propose' => $this->doPropose(),
                'pending' => $this->doPending(),
                'resolve' => $this->doResolve(),
                default => $this->emit(['action' => $action, 'outcome' => 'refused', 'reason' => 'unknown_action'], 1),
            };
        } catch (Throwable $e) {
            return $this->emit([
                'action' => $action,
                'outcome' => 'refused',
                'reason' => 'service_error',
                'message' => $e->getMessage(),
            ], 1);
        }
    }

    private function doPropose(): int
    {
        $intentId = (string) $this->option('intent');
        if ($intentId === '') {
            return $this->emit(['action' => 'propose', 'outcome' => 'refused', 'reason' => 'intent_required'], 1);
        }
        $intent = $this->findIntent($intentId);
        if ($intent === null) {
            return $this->emit(['action' => 'propose', 'outcome' => 'refused', 'reason' => 'intent_not_found'], 1);
        }

        $findings = array_map(static fn (array $a): array => [
            'ambiguity_finding_id' => (string) ($a['ambiguity_finding_id'] ?? ''),
            'dimension' => (string) ($a['dimension'] ?? ''),
            'source_span' => (string) ($a['source_span'] ?? ''),
        ], (array) ($intent['ambiguities'] ?? []));

        $ledgerSnapshot = $this->ledger->snapshotForIntent($intentId);
        $packets = $this->proposer->propose($intent, $findings, $ledgerSnapshot);

        return $this->emit([
            'action' => 'propose',
            'intent_id' => $intentId,
            'packets' => $packets,
        ], 0);
    }

    private function doPending(): int
    {
        return $this->emit([
            'action' => 'pending',
            'rows' => $this->scheduler->pending(),
        ], 0);
    }

    private function doResolve(): int
    {
        $intentId = (string) $this->option('intent');
        $questionHash = (string) $this->option('question');
        $choice = $this->option('choice');
        if ($intentId === '' || $questionHash === '' || $choice === null || $choice === '') {
            return $this->emit(['action' => 'resolve', 'outcome' => 'refused', 'reason' => 'missing_required_options'], 1);
        }
        $intent = $this->findIntent($intentId);
        if ($intent === null) {
            return $this->emit(['action' => 'resolve', 'outcome' => 'refused', 'reason' => 'intent_not_found'], 1);
        }

        $findingId = '';
        foreach ((array) ($intent['ambiguities'] ?? []) as $a) {
            if ((string) ($a['question_hash'] ?? '') === $questionHash) {
                $findingId = (string) ($a['ambiguity_finding_id'] ?? '');
                break;
            }
        }
        if ($findingId === '') {
            return $this->emit(['action' => 'resolve', 'outcome' => 'refused', 'reason' => 'question_hash_not_in_open_set'], 1);
        }

        $chosen = $choice === '__unknown__' ? null : (string) $choice;
        $row = $this->ledger->append(
            intentId: $intentId,
            ambiguityFindingId: $findingId,
            questionHash: $questionHash,
            chosenCandidate: $chosen,
            resolvedAtUtc: gmdate('Y-m-d\TH:i:s\Z'),
            operatorSignatureHash: hash('sha256', $questionHash.'|'.(string) $chosen),
            expectedPriorRowHash: $this->ledger->tailHash(),
        );

        return $this->emit([
            'action' => 'resolve',
            'outcome' => 'resolved',
            'row' => $row,
            'pending_after' => $this->scheduler->pending(),
        ], 0);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findIntent(string $intentId): ?array
    {
        if (! $this->getLaravel()->bound(self::INTENTS_SOURCE_BINDING)) {
            return null;
        }
        $source = $this->getLaravel()->make(self::INTENTS_SOURCE_BINDING);
        if (! is_callable($source)) {
            return null;
        }
        foreach ((array) $source() as $intent) {
            if (is_array($intent) && (string) ($intent['intent_id'] ?? '') === $intentId) {
                return $intent;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $sorted = $this->sortRecursive($payload);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($sorted, $flags));

        return $exit;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->sortRecursive($v);
        }

        return $value;
    }
}
