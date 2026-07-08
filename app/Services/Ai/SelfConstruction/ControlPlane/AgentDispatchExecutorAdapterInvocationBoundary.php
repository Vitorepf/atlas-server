<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentDispatchExecutorAdapterInvocationBoundary
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    /**
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'atlas_self_construction_agent_runs',
        'atlas_self_construction_agent_heartbeats',
    ];

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AgentProviderAdapterRegistry $adapterRegistry,
    ) {}

    /** The ONLY fields a provider invocation may ever receive. */
    private const ALLOWED_CONTEXT_FIELDS = ['task_packet_id', 'allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const REDACTION_CATEGORY_KEYWORDS = [
        'queue_state' => ['queue', 'lease', 'claim', 'worker', 'backlog'],
        'internal_prompt' => ['prompt', 'system_prompt', 'instruction', 'persona'],
        'workspace_context' => ['workspace', 'cwd', 'env', 'secret', 'credential', 'token', 'path'],
    ];

    /**
     * Builds the ONLY context a provider adapter invocation may receive:
     * task_packet_id, allowed_files, acceptance_criteria, required_evidence.
     * Any other field in $taskFacts — queue state, internal prompts,
     * workspace/credential context, or anything else — is stripped and
     * accounted for in redaction_summary, NEVER passed through.
     *
     * boundary_status:
     *   safe                — all four allowed fields present and non-empty.
     *   incomplete_context   — at least one required allowed field is missing
     *                          or empty; the invocation context is still
     *                          built (with what's present) but flagged.
     *
     * @param  array<string, mixed>  $taskFacts
     * @return array<string, mixed>
     */
    public function buildProviderSafeInvocationContext(array $taskFacts): array
    {
        $invocationContext = [
            'task_packet_id' => (string) ($taskFacts['task_packet_id'] ?? ''),
            'allowed_files' => array_values(array_filter(array_map('strval', (array) ($taskFacts['allowed_files'] ?? [])))),
            'acceptance_criteria' => array_values(array_filter(array_map('strval', (array) ($taskFacts['acceptance_criteria'] ?? [])))),
            'required_evidence' => array_values(array_filter(array_map('strval', (array) ($taskFacts['required_evidence'] ?? [])))),
        ];

        $strippedFields = array_values(array_diff(array_keys($taskFacts), self::ALLOWED_CONTEXT_FIELDS));
        $strippedByCategory = ['queue_state' => [], 'internal_prompt' => [], 'workspace_context' => [], 'other' => []];
        foreach ($strippedFields as $field) {
            $strippedByCategory[$this->categorizeStrippedField($field)][] = $field;
        }

        $isComplete = $invocationContext['task_packet_id'] !== ''
            && $invocationContext['allowed_files'] !== []
            && $invocationContext['acceptance_criteria'] !== []
            && $invocationContext['required_evidence'] !== [];

        return [
            'boundary_status' => $isComplete ? 'safe' : 'incomplete_context',
            'invocation_context' => $invocationContext,
            'redaction_summary' => [
                'stripped_field_count' => count($strippedFields),
                'stripped_fields' => $strippedFields,
                'stripped_fields_by_category' => $strippedByCategory,
            ],
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
        ];
    }

    private function categorizeStrippedField(string $field): string
    {
        $lower = strtolower($field);
        foreach (self::REDACTION_CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $category;
                }
            }
        }

        return 'other';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareInvocation(array $input): array
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
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $run->metadata;
            $existingInvocationId = (string) data_get($metadata, 'adapter_invocation.adapter_invocation_id', '');

            if ($existingInvocationId !== '') {
                if ($existingInvocationId !== $normalized['adapter_invocation_id']) {
                    throw new InvalidArgumentException('adapter_invocation_already_prepared_for_run');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertRunReady($run, $metadata, $normalized);
            $this->assertPreStartHeartbeatExists($run, $normalized);
            $adapterDescriptor = $this->adapterRegistry->resolve($normalized['provider'], $normalized['adapter']);
            $adapterDescriptorHash = $this->adapterRegistry->descriptorHash($adapterDescriptor);

            $metadata['adapter_invocation'] = [
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider' => $normalized['provider'],
                'adapter' => $normalized['adapter'],
                'adapter_id' => $adapterDescriptor['adapter_id'],
                'adapter_descriptor_hash' => $adapterDescriptorHash,
                'command' => $normalized['command'],
                'cwd' => $normalized['cwd'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'max_runtime_minutes' => $normalized['max_runtime_minutes'],
                'max_cost_usd' => $normalized['max_cost_usd'],
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
                'status' => 'prepared_pending_external_invocation',
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ];

            $run->forceFill([
                'status' => 'adapter_invocation_prepared',
                'summary' => 'Adapter invocation boundary prepared; external provider process remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_dispatch_executor.adapter_invocation_prepared',
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => $run->provider,
                'adapter' => $normalized['adapter'],
                'adapter_id' => $adapterDescriptor['adapter_id'],
                'adapter_descriptor_hash' => $adapterDescriptorHash,
                'cwd' => $normalized['cwd'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_dispatch_executor_adapter_invocation_boundary',
                'receipt_id' => $normalized['adapter_invocation_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-dispatch-executor-adapter-invocation-boundary.v1',
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
            'adapter_invocation_id',
            'provider_start_attempt_id',
            'provider',
            'adapter',
            'command',
            'cwd',
            'context_pack_hash',
            'continuation_summary_hash',
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

        foreach (['context_pack_hash', 'continuation_summary_hash'] as $hashField) {
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
            'run_key' => (string) $input['run_key'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'provider' => (string) $input['provider'],
            'adapter' => (string) $input['adapter'],
            'command' => (string) $input['command'],
            'cwd' => $this->normalizePath((string) $input['cwd']),
            'context_pack_hash' => (string) $input['context_pack_hash'],
            'continuation_summary_hash' => (string) $input['continuation_summary_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => $runtimeMinutes,
            'max_cost_usd' => $maxCost,
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertRunReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'pre_start_guarded') {
            throw new InvalidArgumentException('agent_run_not_pre_start_guarded');
        }

        if ((string) $run->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('agent_run_provider_mismatch');
        }

        if ((string) data_get($metadata, 'provider_start_attempt_id') !== $normalized['provider_start_attempt_id']) {
            throw new InvalidArgumentException('provider_start_attempt_mismatch');
        }

        if ((string) data_get($metadata, 'adapter') !== $normalized['adapter']) {
            throw new InvalidArgumentException('adapter_mismatch');
        }

        if ((string) data_get($metadata, 'command') !== $normalized['command']) {
            throw new InvalidArgumentException('command_mismatch');
        }

        if ($this->normalizePath((string) data_get($metadata, 'cwd')) !== $normalized['cwd']) {
            throw new InvalidArgumentException('cwd_mismatch');
        }

        if ((bool) data_get($metadata, 'provider_started', false)) {
            throw new InvalidArgumentException('provider_already_started');
        }

        if ((bool) data_get($metadata, 'adapter_invocation_allowed', false)) {
            throw new InvalidArgumentException('adapter_invocation_already_allowed');
        }
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function assertPreStartHeartbeatExists(AtlasSelfConstructionAgentRun $run, array $normalized): void
    {
        $exists = AtlasSelfConstructionAgentHeartbeat::query()
            ->where('agent_run_id', $run->id)
            ->where('heartbeat_key', $normalized['run_key'].':heartbeat:pre-start')
            ->where('signal', 'pre_start_guard')
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('pre_start_heartbeat_missing');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'adapter_invocation_prepared',
            'idempotent' => $idempotent,
            'adapter_invocation_id' => (string) data_get($run->metadata, 'adapter_invocation.adapter_invocation_id'),
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'provider_start_attempt_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'adapter'),
            'adapter_id' => (string) data_get($run->metadata, 'adapter_invocation.adapter_id'),
            'adapter_descriptor_hash' => (string) data_get($run->metadata, 'adapter_invocation.adapter_descriptor_hash'),
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
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
