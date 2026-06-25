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

    protected $signature = 'atlas:task:maestro:retry {action : inspect|policy|evidence|history} {--packet-id=} {--json}';

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
        $packetId = (string) $this->option('packet-id');
        if ($packetId === '') {
            return $this->emit(['action' => 'inspect', 'outcome' => 'refused', 'reason' => 'packet_id_required'], 1);
        }
        $rows = $this->ledger->forTask($packetId);
        if ($rows === []) {
            return $this->emit(['action' => 'inspect', 'packet_id' => $packetId, 'last_decision' => null], 0);
        }
        $last = end($rows);

        return $this->emit([
            'action' => 'inspect',
            'packet_id' => $packetId,
            'last_decision' => [
                'decision' => (string) ($last['decision'] ?? ''),
                'policy_reason' => (string) ($last['policy_reason'] ?? ''),
                'attempt_index' => (int) ($last['attempt_index'] ?? 0),
                'reshape_fingerprint' => (string) ($last['reshape_fingerprint'] ?? ''),
            ],
        ], 0);
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
