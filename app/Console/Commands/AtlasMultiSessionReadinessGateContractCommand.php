<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMultiSessionReadinessGateContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Multi-Session Readiness Gate Contract.
 *
 * With safe defaults (no packets, no reservation ledger) it emits the
 * deterministic gate, which lands on `preview_only_single_session` and reports
 * that durable dispatch is off. Proves the doc's contract is live: the gate is a
 * read-only decider that never starts sessions or grants durable dispatch.
 *
 * @see docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
 */
class AtlasMultiSessionReadinessGateContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:multi-session-readiness-gate-contract {--json : Print machine-readable JSON}';

    protected $description = 'Emit the deterministic read-only multi-session readiness gate decision (never starts sessions).';

    public function handle(AtlasMultiSessionReadinessGateContractService $service): int
    {
        try {
            // Safe defaults: no candidate packets and no reservation ledger, so the
            // gate resolves to the single-session preview value with durable
            // dispatch off — exactly what the contract mandates today.
            $payload = $service->evaluate([], []);
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasMultiSessionReadinessGateContractService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('decision', (string) $payload['decision']);
        $this->components->twoColumnDetail('durable_dispatch_enabled', $payload['durable_dispatch_enabled'] ? 'true' : 'false');
        $this->components->twoColumnDetail('starts_sessions', $payload['starts_sessions'] ? 'true' : 'false');
        $this->components->twoColumnDetail('blocker_count', (string) count($payload['blockers']));
        $this->components->twoColumnDetail('safe_next_instruction', (string) $payload['safe_next_instruction']);

        return self::SUCCESS;
    }
}
