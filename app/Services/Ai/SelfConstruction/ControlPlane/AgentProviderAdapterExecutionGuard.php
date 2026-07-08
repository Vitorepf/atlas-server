<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentProviderAdapterExecutionGuard
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public const BLOCK_REASON_ADAPTER_NOT_READY = 'adapter_not_ready';
    public const BLOCK_REASON_TASK_FAMILY_UNSUPPORTED = 'task_family_unsupported';
    public const BLOCK_REASON_SCOPE_UNSAFE = 'scope_unsafe';
    public const BLOCK_REASON_EVIDENCE_POLICY_NOT_SATISFIED = 'evidence_policy_not_satisfied';
    public const BLOCK_REASON_FALLBACK_UNAVAILABLE = 'fallback_unavailable';

    private const REPAIR_HINTS = [
        self::BLOCK_REASON_ADAPTER_NOT_READY => 'wait_for_adapter_capability_check_to_pass_before_retrying',
        self::BLOCK_REASON_TASK_FAMILY_UNSUPPORTED => 'route_to_an_adapter_that_supports_this_task_family_or_extend_its_supported_list',
        self::BLOCK_REASON_SCOPE_UNSAFE => 'narrow_or_repair_scope_to_clear_forbidden_axis_or_overlap_hits_before_retrying',
        self::BLOCK_REASON_EVIDENCE_POLICY_NOT_SATISFIED => 'attach_the_required_evidence_before_allowing_start',
        self::BLOCK_REASON_FALLBACK_UNAVAILABLE => 'configure_a_fallback_route_before_allowing_an_unsafe_primary_start',
    ];

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AgentProviderAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * Pure pre-flight evaluator: decides whether external provider execution is safe to start,
     * checking adapter readiness, task-family fit, scope safety, evidence policy and fallback
     * availability — BEFORE any process is started, rather than relying on runtime failure to
     * surface the problem. Does not touch the database or the ledger.
     *
     * Block-reason priority (first failing check wins for block_reason/required_repair_hint, but
     * every failing check is recorded in block_reasons):
     *   1. adapter_not_ready
     *   2. task_family_unsupported   — only checked when supported_task_families is non-empty
     *   3. scope_unsafe
     *   4. evidence_policy_not_satisfied
     *   5. fallback_unavailable
     *
     * INPUT:
     *   adapter_ready?: bool (default true)
     *   task_family?: string
     *   supported_task_families?: list<string>
     *   scope_safe?: bool (default true)
     *   evidence_policy_satisfied?: bool (default true)
     *   fallback_available?: bool (default true)
     *
     * OUTPUT:
     *   { allow_start, block_reason, block_reasons, required_repair_hint }
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluateExecutionStart(array $input): array
    {
        $adapterReady = (bool) ($input['adapter_ready'] ?? true);
        $taskFamily = (string) ($input['task_family'] ?? '');
        $supportedTaskFamilies = array_values(array_map('strval', (array) ($input['supported_task_families'] ?? [])));
        $scopeSafe = (bool) ($input['scope_safe'] ?? true);
        $evidencePolicySatisfied = (bool) ($input['evidence_policy_satisfied'] ?? true);
        $fallbackAvailable = (bool) ($input['fallback_available'] ?? true);

        $blockReasons = [];

        if (! $adapterReady) {
            $blockReasons[] = self::BLOCK_REASON_ADAPTER_NOT_READY;
        }

        if ($supportedTaskFamilies !== [] && ! in_array($taskFamily, $supportedTaskFamilies, true)) {
            $blockReasons[] = self::BLOCK_REASON_TASK_FAMILY_UNSUPPORTED;
        }

        if (! $scopeSafe) {
            $blockReasons[] = self::BLOCK_REASON_SCOPE_UNSAFE;
        }

        if (! $evidencePolicySatisfied) {
            $blockReasons[] = self::BLOCK_REASON_EVIDENCE_POLICY_NOT_SATISFIED;
        }

        if (! $fallbackAvailable) {
            $blockReasons[] = self::BLOCK_REASON_FALLBACK_UNAVAILABLE;
        }

        $primaryReason = $blockReasons[0] ?? null;

        return [
            'allow_start' => $blockReasons === [],
            'block_reason' => $primaryReason,
            'block_reasons' => $blockReasons,
            'required_repair_hint' => $primaryReason !== null ? self::REPAIR_HINTS[$primaryReason] : null,
            'provider_contract' => $blockReasons === [] ? [
                'provider' => (string) ($input['provider'] ?? ''),
                'adapter' => (string) ($input['adapter'] ?? ''),
                'task_family' => $taskFamily,
            ] : null,
            'fallback_policy' => [
                'fallback_available' => $fallbackAvailable,
                'fallback_adapter' => $fallbackAvailable ? (string) ($input['fallback_adapter'] ?? 'default') : null,
            ],
            'evidence_freshness' => [
                'evidence_policy_satisfied' => $evidencePolicySatisfied,
                'evidence_age_seconds' => (int) ($input['evidence_age_seconds'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function blockUntilProviderSpecificContract(array $input): array
    {
        $normalized = $this->normalize($input);

        if (! Schema::hasTable('atlas_self_construction_agent_runs')) {
            throw new InvalidArgumentException('atlas_self_construction_agent_runs_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }

        return DB::transaction(function () use ($normalized): array {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $run->metadata;
            $existingGuardId = (string) data_get($metadata, 'provider_adapter_execution_guard.execution_guard_id', '');

            if ($existingGuardId !== '') {
                if ($existingGuardId !== $normalized['execution_guard_id']) {
                    throw new InvalidArgumentException('provider_adapter_execution_guard_already_recorded');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertAdapterInvocationPrepared($run, $metadata, $normalized);

            $descriptor = $this->adapterRegistry->resolve($normalized['provider'], $normalized['adapter']);
            $descriptorHash = $this->adapterRegistry->descriptorHash($descriptor);

            if ((string) data_get($metadata, 'adapter_invocation.adapter_descriptor_hash') !== $descriptorHash) {
                throw new InvalidArgumentException('adapter_descriptor_hash_mismatch');
            }

            if ((bool) $descriptor['external_process_start_enabled'] || (bool) $descriptor['token_spend_enabled']) {
                throw new InvalidArgumentException('provider_adapter_descriptor_not_execution_safe');
            }

            $metadata['provider_adapter_execution_guard'] = [
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider' => $normalized['provider'],
                'adapter' => $normalized['adapter'],
                'adapter_id' => $descriptor['adapter_id'],
                'adapter_descriptor_hash' => $descriptorHash,
                'status' => 'blocked_pending_provider_specific_execution_contract',
                'blocked_by' => 'provider_specific_execution_contract_missing',
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Provider adapter execution guard recorded; external provider execution remains blocked.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_provider_adapter.execution_blocked',
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => $normalized['provider'],
                'adapter' => $normalized['adapter'],
                'adapter_id' => $descriptor['adapter_id'],
                'adapter_descriptor_hash' => $descriptorHash,
                'blocked_by' => 'provider_specific_execution_contract_missing',
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_provider_adapter_execution_guard',
                'receipt_id' => $normalized['execution_guard_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-provider-adapter-execution-guard.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'run_key',
            'execution_guard_id',
            'adapter_invocation_id',
            'provider',
            'adapter',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider' => strtolower(trim((string) $input['provider'])),
            'adapter' => strtolower(trim((string) $input['adapter'])),
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertAdapterInvocationPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('agent_run_provider_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.adapter_invocation_id') !== $normalized['adapter_invocation_id']) {
            throw new InvalidArgumentException('adapter_invocation_id_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.provider') !== $normalized['provider']) {
            throw new InvalidArgumentException('adapter_invocation_provider_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.adapter') !== $normalized['adapter']) {
            throw new InvalidArgumentException('adapter_invocation_adapter_mismatch');
        }

        if ((bool) data_get($metadata, 'adapter_invocation.external_process_started', false)) {
            throw new InvalidArgumentException('external_process_already_started');
        }

        if ((bool) data_get($metadata, 'adapter_invocation.token_spend_allowed', false)) {
            throw new InvalidArgumentException('token_spend_already_allowed');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'provider_adapter_execution_blocked',
            'idempotent' => $idempotent,
            'execution_guard_id' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.execution_guard_id'),
            'adapter_invocation_id' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.adapter_invocation_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.adapter'),
            'blocked_by' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.blocked_by'),
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
