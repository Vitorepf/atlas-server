<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Certifies the output of the Agent Control Plane Runtime Pilot Orchestrator.
 * Validates: task packet shape, claim/lease simulation, scope lock safety,
 * evidence ledger dry-run completeness, continuation summary completeness,
 * work product manifest completeness, cost import dry-run completeness,
 * multi-agent parallelism plan safety, all runtime safety flags false, no
 * planned ledger writes, no planned provider calls, no planned dispatch,
 * no planned adapter execution, no planned self-programming.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneRuntimePilotCertificationService
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_runtime_pilot_certification.v1';

    public const MODE = 'read_only_agent_control_plane_runtime_pilot_certification';

    /**
     * @param  array<string, mixed>  $pilot
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $pilot, array $options = []): array
    {
        $checks = [];
        $failures = [];

        $taskPacket = (array) ($pilot['task_packet'] ?? []);
        $claimLease = (array) ($pilot['claim_lease_simulation'] ?? []);
        $scopeLock = (array) ($pilot['scope_lock_plan'] ?? []);
        $evidence = (array) ($pilot['evidence_ledger_dry_run'] ?? []);
        $continuation = (array) ($pilot['continuation_summary'] ?? []);
        $workProduct = (array) ($pilot['work_product_manifest_plan'] ?? []);
        $cost = (array) ($pilot['cost_import_dry_run'] ?? []);
        $parallelism = (array) ($pilot['multi_agent_parallelism_plan'] ?? []);

        $checks['task_packet_valid'] = $this->record(
            $failures,
            'task_packet_valid',
            (string) ($taskPacket['status'] ?? '') === 'planned'
                && (string) ($taskPacket['task_packet_hash'] ?? '') !== ''
                && (string) ($taskPacket['scope_hash'] ?? '') !== '',
            'task_packet_invalid_or_blocked',
        );

        $checks['claim_lease_simulated'] = $this->record(
            $failures,
            'claim_lease_simulated',
            (string) ($claimLease['lease_status'] ?? '') === 'simulated_granted',
            'claim_lease_not_granted',
        );

        $checks['scope_lock_safe'] = $this->record(
            $failures,
            'scope_lock_safe',
            (string) ($scopeLock['status'] ?? '') === 'planned_safe'
                && (array) ($scopeLock['cross_axis_blockers'] ?? []) === []
                && (array) ($scopeLock['unsafe_path_blockers'] ?? []) === [],
            'scope_lock_unsafe',
        );

        $checks['evidence_ledger_dry_run_complete'] = $this->record(
            $failures,
            'evidence_ledger_dry_run_complete',
            (int) ($evidence['receipt_count'] ?? 0) >= 7
                && (bool) ($evidence['ledger_write_allowed'] ?? false) === false
                && (bool) ($evidence['dry_run_only'] ?? false) === true,
            'evidence_ledger_dry_run_incomplete',
        );

        $checks['continuation_summary_complete'] = $this->record(
            $failures,
            'continuation_summary_complete',
            (string) ($continuation['continuation_hash'] ?? '') !== ''
                && (array) ($continuation['next_actions'] ?? []) !== [],
            'continuation_summary_incomplete',
        );

        $checks['work_product_manifest_complete'] = $this->record(
            $failures,
            'work_product_manifest_complete',
            (string) ($workProduct['status'] ?? '') === 'planned'
                && (bool) ($workProduct['collection_allowed'] ?? true) === false
                && (bool) ($workProduct['automatic_collection_runtime_enabled'] ?? true) === false,
            'work_product_manifest_incomplete',
        );

        $checks['cost_import_dry_run_complete'] = $this->record(
            $failures,
            'cost_import_dry_run_complete',
            (string) ($cost['status'] ?? '') === 'planned'
                && (bool) ($cost['automatic_cost_import_runtime_enabled'] ?? true) === false
                && (bool) ($cost['token_spend_allowed'] ?? true) === false,
            'cost_import_dry_run_incomplete',
        );

        $checks['multi_agent_plan_safe'] = $this->record(
            $failures,
            'multi_agent_plan_safe',
            (string) ($parallelism['status'] ?? '') === 'planned',
            'multi_agent_plan_unsafe',
        );

        $runtimeSafe = (bool) ($pilot['runtime_execution_allowed'] ?? true) === false
            && (bool) ($pilot['dispatch_allowed'] ?? true) === false
            && (bool) ($pilot['provider_call_allowed'] ?? true) === false
            && (bool) ($pilot['token_spend_allowed'] ?? true) === false
            && (bool) ($pilot['self_programming_allowed'] ?? true) === false
            && (bool) ($pilot['ledger_write_allowed'] ?? true) === false
            && (bool) ($pilot['completion_claim_allowed'] ?? true) === false;
        $checks['runtime_safety_all_false'] = $this->record($failures, 'runtime_safety_all_false', $runtimeSafe, 'runtime_safety_flag_true');

        $checks['no_ledger_write'] = $this->record(
            $failures,
            'no_ledger_write',
            (bool) ($evidence['ledger_write_allowed'] ?? true) === false,
            'ledger_write_allowed_true',
        );

        $checks['no_provider_call'] = $this->record(
            $failures,
            'no_provider_call',
            (bool) ($pilot['provider_call_allowed'] ?? true) === false
                && (bool) ($cost['provider_call_allowed'] ?? true) === false,
            'provider_call_allowed_true',
        );

        $checks['no_dispatch'] = $this->record(
            $failures,
            'no_dispatch',
            (bool) ($pilot['dispatch_allowed'] ?? true) === false,
            'dispatch_allowed_true',
        );

        $checks['no_adapter_execution'] = $this->record(
            $failures,
            'no_adapter_execution',
            (bool) ($pilot['runtime_execution_allowed'] ?? true) === false,
            'adapter_execution_allowed',
        );

        $checks['no_self_programming'] = $this->record(
            $failures,
            'no_self_programming',
            (bool) ($pilot['self_programming_allowed'] ?? true) === false,
            'self_programming_allowed_true',
        );

        $checks['chain_integrity_summary_present'] = $this->record(
            $failures,
            'chain_integrity_summary_present',
            isset($pilot['chain_integrity_summary'])
                && (bool) data_get($pilot, 'chain_integrity_summary.runtime_safety_all_false', false),
            'chain_integrity_summary_missing_or_unsafe',
        );

        $checks['replay_summary_present'] = $this->record(
            $failures,
            'replay_summary_present',
            isset($pilot['replay_summary'])
                && data_get($pilot, 'replay_summary.replay_hash') !== null,
            'replay_summary_missing_or_unsafe',
        );

        $status = $failures === [] ? 'available' : 'blocked';
        if ((string) ($pilot['status'] ?? 'unknown') === 'blocked' && $status === 'available') {
            $status = 'warning';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'certification_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'checks' => $checks,
            'check_count' => count($checks),
            'passed_count' => count(array_filter($checks)),
            'failed_count' => count($checks) - count(array_filter($checks)),
            'failures' => $failures,
            'pilot_hash' => (string) ($pilot['pilot_hash'] ?? ''),
            'pilot_status' => (string) ($pilot['status'] ?? 'unknown'),
            'read_only' => true,
            'runtime_disabled' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'runtime_pilot_certification_does_not_start_codex',
                'runtime_pilot_certification_does_not_call_codex_cli_or_app',
                'runtime_pilot_certification_does_not_spawn_subprocess',
                'runtime_pilot_certification_does_not_invoke_adapter',
                'runtime_pilot_certification_does_not_call_provider',
                'runtime_pilot_certification_does_not_dispatch_work',
                'runtime_pilot_certification_does_not_spend_tokens',
                'runtime_pilot_certification_does_not_enable_self_programming',
                'runtime_pilot_certification_does_not_write_ledger',
                'runtime_pilot_certification_does_not_mutate_pointer',
                'runtime_pilot_certification_does_not_promote_completion_claim',
            ],
            'human_summary' => sprintf(
                'Runtime pilot certification status %s (%d/%d checks passed).',
                $status,
                count(array_filter($checks)),
                count($checks),
            ),
        ];

        $payload['certification_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function record(array &$failures, string $check, bool $passed, string $failureCode): bool
    {
        if (! $passed) {
            $failures[] = $failureCode;
        }

        return $passed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['certification_id'], $clone['generated_at'], $clone['certification_hash'], $clone['human_summary'], $clone['pilot_hash']);

        return $this->recursivelyKsort($clone);
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
