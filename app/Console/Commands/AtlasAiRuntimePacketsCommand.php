<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiRuntimePacketsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Runtime Packets contract guard CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-runtime-packets
 *     [--type=memory_delta]
 *     [--trace-id=trc_123]
 *     [--envelope-id=env_123]
 *     [--evidence=ledger:abc,trace:def]   // comma-separated evidence refs
 *     [--tool-tier=t2]                     // t0|t1|t2|t3
 *     [--operation-kind=file_write]        // shell_mutate|file_write|network
 *     [--policy]                           // a policy/permission scope is attached
 *     [--reviewed]                         // memory_delta passed review
 *     [--provider-safe]                    // memory_delta cleared provider-safety
 *     [--map=dev_execution]                // resolve a legacy type's canonical contract instead
 *     [--json]
 *
 * Read-only, deterministic. Emits the accept/reject verdict + receipt, or — when
 * --map is given — the legacy->canonical mapping.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-runtime-packets.md
 */
class AtlasAiRuntimePacketsCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-runtime-packets
        {--type= : packet kind (dev_execution|tool_event|permission_session|memory_delta|router_decision|completion)}
        {--trace-id= : trace anchor for the packet}
        {--envelope-id= : operation envelope anchor for the packet}
        {--evidence= : comma-separated evidence refs}
        {--tool-tier= : t0|t1|t2|t3 for tool/shell operations}
        {--operation-kind= : shell_mutate|file_write|network|...}
        {--policy : mark that a policy/permission scope is attached}
        {--reviewed : memory_delta passed review}
        {--provider-safe : memory_delta cleared provider-safety}
        {--ledger-replayable : completion packet is backed by a replayable ledger}
        {--map= : resolve a legacy packet type to its canonical contract instead of validating}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas runtime · validate a packet/projection against the runtime-packets invariants (accept|reject) or map a legacy packet type.';

    public function handle(AtlasAiRuntimePacketsService $service): int
    {
        try {
            $map = $this->option('map');
            if (is_string($map) && trim($map) !== '') {
                $this->line((string) json_encode(
                    ['ok' => true, 'mapping' => $service->resolveMapping($map)],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

                return self::SUCCESS;
            }

            $evidenceOpt = $this->option('evidence');
            $evidence = is_string($evidenceOpt) && trim($evidenceOpt) !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $evidenceOpt)), static fn ($v) => $v !== ''))
                : [];

            $decision = $service->validate([
                'type' => $this->option('type') ?? 'dev_execution',
                'trace_id' => $this->option('trace-id') ?? '',
                'envelope_id' => $this->option('envelope-id') ?? '',
                'evidence_refs' => $evidence,
                'tool_tier' => $this->option('tool-tier') ?? '',
                'operation_kind' => $this->option('operation-kind') ?? '',
                'has_policy' => (bool) $this->option('policy'),
                'reviewed' => (bool) $this->option('reviewed'),
                'provider_safe' => (bool) $this->option('provider-safe'),
                'ledger_replayable' => (bool) $this->option('ledger-replayable'),
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'runtime_packets_guard_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
