<?php

namespace App\Services\Ai\PersistentContext;

use App\Models\AiMemoryDelta;
use App\Models\AtlasPersistentContextPack;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceService;
use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AtlasPersistentContextRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.persistent_context.runtime.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DEGRADED = 'degraded';

    public function __construct(
        private readonly AtlasSessionBootstrapService $bootstrap,
        private readonly AiContextPackBuilder $contextPackBuilder,
        private readonly AtlasContextIntelligenceService $contextIntelligence,
        private readonly AtlasContextOperationsRuntimeService $contextOperations,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $prompt = trim((string) ($input['prompt'] ?? $input['input_text'] ?? $input['task'] ?? ''));
        $workspace = $this->stringValue($input['workspace'] ?? data_get($input, 'payload.workspace')) ?? base_path();
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $payload['workspace'] = $workspace;
        $sourceType = $this->stringValue($input['source_type'] ?? null) ?? 'apcr_runtime';
        $provider = $this->stringValue($input['provider'] ?? data_get($payload, 'provider'));
        $domain = $this->stringValue($input['domain'] ?? data_get($payload, 'routing_domain') ?? data_get($payload, 'atlas_mode')) ?? 'atlas';
        $flowId = $this->stringValue($input['flow_id'] ?? data_get($payload, 'flow_id') ?? data_get($payload, 'routing_task'));
        $surfaceId = $this->stringValue($input['surface_id'] ?? data_get($payload, 'surface_id') ?? data_get($payload, 'app_surface'));
        $scopeType = $this->stringValue($input['scope_type'] ?? null) ?? 'workspace';
        $scopeId = $this->stringValue($input['scope_id'] ?? null) ?? $this->workspaceScopeId($workspace);
        $evidenceRefs = array_values(array_filter((array) ($input['evidence_refs'] ?? data_get($payload, 'evidence_refs', [])), 'is_string'));

        $bootstrap = $this->safeBootstrap($prompt, $workspace);
        $task = AiTaskRequest::fromInput($prompt, [
            'source_type' => $sourceType,
            'payload' => array_merge($payload, [
                'task_type' => $this->stringValue($input['task_type'] ?? null) ?? data_get($payload, 'task_type'),
                'atlas_workflow_mode' => $this->stringValue($input['workflow_mode'] ?? null) ?? data_get($payload, 'atlas_workflow_mode'),
                'domain' => $domain,
                'provider' => $provider,
            ]),
        ], [
            'agent' => 'atlas_persistent_context',
            'intent' => 'persistent_context_bootstrap',
        ]);
        $contextPack = $this->safeContextPack($prompt, $task, [
            'payload' => $payload,
            'source_type' => $sourceType,
            'include_memory_registry' => true,
            'include_verbatim_recall' => true,
        ]);
        $contextRefs = $this->contextRefs($contextPack, $payload);
        $mustKnowLedger = $this->mustKnowLedger($bootstrap, $contextPack, $input, $prompt);
        $contextCertification = $this->safeContextCertification($prompt, $domain, $contextRefs, $evidenceRefs, $mustKnowLedger);
        $operations = $this->safeOperations($prompt, $domain, $flowId, $contextRefs, $evidenceRefs, $mustKnowLedger, $input);
        $sufficiency = $this->sufficiency($prompt, $bootstrap, $contextPack, $contextCertification, $mustKnowLedger);
        $providerHandoff = $this->providerHandoff($provider, $prompt, $bootstrap, $contextPack, $contextCertification, $mustKnowLedger, $sufficiency);

        $contextPackPayload = [
            'bootstrap' => $this->compactBootstrap($bootstrap),
            'task' => $contextPack['task'] ?? [],
            'surface' => $contextPack['surface'] ?? [],
            'manifest' => $contextPack['manifest'] ?? [],
            'project_state' => $contextPack['project_state'] ?? [],
            'memory' => [
                'recall' => array_slice((array) data_get($contextPack, 'memory.recall', []), 0, 12),
                'decisions' => array_slice((array) data_get($contextPack, 'memory.decisions', []), 0, 12),
                'technical_context' => array_slice((array) data_get($contextPack, 'memory.technical_context', []), 0, 12),
                'deltas' => array_slice((array) data_get($contextPack, 'memory.deltas', []), 0, 12),
            ],
            'retrieval' => $contextPack['retrieval'] ?? [],
            'constraints' => $contextPack['constraints'] ?? [],
            'open_questions' => $contextPack['open_questions'] ?? [],
            'context_intelligence' => $contextCertification,
            'context_operations' => $operations,
        ];
        $contextPackHash = MissionCanonicalHash::sha256($contextPackPayload);
        $mustKnowLedgerHash = MissionCanonicalHash::sha256($mustKnowLedger);
        $status = $sufficiency['status'] === 'blocked'
            ? self::STATUS_BLOCKED
            : (($contextCertification['status'] ?? null) === AtlasContextIntelligenceService::STATUS_BLOCKED ? self::STATUS_DEGRADED : self::STATUS_READY);

        $runtime = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'scope' => [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'workspace' => $workspace,
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'flow_id' => $flowId,
                'provider' => $provider,
            ],
            'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt]),
            'context_pack_hash' => $contextPackHash,
            'must_know_ledger_hash' => $mustKnowLedgerHash,
            'sufficiency' => $sufficiency,
            'retrieval_report' => $contextCertification['retrieval_report'] ?? ($contextPack['retrieval'] ?? []),
            'must_know_ledger' => $mustKnowLedger,
            'context_pack' => $contextPackPayload,
            'provider_handoff' => $providerHandoff,
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'provider_is_context_consumer_only' => true,
            ],
            'writes' => Schema::hasTable('atlas_persistent_context_packs'),
        ];
        $runtime['persistent_context_hash'] = MissionCanonicalHash::sha256($runtime);
        $record = $this->persist($runtime);
        if ($record !== null) {
            $runtime['persistent_context_pack_id'] = $record->id;
            $runtime['persistent_context_pack_uuid'] = $record->uuid;
        }

        return $runtime;
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function recordOutcome(array $runtime, array $outcome): array
    {
        $evidenceRefs = array_values(array_filter((array) ($outcome['evidence_refs'] ?? $runtime['evidence_refs'] ?? []), 'is_string'));
        $claim = trim((string) ($outcome['summary'] ?? $outcome['claim'] ?? 'Persistent context outcome recorded.'));
        $status = $evidenceRefs === [] ? 'blocked' : 'recorded';
        $memoryDelta = null;

        if ($status === 'recorded' && Schema::hasTable('ai_memory_deltas')) {
            $memoryDelta = AiMemoryDelta::query()->create([
                'source_trace_id' => $this->uuidOrNull($outcome['trace_id'] ?? null),
                'source_session_id' => $this->uuidOrNull($outcome['session_id'] ?? null),
                'source_workspace' => data_get($runtime, 'scope.workspace'),
                'type' => (string) ($outcome['memory_type'] ?? 'technical_context'),
                'claim' => $claim,
                'evidence' => $evidenceRefs,
                'scope' => data_get($runtime, 'scope.scope_type', 'workspace').':'.data_get($runtime, 'scope.scope_id', 'atlas'),
                'confidence' => min(1.0, max(0.1, (float) ($outcome['confidence'] ?? 0.75))),
                'use_when' => ['future sessions need the same project/context decision'],
                'do_not_use_when' => ['superseded by newer decision or evidence'],
                'requires_confirmation' => true,
                'status' => 'pending',
            ]);
        }

        $receipt = [
            'schema_version' => 'atlas.persistent_context.post_execution_update.v1',
            'status' => $status,
            'memory_delta_id' => $memoryDelta?->id,
            'promotion_allowed' => false,
            'requires_confirmation' => true,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $status === 'blocked' ? [[
                'id' => 'missing_evidence_refs',
                'reason' => 'APCR never updates durable memory without evidence refs.',
            ]] : [],
            'claim_policy' => [
                'provider_calls_made' => false,
                'benchmark_not_run' => true,
            ],
        ];
        $receipt['post_execution_update_hash'] = MissionCanonicalHash::sha256($receipt);

        if (Schema::hasTable('atlas_persistent_context_packs') && is_string($runtime['persistent_context_pack_id'] ?? null)) {
            AtlasPersistentContextPack::query()
                ->whereKey($runtime['persistent_context_pack_id'])
                ->update([
                    'post_execution_update' => $receipt,
                    'memory_delta_id' => $memoryDelta?->id,
                ]);
        }

        return $receipt;
    }

    /**
     * @return array<string,mixed>
     */
    private function safeBootstrap(string $prompt, string $workspace): array
    {
        try {
            return $this->bootstrap->bootstrap($prompt, ['workspace' => $workspace]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => 'atlas.session_bootstrap.v1',
                'status' => 'degraded',
                'error' => 'session_bootstrap_threw',
                'exception_class' => $exception::class,
                'read_first' => [],
                'blocked_when' => ['session_bootstrap_unavailable'],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function safeContextPack(string $prompt, AiTaskRequest $task, array $options): array
    {
        try {
            return $this->contextPackBuilder->build($prompt, $task, $options)->toArray();
        } catch (Throwable $exception) {
            return [
                'schema_version' => 1,
                'status' => 'degraded',
                'error' => 'context_pack_builder_threw',
                'exception_class' => $exception::class,
                'manifest' => [],
                'retrieval' => [],
                'memory' => [],
                'context_refs' => [],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function contextRefs(array $contextPack, array $payload): array
    {
        $refs = [];
        foreach ((array) data_get($contextPack, 'manifest.sources', []) as $source) {
            if (is_array($source) && is_scalar($source['id'] ?? null)) {
                $refs[] = (string) ($source['type'] ?? 'source').':'.(string) $source['id'];
            }
        }
        foreach ((array) data_get($payload, 'context_refs', []) as $ref) {
            if (is_scalar($ref)) {
                $refs[] = 'payload:'.(string) $ref;
            }
        }
        foreach ((array) data_get($payload, 'rich_input_payload.source_manifest', []) as $index => $source) {
            if (is_array($source)) {
                $refs[] = 'rich_input:'.($source['kind'] ?? 'source').':'.($source['id'] ?? $index);
            }
        }

        return array_values(array_unique(array_filter($refs)));
    }

    /**
     * @param  array<string,mixed>  $bootstrap
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mustKnowLedger(array $bootstrap, array $contextPack, array $input, string $prompt): array
    {
        $decisions = array_values(array_filter(array_merge(
            array_map(static fn (mixed $doc): array => ['kind' => 'read_first_doc', 'value' => (string) $doc], (array) ($bootstrap['read_first'] ?? [])),
            array_map(static fn (mixed $risk): array => ['kind' => 'risk', 'value' => (string) $risk], (array) ($bootstrap['risks'] ?? [])),
            array_map(static fn (mixed $blocker): array => ['kind' => 'blocker', 'value' => (string) $blocker], (array) ($bootstrap['blocked_when'] ?? [])),
            array_map(static fn (mixed $decision): array => ['kind' => 'memory_decision', 'value' => is_array($decision) ? json_encode($decision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $decision], (array) data_get($contextPack, 'memory.decisions', [])),
            array_map(static fn (mixed $item): array => is_array($item) ? $item : ['kind' => 'caller_must_keep', 'value' => (string) $item], (array) ($input['must_keep_items'] ?? [])),
        ), static fn (array $item): bool => trim((string) ($item['value'] ?? $item['digest'] ?? '')) !== ''));

        array_unshift($decisions, [
            'kind' => 'invariant',
            'value' => 'provider sessions must never start without APCR context pack, sufficiency gate and must-know ledger',
        ]);

        return [
            'schema_version' => 'atlas.persistent_context.must_know_ledger.v1',
            'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt]),
            'items' => array_values(array_map(function (array $item, int $index): array {
                $value = (string) ($item['value'] ?? $item['digest'] ?? '');

                return [
                    'id' => (string) ($item['id'] ?? 'must_know_'.$index),
                    'kind' => (string) ($item['kind'] ?? 'fact'),
                    'digest' => $value,
                    'digest_hash' => MissionCanonicalHash::sha256(['value' => $value]),
                ];
            }, $decisions, array_keys($decisions))),
        ];
    }

    /**
     * @param  list<string>  $contextRefs
     * @param  list<string>  $evidenceRefs
     * @param  array<string,mixed>  $mustKnowLedger
     * @return array<string,mixed>
     */
    private function safeContextCertification(string $prompt, string $domain, array $contextRefs, array $evidenceRefs, array $mustKnowLedger): array
    {
        try {
            return $this->contextIntelligence->assess([
                'prompt' => $prompt,
                'task_type' => 'persistent_context_bootstrap',
                'domain' => $domain,
                'risk_level' => 'medium',
                'context_refs' => $contextRefs,
                'evidence_refs' => $evidenceRefs,
                'must_keep_items' => $mustKnowLedger['items'] ?? [],
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasContextIntelligenceService::SCHEMA_VERSION,
                'status' => AtlasContextIntelligenceService::STATUS_DEGRADED,
                'error' => 'context_intelligence_threw',
                'exception_class' => $exception::class,
            ];
        }
    }

    /**
     * @param  list<string>  $contextRefs
     * @param  list<string>  $evidenceRefs
     * @param  array<string,mixed>  $mustKnowLedger
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function safeOperations(string $prompt, string $domain, ?string $flowId, array $contextRefs, array $evidenceRefs, array $mustKnowLedger, array $input): array
    {
        try {
            return $this->contextOperations->evaluate([
                'prompt' => $prompt,
                'domain' => $domain,
                'flow_id' => $flowId ?? 'atlas_conversation',
                'flow_profile' => $this->stringValue($input['flow_profile'] ?? null),
                'runtime_mode' => $this->stringValue($input['runtime_mode'] ?? null) ?? 'standard',
                'context_refs' => $contextRefs,
                'evidence_refs' => $evidenceRefs,
                'must_keep_items' => $mustKnowLedger['items'] ?? [],
                'handoff_target' => is_array($input['handoff_target'] ?? null) ? $input['handoff_target'] : null,
                'turns' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasContextOperationsRuntimeService::SCHEMA_VERSION,
                'status' => AtlasContextOperationsRuntimeService::STATUS_WATCH,
                'error' => 'context_operations_threw',
                'exception_class' => $exception::class,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $bootstrap
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $contextCertification
     * @param  array<string,mixed>  $mustKnowLedger
     * @return array<string,mixed>
     */
    private function sufficiency(string $prompt, array $bootstrap, array $contextPack, array $contextCertification, array $mustKnowLedger): array
    {
        $blockers = [];
        if ($prompt === '') {
            $blockers[] = ['id' => 'empty_prompt', 'reason' => 'APCR cannot build reliable context for empty prompt.'];
        }
        if (($bootstrap['read_first'] ?? []) === []) {
            $blockers[] = ['id' => 'no_bootstrap_docs', 'reason' => 'session bootstrap did not return read_first docs.'];
        }
        if (($contextPack['manifest'] ?? []) === []) {
            $blockers[] = ['id' => 'missing_context_pack_manifest', 'reason' => 'context pack manifest is required.'];
        }
        if (($mustKnowLedger['items'] ?? []) === []) {
            $blockers[] = ['id' => 'empty_must_know_ledger', 'reason' => 'must-know ledger cannot be empty.'];
        }
        foreach ((array) ($contextCertification['blockers'] ?? []) as $blocker) {
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }

        return [
            'schema_version' => 'atlas.persistent_context.sufficiency_gate.v1',
            'status' => $blockers === [] ? 'sufficient' : 'blocked',
            'context_ref_count' => (int) data_get($contextPack, 'manifest.context_ref_count', 0),
            'source_count' => (int) data_get($contextPack, 'manifest.source_count', 0),
            'must_know_count' => count((array) ($mustKnowLedger['items'] ?? [])),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $bootstrap
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $contextCertification
     * @param  array<string,mixed>  $mustKnowLedger
     * @param  array<string,mixed>  $sufficiency
     * @return array<string,mixed>
     */
    private function providerHandoff(?string $provider, string $prompt, array $bootstrap, array $contextPack, array $contextCertification, array $mustKnowLedger, array $sufficiency): array
    {
        return [
            'schema_version' => 'atlas.persistent_context.provider_handoff.v1',
            'provider' => $provider ?? 'auto',
            'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt]),
            'context_pack_hash' => MissionCanonicalHash::sha256([
                'manifest' => $contextPack['manifest'] ?? [],
                'retrieval' => $contextPack['retrieval'] ?? [],
                'memory' => $contextPack['memory'] ?? [],
            ]),
            'must_know_ledger_hash' => MissionCanonicalHash::sha256($mustKnowLedger),
            'sufficiency_status' => $sufficiency['status'],
            'read_first' => array_values((array) ($bootstrap['read_first'] ?? [])),
            'required_before_execution' => [
                'read_context_pack',
                'preserve_must_know_ledger',
                'honor_sufficiency_gate',
                'return_evidence_refs',
                'do_not_invent_source_refs',
            ],
            'execution_allowed' => $sufficiency['status'] === 'sufficient'
                && ($contextCertification['status'] ?? null) !== AtlasContextIntelligenceService::STATUS_BLOCKED,
        ];
    }

    /**
     * @param  array<string,mixed>  $runtime
     */
    private function persist(array $runtime): ?AtlasPersistentContextPack
    {
        if (! Schema::hasTable('atlas_persistent_context_packs')) {
            return null;
        }

        return AtlasPersistentContextPack::query()->create([
            'uuid' => 'apcr_'.substr((string) ($runtime['persistent_context_hash'] ?? Str::orderedUuid()), 0, 24),
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $runtime['status'],
            'scope_type' => data_get($runtime, 'scope.scope_type', 'workspace'),
            'scope_id' => data_get($runtime, 'scope.scope_id'),
            'workspace' => data_get($runtime, 'scope.workspace'),
            'surface_id' => data_get($runtime, 'scope.surface_id'),
            'domain' => data_get($runtime, 'scope.domain'),
            'flow_id' => data_get($runtime, 'scope.flow_id'),
            'provider' => data_get($runtime, 'scope.provider'),
            'prompt_hash' => $runtime['prompt_hash'],
            'context_pack_hash' => $runtime['context_pack_hash'],
            'must_know_ledger_hash' => $runtime['must_know_ledger_hash'],
            'sufficiency_status' => data_get($runtime, 'sufficiency.status'),
            'retrieval_report' => $runtime['retrieval_report'],
            'must_know_ledger' => $runtime['must_know_ledger'],
            'context_pack' => $runtime['context_pack'],
            'provider_handoff' => $runtime['provider_handoff'],
            'evidence_refs' => $runtime['evidence_refs'],
            'metadata' => [
                'persistent_context_hash' => $runtime['persistent_context_hash'],
                'claim_policy' => $runtime['claim_policy'],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $bootstrap
     * @return array<string,mixed>
     */
    private function compactBootstrap(array $bootstrap): array
    {
        return [
            'schema_version' => $bootstrap['schema_version'] ?? 'atlas.session_bootstrap.v1',
            'status' => $bootstrap['status'] ?? 'unknown',
            'read_first' => array_values((array) ($bootstrap['read_first'] ?? [])),
            'placement' => $bootstrap['placement'] ?? [],
            'gate_status' => $bootstrap['gate_status'] ?? null,
            'blocked_when' => array_values((array) ($bootstrap['blocked_when'] ?? [])),
            'risks' => array_values((array) ($bootstrap['risks'] ?? [])),
            'required_validation' => array_values((array) ($bootstrap['required_validation'] ?? [])),
        ];
    }

    private function workspaceScopeId(string $workspace): string
    {
        return Str::limit(str_replace(['/', '\\', ' '], '_', trim($workspace)), 150, '');
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::isUuid($value) ? $value : null;
    }
}
