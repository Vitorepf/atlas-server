<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackRetryPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackRetryReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroRetryEvidenceMiner;
use Illuminate\Console\Command;

/**
 * Operator surface for the maestro give-back retry loop. READ-ONLY: every action observes existing
 * state (policy config, ledger receipts, mined evidence) — NEVER writes to the ledger, NEVER calls
 * a provider, NEVER appends a receipt. Unknown action ⇒ exit code 2, error names the 4 valid actions.
 */
final class AtlasTaskMaestroRetryCommand extends Command
{
    private const VALID_ACTIONS = ['inspect', 'policy', 'evidence', 'history'];

    protected $signature = 'atlas:task:maestro:retry {action : inspect|policy|evidence|history} {--packet-id=} {--input=} {--json}';

    private const CAUSE_MISSING_SCOPE = 'missing_scope';

    private const CAUSE_PROTECTED_TARGET = 'protected_target';

    private const CAUSE_BASELINE_TEST_FAILURE = 'baseline_test_failure';

    private const CAUSE_UNCLEAR_ACCEPTANCE = 'unclear_acceptance';

    private const CAUSE_OTHER = 'other';

    private const CAUSE_KEYWORDS = [
        self::CAUSE_MISSING_SCOPE => ['missing_scope', 'missing_file', 'no_allowed_files', 'scope_incomplete'],
        self::CAUSE_PROTECTED_TARGET => ['protected_target', 'forbidden_target', 'pétreo', 'petreo'],
        self::CAUSE_BASELINE_TEST_FAILURE => ['baseline_test_failure', 'baseline_red', 'pre_existing_failure'],
        self::CAUSE_UNCLEAR_ACCEPTANCE => ['unclear_acceptance', 'vague_acceptance', 'ambiguous_acceptance'],
    ];

    protected $description = 'Read-only inspector for the maestro give-back retry loop (policy / receipts / mined evidence).';

    public function __construct(
        private readonly AtlasMaestroGiveBackRetryPolicy $policy,
        private readonly AtlasMaestroGiveBackRetryReceiptLedger $ledger,
        private readonly AtlasMaestroRetryEvidenceMiner $miner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return $this->emit([
                'action' => $action,
                'outcome' => 'refused',
                'reason' => 'unknown_action',
                'valid_actions' => self::VALID_ACTIONS,
            ], 2);
        }

        return match ($action) {
            'inspect' => $this->doInspect(),
            'policy' => $this->doPolicy(),
            'evidence' => $this->doEvidence(),
            'history' => $this->doHistory(),
        };
    }

    private function doInspect(): int
    {
        $inputPath = (string) $this->option('input');
        $rootCauseSummary = $inputPath !== '' && is_file($inputPath)
            ? $this->rootCauseSummary($inputPath)
            : null;

        $packetId = (string) $this->option('packet-id');
        if ($packetId === '') {
            if ($rootCauseSummary !== null) {
                return $this->emit(['action' => 'inspect', 'root_cause_summary' => $rootCauseSummary], 0);
            }

            return $this->emit(['action' => 'inspect', 'outcome' => 'refused', 'reason' => 'packet_id_required'], 1);
        }
        $rows = $this->ledger->forTask($packetId);
        $payload = ['action' => 'inspect', 'packet_id' => $packetId];
        if ($rootCauseSummary !== null) {
            $payload['root_cause_summary'] = $rootCauseSummary;
        }
        if ($rows === []) {
            $payload['last_decision'] = null;

            return $this->emit($payload, 0);
        }
        $last = end($rows);
        $payload['last_decision'] = [
            'decision' => (string) ($last['decision'] ?? ''),
            'policy_reason' => (string) ($last['policy_reason'] ?? ''),
            'attempt_index' => (int) ($last['attempt_index'] ?? 0),
            'reshape_fingerprint' => (string) ($last['reshape_fingerprint'] ?? ''),
        ];

        return $this->emit($payload, 0);
    }

    /**
     * Advisory-only, read-only give_back root-cause summary. Groups repeated give_back events by
     * missing scope, protected target, baseline test failure, and unclear acceptance — NEVER
     * enqueues a retry or writes to the ledger; the caller (Queue Self-Healing) decides what to do
     * with the grouping.
     *
     * @return array<string,mixed>
     */
    private function rootCauseSummary(string $inputPath): array
    {
        $decoded = json_decode((string) file_get_contents($inputPath), true);
        $giveBacks = is_array($decoded['give_backs'] ?? null) ? $decoded['give_backs'] : [];

        $counts = array_fill_keys([
            self::CAUSE_MISSING_SCOPE,
            self::CAUSE_PROTECTED_TARGET,
            self::CAUSE_BASELINE_TEST_FAILURE,
            self::CAUSE_UNCLEAR_ACCEPTANCE,
            self::CAUSE_OTHER,
        ], 0);

        foreach ($giveBacks as $event) {
            if (! is_array($event)) {
                continue;
            }
            $reason = strtolower(trim((string) ($event['root_cause'] ?? $event['reason'] ?? '')));
            $counts[$this->classifyRootCause($reason)]++;
        }

        return [
            'total_give_backs' => array_sum($counts),
            'by_cause' => $counts,
            'advisory_only' => true,
            'auto_retry_enqueued' => false,
        ];
    }

    private function classifyRootCause(string $reason): string
    {
        if ($reason === '') {
            return self::CAUSE_OTHER;
        }
        foreach (self::CAUSE_KEYWORDS as $cause => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($reason, $keyword)) {
                    return $cause;
                }
            }
        }

        return self::CAUSE_OTHER;
    }

    private function doPolicy(): int
    {
        return $this->emit([
            'action' => 'policy',
            'policy' => [
                'max_retries' => $this->maxRetriesOf($this->policy),
                'fingerprint_algo' => 'sha256_of_structural_delta',
                'cooldown_until_unix_default' => 0,
            ],
        ], 0);
    }

    private function doEvidence(): int
    {
        $facts = $this->miner->mine([], []);
        $rows = array_map(static fn (object $fact): array => $fact->toArray(), $facts);

        return $this->emit([
            'action' => 'evidence',
            'facts' => $rows,
        ], 0);
    }

    private function doHistory(): int
    {
        $packetId = (string) $this->option('packet-id');
        if ($packetId === '') {
            return $this->emit(['action' => 'history', 'outcome' => 'refused', 'reason' => 'packet_id_required'], 1);
        }

        return $this->emit([
            'action' => 'history',
            'packet_id' => $packetId,
            'receipts' => $this->ledger->forTask($packetId),
        ], 0);
    }

    private function maxRetriesOf(AtlasMaestroGiveBackRetryPolicy $policy): int
    {
        $ref = new \ReflectionClass($policy);
        if (! $ref->hasProperty('maxRetries')) {
            return AtlasMaestroGiveBackRetryPolicy::DEFAULT_MAX_RETRIES;
        }
        $prop = $ref->getProperty('maxRetries');
        $prop->setAccessible(true);

        return (int) $prop->getValue($policy);
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
