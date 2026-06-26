<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Plans how a runtime pilot WOULD import provider cost/token events for a
 * task packet, with a budget policy, a token/provider budget, a cost-meter
 * plan and the import sources to consult. Automatic cost import at runtime
 * is disabled; token spend is forbidden.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneCostImportDryRun
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_cost_import_dry_run.v1';

    public const MODE = 'read_only_agent_control_plane_cost_import_dry_run';

    public const DEFAULT_IMPORT_SOURCES = [
        'manual_cost_event_writer',
        'provider_token_usage_meter',
        'workspace_cost_audit_log',
    ];

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $taskPacket, array $options = []): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $tokenBudget = (int) data_get($taskPacket, 'cost_budget_requirements.max_token_budget', 0);
        $runtimeBudget = (int) data_get($taskPacket, 'cost_budget_requirements.max_runtime_seconds', 0);

        $providerBudget = [
            'max_token_budget' => $tokenBudget,
            'max_runtime_seconds' => $runtimeBudget,
            'token_spend_allowed' => false,
            'provider_call_allowed' => false,
        ];

        $budgetPolicy = [
            'enforce' => true,
            'hard_cap_runtime_enabled' => false,
            'overflow_strategy' => 'block_and_report',
            'token_spend_allowed' => false,
        ];

        $importSources = array_values(array_unique(array_merge(self::DEFAULT_IMPORT_SOURCES, (array) ($options['import_sources'] ?? []))));

        $costMeterPlan = [
            'meter_kind' => 'token_and_wallclock',
            'sampling_interval_seconds' => (int) ($options['sampling_interval_seconds'] ?? 60),
            'persistence_runtime_enabled' => false,
            'enforcement_runtime_enabled' => false,
        ];

        $blockingReasons = [];
        if ((string) ($taskPacket['status'] ?? 'unknown') !== 'planned') {
            $blockingReasons[] = 'task_packet_not_planned';
        }

        $status = $blockingReasons === [] ? 'planned' : 'planned_blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'cost_import_plan_id' => (string) Str::uuid(),
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'budget_policy' => $budgetPolicy,
            'token_budget' => $tokenBudget,
            'provider_budget' => $providerBudget,
            'cost_meter_plan' => $costMeterPlan,
            'import_sources' => $importSources,
            'import_source_count' => count($importSources),
            'automatic_cost_import_runtime_enabled' => false,
            'token_spend_allowed' => false,
            'blocking_reasons' => $blockingReasons,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'persistence_allowed' => false,
            'non_execution_guarantees' => [
                'cost_import_dry_run_does_not_start_codex',
                'cost_import_dry_run_does_not_call_codex_cli_or_app',
                'cost_import_dry_run_does_not_spawn_subprocess',
                'cost_import_dry_run_does_not_invoke_adapter',
                'cost_import_dry_run_does_not_call_provider',
                'cost_import_dry_run_does_not_dispatch_work',
                'cost_import_dry_run_does_not_spend_tokens',
                'cost_import_dry_run_does_not_read_provider_billing_apis',
                'cost_import_dry_run_does_not_enable_self_programming',
                'cost_import_dry_run_does_not_write_cost_events',
                'cost_import_dry_run_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Cost import plan for packet %s: token_budget=%d, runtime_budget=%ds, %d import sources.',
                $packetId,
                $tokenBudget,
                $runtimeBudget,
                count($importSources),
            ),
        ];

        $payload['cost_import_plan_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['cost_import_plan_id'], $clone['generated_at'], $clone['cost_import_plan_hash'], $clone['human_summary'], $clone['task_packet_id']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        return ReadinessHash::ksortRecursive($value);
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
