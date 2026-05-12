<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentDispatchExecutorProviderStartDriver
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    /**
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'atlas_self_construction_agent_dispatch_receipts',
        'atlas_self_construction_agent_dispatch_executor_release_authorizations',
        'atlas_self_construction_agent_sandbox_bindings',
        'atlas_self_construction_agent_runs',
        'atlas_self_construction_agent_heartbeats',
    ];

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function startProviderOnce(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }

        return DB::transaction(function () use ($normalized): array {
            $receipt = AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('receipt_hash', $normalized['receipt_hash'])
                ->lockForUpdate()
                ->first();

            if (! $receipt instanceof AtlasSelfConstructionAgentDispatchReceipt) {
                throw new InvalidArgumentException('dispatch_receipt_not_found');
            }

            $this->assertReceiptReady($receipt, $normalized);

            $authorization = AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::query()
                ->where('signed_receipt_hash', $normalized['executor_release_authorization_hash'])
                ->lockForUpdate()
                ->first();

            if (! $authorization instanceof AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization) {
                throw new InvalidArgumentException('release_authorization_not_found');
            }

            $this->assertAuthorizationReady($authorization, $normalized);

            $binding = AtlasSelfConstructionAgentSandboxBinding::query()
                ->where('binding_key', $normalized['sandbox_binding_key'])
                ->lockForUpdate()
                ->first();

            if (! $binding instanceof AtlasSelfConstructionAgentSandboxBinding) {
                throw new InvalidArgumentException('sandbox_binding_not_found');
            }

            $this->assertBindingReady($binding, $normalized);

            $runKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $existingRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $runKey)
                ->lockForUpdate()
                ->first();

            if ($existingRun instanceof AtlasSelfConstructionAgentRun) {
                return $this->result($existingRun, $binding, idempotent: true, ledgerEventId: null);
            }

            $now = CarbonImmutable::now();
            $run = AtlasSelfConstructionAgentRun::query()->create([
                'run_key' => $runKey,
                'packet_id' => $normalized['packet_id'],
                'reservation_id' => null,
                'actor' => $normalized['actor'],
                'provider' => $normalized['provider'],
                'provider_role' => $binding->provider_role,
                'session_id' => $normalized['session'],
                'workspace_id' => 'atlas-self-construction-forge-workspace',
                'obra_id' => 'atlas-self-construction-os',
                'status' => 'pre_start_guarded',
                'liveness' => 'alive',
                'packet_hash' => null,
                'allowed_files_hash' => $binding->allowed_files_hash,
                'lease_expires_at' => $now->addMinutes($normalized['max_runtime_minutes']),
                'last_heartbeat_at' => $now,
                'started_at' => null,
                'finished_at' => null,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => 0,
                'completion_evidence_hash' => null,
                'summary' => 'Provider start preflight registered; adapter invocation remains disabled.',
                'metadata' => [
                    'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                    'receipt_hash' => $normalized['receipt_hash'],
                    'executor_contract_hash' => $normalized['executor_contract_hash'],
                    'executor_release_authorization_hash' => $normalized['executor_release_authorization_hash'],
                    'sandbox_binding_key' => $normalized['sandbox_binding_key'],
                    'adapter' => $normalized['adapter'],
                    'command' => $normalized['command'],
                    'cwd' => $normalized['cwd'],
                    'max_cost_usd' => $normalized['max_cost_usd'],
                    'reason' => $normalized['reason'],
                    'provider_started' => false,
                    'adapter_invocation_allowed' => false,
                ],
            ]);

            $heartbeat = AtlasSelfConstructionAgentHeartbeat::query()->create([
                'agent_run_id' => $run->id,
                'heartbeat_key' => $runKey.':heartbeat:pre-start',
                'sequence' => 1,
                'status' => 'alive',
                'signal' => 'pre_start_guard',
                'occurred_at' => $now,
                'metadata' => [
                    'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                    'provider_start_side_effect_performed' => false,
                    'adapter_invocation_allowed' => false,
                ],
            ]);

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_dispatch_executor.provider_start_prepared',
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'receipt_hash' => $receipt->receipt_hash,
                'packet_id' => $receipt->packet_id,
                'provider' => $receipt->provider,
                'sandbox_binding_key' => $binding->binding_key,
                'agent_run_id' => (string) $run->id,
                'pre_start_heartbeat_id' => (string) $heartbeat->id,
                'provider_started' => false,
                'adapter_invocation_allowed' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_dispatch_executor_provider_start_driver',
                'receipt_id' => $runKey,
                'correlation_id' => $receipt->receipt_hash,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-dispatch-executor-provider-start-driver.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            return $this->result($run, $binding, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'sandbox_binding_key',
            'provider_start_attempt_id',
            'packet_id',
            'provider',
            'adapter',
            'command',
            'cwd',
            'actor',
            'session',
            'max_runtime_minutes',
            'max_cost_usd',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach (['receipt_hash', 'executor_contract_hash', 'executor_release_authorization_hash'] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        $runtimeMinutes = (int) $input['max_runtime_minutes'];
        $maxCost = (float) $input['max_cost_usd'];

        if ($runtimeMinutes < 1) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ($maxCost < 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'receipt_hash' => (string) $input['receipt_hash'],
            'executor_contract_hash' => (string) $input['executor_contract_hash'],
            'executor_release_authorization_hash' => (string) $input['executor_release_authorization_hash'],
            'sandbox_binding_key' => (string) $input['sandbox_binding_key'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'packet_id' => (string) $input['packet_id'],
            'provider' => (string) $input['provider'],
            'adapter' => (string) $input['adapter'],
            'command' => (string) $input['command'],
            'cwd' => $this->normalizePath((string) $input['cwd']),
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => $runtimeMinutes,
            'max_cost_usd' => $maxCost,
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function assertReceiptReady(AtlasSelfConstructionAgentDispatchReceipt $receipt, array $normalized): void
    {
        if ($receipt->status !== 'used_pending_provider_start' || $receipt->used_at === null) {
            throw new InvalidArgumentException('dispatch_receipt_not_used_pending_provider_start');
        }

        if ((string) $receipt->packet_id !== $normalized['packet_id']) {
            throw new InvalidArgumentException('dispatch_receipt_packet_mismatch');
        }

        if ((string) $receipt->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('dispatch_receipt_provider_mismatch');
        }

        if ((string) data_get($receipt->payload, 'receipt_use.executor_contract_hash') !== $normalized['executor_contract_hash']) {
            throw new InvalidArgumentException('executor_contract_hash_mismatch');
        }

        if ((string) data_get($receipt->payload, 'receipt_use.executor_release_authorization_hash') !== $normalized['executor_release_authorization_hash']) {
            throw new InvalidArgumentException('executor_release_authorization_hash_mismatch');
        }
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function assertAuthorizationReady(
        AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization $authorization,
        array $normalized,
    ): void {
        if ($authorization->decision !== 'approve_release_once') {
            throw new InvalidArgumentException('release_authorization_decision_not_approved');
        }

        if ($authorization->status !== 'persisted_pending_executor_release') {
            throw new InvalidArgumentException('release_authorization_status_not_pending');
        }

        if ($authorization->expires_at !== null && $authorization->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException('release_authorization_expired');
        }

        if ((string) $authorization->packet_id !== $normalized['packet_id']) {
            throw new InvalidArgumentException('release_authorization_packet_mismatch');
        }

        if ((string) $authorization->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('release_authorization_provider_mismatch');
        }
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function assertBindingReady(AtlasSelfConstructionAgentSandboxBinding $binding, array $normalized): void
    {
        if ($binding->status !== 'active_pending_provider_start') {
            throw new InvalidArgumentException('sandbox_binding_not_active');
        }

        if ((string) $binding->receipt_hash !== $normalized['receipt_hash']) {
            throw new InvalidArgumentException('sandbox_binding_receipt_mismatch');
        }

        if ((string) $binding->packet_id !== $normalized['packet_id']) {
            throw new InvalidArgumentException('sandbox_binding_packet_mismatch');
        }

        if ((string) $binding->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('sandbox_binding_provider_mismatch');
        }

        if ((string) $binding->executor_contract_hash !== $normalized['executor_contract_hash']) {
            throw new InvalidArgumentException('sandbox_binding_contract_hash_mismatch');
        }

        if ((string) $binding->executor_release_authorization_hash !== $normalized['executor_release_authorization_hash']) {
            throw new InvalidArgumentException('sandbox_binding_authorization_hash_mismatch');
        }

        if ($this->normalizePath($binding->worktree_path) !== $normalized['cwd']) {
            throw new InvalidArgumentException('sandbox_binding_cwd_mismatch');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        AtlasSelfConstructionAgentRun $run,
        AtlasSelfConstructionAgentSandboxBinding $binding,
        bool $idempotent,
        ?string $ledgerEventId,
    ): array {
        return [
            'status' => 'provider_start_prepared',
            'idempotent' => $idempotent,
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'provider_start_attempt_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'sandbox_binding_key' => $binding->binding_key,
            'worktree_path' => $binding->worktree_path,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'adapter'),
            'pre_start_heartbeat_written' => true,
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }
}
