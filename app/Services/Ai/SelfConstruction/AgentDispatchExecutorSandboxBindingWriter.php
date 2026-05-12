<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentDispatchExecutorSandboxBindingWriter
{
    private const BINDINGS_TABLE = 'atlas_self_construction_agent_sandbox_bindings';

    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function bindProviderToWorkspace(array $input): array
    {
        $normalized = $this->normalize($input);

        if (! Schema::hasTable(self::BINDINGS_TABLE)) {
            throw new InvalidArgumentException('sandbox_bindings_table_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }

        return DB::transaction(function () use ($normalized): array {
            $existingByReceipt = AtlasSelfConstructionAgentSandboxBinding::query()
                ->where('receipt_hash', $normalized['receipt_hash'])
                ->lockForUpdate()
                ->first();

            if ($existingByReceipt instanceof AtlasSelfConstructionAgentSandboxBinding) {
                if ($this->matchesExistingBinding($existingByReceipt, $normalized)) {
                    return $this->result($existingByReceipt, created: false, ledgerEventId: null);
                }

                throw new InvalidArgumentException('active_sandbox_binding_exists_for_receipt');
            }

            $duplicateKey = AtlasSelfConstructionAgentSandboxBinding::query()
                ->where('binding_key', $normalized['binding_key'])
                ->exists();

            if ($duplicateKey) {
                throw new InvalidArgumentException('duplicate_sandbox_binding_key');
            }

            $binding = AtlasSelfConstructionAgentSandboxBinding::query()->create($normalized);

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_dispatch_executor_sandbox_binding.created',
                'binding_key' => $binding->binding_key,
                'receipt_hash' => $binding->receipt_hash,
                'receipt_key' => $binding->receipt_key,
                'packet_id' => $binding->packet_id,
                'provider' => $binding->provider,
                'provider_role' => $binding->provider_role,
                'workspace_root' => $binding->workspace_root,
                'worktree_path' => $binding->worktree_path,
                'branch' => $binding->branch,
                'provider_start_allowed' => false,
                'receipt_use_mark_allowed' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_dispatch_executor_sandbox_binding',
                'receipt_id' => $binding->binding_key,
                'correlation_id' => $binding->receipt_hash,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-dispatch-executor-sandbox-binding-writer.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            return $this->result($binding, created: true, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'binding_key',
            'receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'packet_id',
            'provider',
            'workspace_root',
            'worktree_path',
            'branch',
            'allowed_files_hash',
            'forbidden_scope_hash',
            'scope_validator_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach ([
            'receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'allowed_files_hash',
            'forbidden_scope_hash',
            'scope_validator_hash',
        ] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        if ((bool) ($input['hot_scope_overlap'] ?? false)) {
            throw new InvalidArgumentException('hot_scope_overlap_detected');
        }

        $workspaceRoot = $this->normalizePath((string) $input['workspace_root']);
        $worktreePath = $this->normalizePath((string) $input['worktree_path']);

        if (! str_starts_with($worktreePath.'/', rtrim($workspaceRoot, '/').'/')) {
            throw new InvalidArgumentException('worktree_path_outside_workspace_root');
        }

        return [
            'binding_key' => (string) $input['binding_key'],
            'receipt_hash' => (string) $input['receipt_hash'],
            'receipt_key' => $this->nullableString($input['receipt_key'] ?? null),
            'packet_id' => (string) $input['packet_id'],
            'provider' => (string) $input['provider'],
            'provider_role' => $this->nullableString($input['provider_role'] ?? null),
            'status' => 'active_pending_provider_start',
            'workspace_root' => $workspaceRoot,
            'worktree_path' => $worktreePath,
            'branch' => (string) $input['branch'],
            'executor_contract_hash' => (string) $input['executor_contract_hash'],
            'executor_release_authorization_hash' => (string) $input['executor_release_authorization_hash'],
            'allowed_files_hash' => (string) $input['allowed_files_hash'],
            'forbidden_scope_hash' => (string) $input['forbidden_scope_hash'],
            'scope_validator_hash' => (string) $input['scope_validator_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'payload' => [
                'reason' => (string) $input['reason'],
                'hot_scope_overlap' => false,
                'provider_start_side_effect_performed' => false,
                'receipt_use_mark_allowed' => false,
                'bound_by_writer' => self::class,
            ],
            'activated_at' => CarbonImmutable::now(),
            'released_at' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function matchesExistingBinding(AtlasSelfConstructionAgentSandboxBinding $binding, array $normalized): bool
    {
        foreach ([
            'binding_key',
            'receipt_hash',
            'packet_id',
            'provider',
            'workspace_root',
            'worktree_path',
            'branch',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'allowed_files_hash',
            'forbidden_scope_hash',
            'scope_validator_hash',
        ] as $field) {
            if ((string) $binding->{$field} !== (string) $normalized[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        AtlasSelfConstructionAgentSandboxBinding $binding,
        bool $created,
        ?string $ledgerEventId,
    ): array {
        return [
            'status' => 'sandbox_binding_active',
            'created' => $created,
            'binding_id' => (string) $binding->id,
            'binding_key' => $binding->binding_key,
            'receipt_hash' => $binding->receipt_hash,
            'packet_id' => $binding->packet_id,
            'provider' => $binding->provider,
            'worktree_path' => $binding->worktree_path,
            'branch' => $binding->branch,
            'binding_status' => $binding->status,
            'ledger_event_id' => $ledgerEventId,
            'provider_start_allowed_after_binding' => false,
            'receipt_use_mark_allowed' => false,
            'dispatch_allowed' => false,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
