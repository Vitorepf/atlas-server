<?php

namespace App\Services\Ai;

use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AtlasOpenBrainContextInjectionService
{
    public function __construct(
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly ?AtlasMemoryQualityService $memoryQuality = null,
        private readonly ?ContextPackSelfReflectionGate $contextReflection = null,
        private readonly ?OperatorContextComposer $operatorContext = null,
        private readonly ?CodeGraphContextRetriever $codeGraph = null,
        private readonly ?AtlasHybridMemoryRetrievalService $memoryRecall = null,
        private readonly ?AtlasRealityGraphQueryService $realityGraph = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function inject(string $input, AiTaskRequest $task, AiContextPack $contextPack, array $options = []): array
    {
        $policy = $this->policy($task, $options);

        if ($policy['mode'] === 'off') {
            return $this->skipped($policy, 'policy_off');
        }

        if (! $policy['enabled'] && $policy['mode'] !== 'required') {
            return $this->skipped($policy, 'global_disabled');
        }

        if (! $this->requiresInjection($task, $options) && $policy['mode'] !== 'required') {
            return $this->skipped($policy, 'mode_not_open_brain');
        }

        try {
            return $this->buildInjection($input, $task, $contextPack, $options, $policy);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed($policy, $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function policy(AiTaskRequest $task, array $options): array
    {
        $payload = $this->payload($options);
        $requested = $this->string(data_get($options, 'open_brain.mode', data_get($payload, 'open_brain.mode')));
        $mode = in_array($requested, ['auto', 'off', 'required'], true)
            ? $requested
            : ($this->requiresInjection($task, $options) ? 'auto' : 'off');

        $budget = (int) data_get(
            $options,
            'open_brain.budget_chars',
            data_get($payload, 'open_brain.budget_chars', config('atlas.open_brain.injection.budget_chars', 20000)),
        );

        return [
            'enabled' => (bool) config('atlas.open_brain.injection.enabled', true),
            'mode' => $mode,
            'surface' => $this->surface($options, $payload),
            'budget_chars' => max(2000, $budget),
            'refresh' => (bool) data_get($options, 'open_brain.refresh', data_get($payload, 'open_brain.refresh', false)),
            'provider_safe_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function requiresInjection(AiTaskRequest $task, array $options): bool
    {
        $payload = $this->payload($options);
        $signals = collect([
            $task->desiredMode(),
            $task->taskType(),
            data_get($payload, 'atlas_workflow_mode'),
            data_get($payload, 'routing_task'),
            data_get($payload, 'routing_domain'),
            data_get($payload, 'programming_flow'),
            data_get($payload, 'dev_execution_plan.programming_flow'),
            data_get($payload, 'programming_message_plan.programming_flow'),
            data_get($payload, 'task_type'),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (mixed $value): string => Str::of((string) $value)->lower()->trim()->value())
            ->values()
            ->all();

        return collect($signals)->contains(function (string $signal): bool {
            if (str_starts_with($signal, 'programming.')) {
                return in_array($signal, [
                    'programming.dev',
                    'programming.debug',
                    'programming.review',
                    'programming.repair',
                    'programming.refactor',
                    'programming.qa',
                    'programming.security',
                    'programming.database',
                    'programming.frontend',
                    'programming.visual',
                    'programming.forge',
                ], true);
            }

            return in_array($signal, [
                'dev',
                'debug',
                'review',
                'repair',
                'fix',
                'programming',
                'quality_repair',
                'execute',
            ], true);
        });
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function buildInjection(string $input, AiTaskRequest $task, AiContextPack $contextPack, array $options, array $policy): array
    {
        $payload = $this->payload($options);
        $pack = $contextPack->toArray();
        $precomputedAobg = is_array($options['precomputed_aobg_pack'] ?? null)
            ? $options['precomputed_aobg_pack']
            : null;
        if ($precomputedAobg !== null && is_array($precomputedAobg['context_delivery_policy'] ?? null)) {
            $pack['context_delivery_policy'] = $precomputedAobg['context_delivery_policy'];
        }
        $workspace = $this->workspace(data_get($pack, 'surface.workspace', data_get($payload, 'workspace')));
        $engineeringContext = $this->engineeringContext($workspace, $payload);
        $memoryQuality = $this->memoryQuality($engineeringContext, $policy);
        $memoryQualitySummary = $this->memoryQualitySummary($memoryQuality);
        $selfReflection = $this->selfReflection($contextPack);
        $operatorContext = $this->operatorContext($payload, $task, $policy, $options);
        $knowledgeRefs = $this->knowledgeRefs($engineeringContext);
        $codeRefs = $this->codeRefs($engineeringContext);
        // AP-815 I-4 (Stage 2): the precise, BM25-ranked code-graph context pack reaches
        // the provider prompt through THIS shared seam — flag-gated, default-OFF. When the
        // flag is off this resolves to [] (no DB touch, no app() resolution, no hash key)
        // so the injection stays byte-identical to the pre-wiring behaviour.
        $codeGraphRefs = $precomputedAobg === null
            ? $this->codeGraphRefs($input, $payload, $pack, $workspace)
            : $this->precomputedCodeGraphRefs($precomputedAobg);
        // R4 (PART A): the operator's accrued SEMANTIC memory recall (decisions/learnings)
        // pulled through the now-pgvector AtlasHybridMemoryRetrievalService::recall — the
        // single shared semantic recall path (NOT a new retrieval engine). Flag-gated,
        // default-OFF: when off this resolves to [] (no service resolution, no DB, no hash
        // key) so the injection stays byte-identical to the pre-wiring behaviour.
        $memoryRecallRefs = $precomputedAobg === null
            ? $this->memoryRecallRefs($input, $engineeringContext, $pack)
            : $this->precomputedMemoryRecallRefs($precomputedAobg);
        // F3 (Salto 1 — AURG vivo): the fused reality graph's CROSS-LAYER chains enter the
        // LIVE prompt through this same seam — flag-gated, default-OFF. PROVIDER-BOUND
        // ALWAYS (hard-coded true inside realityGraphRefs): this section IS a provider
        // prompt, so the unbounded local-only view of the brain is structurally
        // unreachable from here. When the flag is off this resolves to [] (no service
        // resolution, no DB, no hash key) so the injection stays byte-identical to the
        // pre-wiring behaviour.
        $realityGraphRefs = $precomputedAobg === null
            ? $this->realityGraphRefs($input)
            : $this->precomputedRealityGraphRefs($precomputedAobg);
        $operatorRefs = $this->operatorContextRefs($operatorContext);
        $contextDeliveryPolicy = $this->contextDeliveryPolicy($payload, $pack);
        $contextDeliveryRefs = $contextDeliveryPolicy !== null ? $this->contextDeliveryRefs($contextDeliveryPolicy) : [];

        // Hygiene gate: drop any ref carrying an unsafe marker (quarantine,
        // require_sanitization, non_instructional_context, hostile_memory,
        // raw_prompt_leakage, raw_prompt_detected) or provider_safe===false BEFORE it can
        // reach the merged refs, the deterministic hash or the rendered prompt section.
        // Omitted refs are dropped from prompt text but counted into hygiene_receipt below.
        $omissionReasons = [];
        $omittedRefCount = 0;
        $contextPackRefs = $this->filterProviderUnsafeRefs($contextPack->contextRefs(), $omissionReasons, $omittedRefCount);
        $knowledgeRefs = $this->filterProviderUnsafeRefs($knowledgeRefs, $omissionReasons, $omittedRefCount);
        $codeRefs = $this->filterProviderUnsafeRefs($codeRefs, $omissionReasons, $omittedRefCount);
        $codeGraphRefs = $this->filterProviderUnsafeRefs($codeGraphRefs, $omissionReasons, $omittedRefCount);
        $memoryRecallRefs = $this->filterProviderUnsafeRefs($memoryRecallRefs, $omissionReasons, $omittedRefCount);
        $realityGraphRefs = $this->filterProviderUnsafeRefs($realityGraphRefs, $omissionReasons, $omittedRefCount);
        $operatorRefs = $this->filterProviderUnsafeRefs($operatorRefs, $omissionReasons, $omittedRefCount);
        $contextDeliveryRefs = $this->filterProviderUnsafeRefs($contextDeliveryRefs, $omissionReasons, $omittedRefCount);
        $omissionReasons = array_values(array_unique($omissionReasons));
        sort($omissionReasons);

        $fusedSourceRefs = null;
        if ($precomputedAobg !== null
            && (bool) data_get($precomputedAobg, 'retrieval_fusion.applied_to_sections', false)
        ) {
            $fusedSourceRefs = $this->orderFusedSourceRefs(
                $codeGraphRefs,
                $memoryRecallRefs,
                $realityGraphRefs,
                (array) data_get($precomputedAobg, 'retrieval_fusion.candidates', []),
            );
            $contextRefs = $this->mergeRefs(
                $contextPackRefs,
                $knowledgeRefs,
                $codeRefs,
                $fusedSourceRefs,
                $operatorRefs,
                $contextDeliveryRefs,
            );
        } else {
            $contextRefs = $this->mergeRefs($contextPackRefs, $knowledgeRefs, $codeRefs, $codeGraphRefs, $memoryRecallRefs, $realityGraphRefs, $operatorRefs, $contextDeliveryRefs);
        }
        $hashPayload = [
            'context_pack' => $this->stableContextPackForHash($pack),
            'knowledge_refs' => $knowledgeRefs,
            'code_refs' => $codeRefs,
            'operator_context' => $this->stableOperatorContextForHash($operatorContext),
            'memory_quality' => $memoryQualitySummary,
            'self_reflection' => $this->stableSelfReflectionForHash($selfReflection),
            'policy' => $policy,
        ];
        // Only fold the code-graph pack into the deterministic hash when it actually
        // produced refs (flag ON + matched symbols). Adding the key unconditionally would
        // alter the encoded hash payload even with the flag OFF and break byte-identity.
        if ($codeGraphRefs !== []) {
            $hashPayload['code_graph_refs'] = $codeGraphRefs;
        }
        // Same byte-identity contract as code_graph above: only fold the recall into the
        // deterministic hash when it actually produced refs (flag ON + matched memory).
        if ($memoryRecallRefs !== []) {
            $hashPayload['memory_recall_refs'] = $this->stableMemoryRecallForHash($memoryRecallRefs);
        }
        // Same byte-identity contract again: only fold the reality-graph chains into the
        // deterministic hash when they actually produced refs (flag ON + reached paths),
        // via an order-independent stable projection (sorted chain ids).
        if ($realityGraphRefs !== []) {
            $hashPayload['reality_graph_refs'] = $this->stableRealityGraphForHash($realityGraphRefs);
        }
        if ($contextDeliveryPolicy !== null) {
            $hashPayload['context_delivery_policy'] = $this->stableContextDeliveryPolicyForHash($contextDeliveryPolicy);
        }
        if ($precomputedAobg !== null) {
            $hashPayload['retrieval_core_hash'] = (string) ($precomputedAobg['context_pack_hash'] ?? '');
        }
        $contextPackHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $summary = $this->summary($contextRefs, $knowledgeRefs, $codeRefs, $policy, $pack);
        if ($contextDeliveryPolicy !== null) {
            $summary['context_delivery_policy'] = $this->contextDeliveryPolicySummary($contextDeliveryPolicy);
        }
        if ($memoryQuality !== null) {
            $summary['memory_quality'] = $memoryQualitySummary;
        }
        $summary['self_reflection'] = $selfReflection;
        $summary['programming_context'] = $this->programmingContextSummary($payload, $contextPack);
        $summary['operator_context'] = $this->operatorContextSummary($operatorContext);
        $summary['operator_context_items'] = collect((array) ($operatorContext['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->take((int) config('atlas_operator_intelligence.max_injected_profile_items', 8))
            ->all();
        $summary['retrieval_core'] = $precomputedAobg === null
            ? ['mode' => 'legacy_parallel']
            : [
                'mode' => 'precomputed_aobg',
                'schema' => (string) ($precomputedAobg['schema'] ?? ''),
                'context_pack_hash' => (string) ($precomputedAobg['context_pack_hash'] ?? ''),
                'sources_present' => array_values((array) data_get($precomputedAobg, 'provenance.sources_present', [])),
                'memory_status' => (string) data_get($precomputedAobg, 'provenance.memory.status', 'unknown'),
            ];
        $warnings = [];

        if ((int) $summary['memory_refs'] === 0 && (int) $summary['memory_recall_refs'] === 0) {
            $warnings[] = 'no_provider_safe_memory_refs';
        }
        if ($precomputedAobg !== null
            && data_get($precomputedAobg, 'provenance.memory.status') === 'retrieval_error') {
            $warnings[] = 'retrieval_memory_error';
        }
        if ((int) $summary['knowledge_refs'] === 0) {
            $warnings[] = 'no_engineering_knowledge_refs';
        }
        if ((int) $summary['code_refs'] === 0) {
            $warnings[] = 'no_code_intelligence_refs';
        }
        if ((int) $summary['context_refs'] === 0) {
            $warnings[] = 'open_brain_context_empty';
        }
        $warnings = array_values(array_unique([
            ...$warnings,
            ...$this->memoryQualityWarnings($memoryQuality),
            ...$this->selfReflectionWarnings($selfReflection),
            ...$this->retrievalPlanWarnings((array) ($summary['retrieval_plan'] ?? [])),
            ...$this->operatorContextWarnings($operatorContext),
            ...$this->contextDeliveryPolicyWarnings($contextDeliveryPolicy),
        ]));

        $promptSection = $this->promptSection(
            task: $task,
            contextPack: $contextPack,
            contextPackHash: $contextPackHash,
            summary: $summary,
            policy: $policy,
            memoryQuality: $memoryQuality,
            knowledgeRefs: $knowledgeRefs,
            codeRefs: $codeRefs,
            codeGraphRefs: $codeGraphRefs,
            memoryRecallRefs: $memoryRecallRefs,
            realityGraphRefs: $realityGraphRefs,
            contextDeliveryPolicy: $contextDeliveryPolicy,
            warnings: $warnings,
        );

        $marker = "\n[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]";
        $markerLength = Str::length($marker);
        $originalChars = Str::length($promptSection);
        $preTruncationHash = hash('sha256', $promptSection);

        $summary['used_chars'] = $originalChars;
        $summary['truncation'] = [
            'truncated' => false,
            'budget_chars' => (int) $policy['budget_chars'],
            'original_chars' => $originalChars,
            'used_chars' => $originalChars,
            'dropped_chars' => 0,
            'marker' => null,
            'pre_truncation_hash' => $preTruncationHash,
            'post_truncation_hash' => $preTruncationHash,
        ];

        if ($originalChars > (int) $policy['budget_chars']) {
            $warnings[] = 'open_brain_context_budget_exceeded';
            $promptSection = Str::limit($promptSection, (int) $policy['budget_chars'], $marker);
            $usedChars = Str::length($promptSection);
            $summary['used_chars'] = $usedChars;
            $summary['truncation'] = [
                'truncated' => true,
                'budget_chars' => (int) $policy['budget_chars'],
                'original_chars' => $originalChars,
                'used_chars' => $usedChars,
                'dropped_chars' => max(0, $originalChars - max(0, $usedChars - $markerLength)),
                'marker' => $marker,
                'pre_truncation_hash' => $preTruncationHash,
                'post_truncation_hash' => hash('sha256', $promptSection),
            ];
        }

        $status = $warnings === [] ? 'injected' : 'degraded';
        $auditAction = $this->isPreview($options, $payload) ? 'context_injection_preview' : 'context_injection';
        $audit = $this->recordAudit($input, $workspace, $contextPackHash, $summary, $policy, $status, $warnings, $auditAction);
        if ($audit === null) {
            $warnings[] = 'open_brain_audit_table_missing';
            $status = 'degraded';
        }

        $blockingWarnings = array_values(array_intersect($warnings, [
            'open_brain_audit_table_missing',
            'open_brain_context_empty',
            'memory_quality_critical',
            'memory_quality_not_migrated',
            'memory_quality_empty',
            'context_pack_insufficient',
            'context_pack_contradictory',
            'context_pack_risky',
            'retrieval_required_source_unavailable',
            'retrieval_memory_error',
        ]));

        if ($blockingWarnings !== [] && $policy['mode'] === 'required') {
            return [
                'enabled' => true,
                'status' => 'failed_closed',
                'reason' => 'required_open_brain_not_ready',
                'surface' => $policy['surface'],
                'mode' => $task->taskType(),
                'workspace' => $workspace,
                'context_pack_hash' => $contextPackHash,
                'audit_id' => $audit['id'] ?? null,
                'prompt_section' => null,
                'summary' => $summary,
                'warnings' => $warnings,
                'next_actions' => $this->nextActions($warnings, $summary),
                'context_refs' => $contextRefs,
                'policy' => $policy,
            ];
        }

        return [
            'enabled' => true,
            'status' => $status,
            'reason' => 'mode_requires_open_brain',
            'surface' => $policy['surface'],
            'mode' => $task->taskType(),
            'workspace' => $workspace,
            'context_pack_hash' => $contextPackHash,
            'audit_id' => $audit['id'] ?? null,
            'prompt_section' => $promptSection,
            'summary' => $summary,
            'warnings' => $warnings,
            'next_actions' => $this->nextActions($warnings, $summary),
            'context_refs' => $contextRefs,
            'policy' => $policy,
            'hygiene_receipt' => [
                'omitted_ref_count' => $omittedRefCount,
                'omission_reasons' => $omissionReasons,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selfReflection(AiContextPack $contextPack): array
    {
        return ($this->contextReflection ?? app(ContextPackSelfReflectionGate::class))->assess($contextPack);
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function stableContextPackForHash(array $pack): array
    {
        if (is_array($pack['manifest'] ?? null)) {
            unset($pack['manifest']['created_at'], $pack['manifest']['expires_at']);
        }

        return $pack;
    }

    /**
     * @param  array<string,mixed>  $selfReflection
     * @return array<string,mixed>
     */
    private function stableSelfReflectionForHash(array $selfReflection): array
    {
        unset($selfReflection['assessed_at']);

        return $selfReflection;
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<string,mixed>
     */
    private function stableOperatorContextForHash(array $operatorContext): array
    {
        $operatorIdHash = is_string($operatorContext['operator_id'] ?? null)
            ? hash('sha256', (string) $operatorContext['operator_id'])
            : ($operatorContext['operator_id_hash'] ?? null);

        unset($operatorContext['operator_id']);

        if (is_string($operatorContext['operator_id_hash'] ?? null)) {
            return $operatorContext;
        }

        return $operatorContext + [
            'operator_id_hash' => $operatorIdHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $selfReflection
     * @return array<int,string>
     */
    private function selfReflectionWarnings(array $selfReflection): array
    {
        return match ((string) ($selfReflection['status'] ?? 'unknown')) {
            ContextPackSelfReflectionGate::STATUS_INSUFFICIENT => ['context_pack_insufficient'],
            ContextPackSelfReflectionGate::STATUS_CONTRADICTORY => ['context_pack_contradictory'],
            ContextPackSelfReflectionGate::STATUS_RISKY => ['context_pack_risky'],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function payload(array $options): array
    {
        return is_array($options['payload'] ?? null) ? $options['payload'] : [];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    private function surface(array $options, array $payload): string
    {
        $explicit = $this->string(data_get($options, 'open_brain.surface', data_get($payload, 'open_brain.surface')));
        if ($explicit && in_array($explicit, ['cli_dev', 'cli_continue', 'cli_chat', 'app_ai', 'api', 'system'], true)) {
            return $explicit;
        }

        $appSurface = Str::of((string) data_get($payload, 'app_surface', data_get($payload, 'surface', '')))->lower()->value();
        if (str_contains($appSurface, 'atlas_cli')) {
            $devPlan = data_get($payload, 'dev_execution_plan');
            if (is_array($devPlan) && ! empty($devPlan)) {
                return data_get($devPlan, 'resumed_at') ? 'cli_continue' : 'cli_dev';
            }

            return 'cli_chat';
        }

        if (str_contains($appSurface, 'atlas_ai') || ($options['source_type'] ?? null) === 'app') {
            return 'app_ai';
        }

        return ($options['source_type'] ?? null) === 'manual' ? 'cli_chat' : 'api';
    }

    private function workspace(mixed $workspace): ?string
    {
        if (! is_scalar($workspace) || trim((string) $workspace) === '') {
            $workspace = config('atlas.ai.workdir');
        }

        if (! is_scalar($workspace) || trim((string) $workspace) === '') {
            return null;
        }

        $workspace = trim((string) $workspace);

        return realpath($workspace) ?: $workspace;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function engineeringContext(?string $workspace, array $payload): array
    {
        return [
            'project_id' => data_get($payload, 'project_id'),
            'task_id' => data_get($payload, 'task_id'),
            'engineering_run_id' => data_get($payload, 'engineering_run_id', data_get($payload, 'run_id')),
            'workspace' => $workspace,
            'contract' => data_get($payload, 'dev_execution_plan.engineering_contract', []),
            'blueprint' => data_get($payload, 'dev_execution_plan.engineering_blueprint', []),
            'tags' => array_values(array_filter([
                data_get($payload, 'atlas_workflow_mode'),
                data_get($payload, 'routing_task'),
                data_get($payload, 'routing_domain'),
                data_get($payload, 'task_type'),
            ], 'is_string')),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    private function knowledgeRefs(array $context): array
    {
        try {
            return $this->knowledge->contextRefs($context, (int) config('atlas.open_brain.injection.knowledge_ref_limit', 6));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    private function codeRefs(array $context): array
    {
        try {
            return $this->code->contextRefs($context, (int) config('atlas.open_brain.injection.code_ref_limit', 8));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * AP-815 I-4 (Stage 2) — pull the precise, BM25-ranked code-graph context pack for the
     * task through the proven {@see CodeGraphContextRetriever} ("free-text query + changed
     * files → workspace-scoped, budgeted E-3 pack", the same path as `atlas:ctx`).
     *
     * FLAG-GATED, default-OFF: when `config('atlas.code_graph.auto_context')` is false this
     * returns [] WITHOUT resolving the retriever, touching the DB, or reading the clock, so
     * the surrounding injection (hash, refs, prompt) stays byte-identical to before. The
     * retriever itself never throws (best-effort recall), but the call is still wrapped so
     * any unexpected fault degrades to [] rather than failing the injection.
     *
     * The query is the operator's free-text input; the changed-file set is the SAME
     * programming `selected_files` already gathered for {@see programmingContextSummary()}
     * (so a task that names the files it touches biases retrieval toward them). The
     * workspace id is resolved from the injection's workspace path via the canonical
     * {@see CodeGraphWorkspaceIdentity}.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<int,array<string,mixed>> the pack's included symbols (E-3 shape), or []
     */
    private function precomputedCodeGraphRefs(array $fusedPack): array
    {
        return collect((array) ($fusedPack['code_graph'] ?? []))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): array => [
                'type' => 'atlas_code_graph_symbol',
                'id' => (string) ($item['id'] ?? ''),
                'symbol_type' => (string) ($item['symbol_type'] ?? ''),
                'file_path' => (string) ($item['file_path'] ?? ''),
                'signature' => (string) ($item['signature'] ?? ''),
                'tokens' => (int) ($item['tokens'] ?? 0),
                'provider_safe' => true,
            ])
            ->filter(static fn (array $item): bool => $item['id'] !== '')
            ->values()
            ->all();
    }

    private function precomputedMemoryRecallRefs(array $fusedPack): array
    {
        return collect((array) ($fusedPack['memory'] ?? []))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static function (array $item): array {
                $sourceId = trim((string) ($item['id'] ?? ''));
                $title = (string) ($item['title'] ?? '');
                $summary = (string) ($item['summary'] ?? ($item['body'] ?? ''));

                return [
                    'type' => 'atlas_memory_recall',
                    'id' => $sourceId !== ''
                        ? (string) ($item['source_type'] ?? 'memory').':'.$sourceId
                        : 'memory:'.hash('sha256', $title.'|'.$summary),
                    'memory_type' => (string) ($item['type'] ?? 'memory'),
                    'scope' => (string) ($item['scope'] ?? ''),
                    'title' => $title,
                    'summary' => $summary,
                    'reason' => 'precomputed AOBG provider-safe recall',
                    'provider_safe' => true,
                ];
            })
            ->values()
            ->all();
    }

    private function precomputedRealityGraphRefs(array $fusedPack): array
    {
        return collect((array) ($fusedPack['reality_graph_paths'] ?? []))
            ->filter(static fn (mixed $path): bool => is_array($path))
            ->map(fn (array $path): ?array => $this->precomputedRealityGraphRef($path))
            ->filter()
            ->values()
            ->all();
    }

    private function precomputedRealityGraphRef(array $path): ?array
    {
        $chain = array_values(array_filter((array) ($path['chain'] ?? []), 'is_array'));
        $hops = array_values(array_filter((array) ($path['hops'] ?? []), 'is_array'));
        if (count($chain) < 2 || count($hops) !== count($chain) - 1) {
            return null;
        }

        $nodes = [];
        $nodeIds = [];
        foreach ($chain as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                return null;
            }
            $nodeIds[] = $id;
            $nodes[] = [
                'kind' => (string) ($node['kind'] ?? $node['source_kind'] ?? ''),
                'label' => (string) ($node['label'] ?? ''),
                'source_kind' => (string) ($node['source_kind'] ?? ''),
                'source_id' => (string) ($node['source_id'] ?? ''),
            ];
        }

        $parts = [$nodes[0]['kind']];
        $confidences = [];
        foreach ($hops as $index => $hop) {
            $edgeKind = trim((string) ($hop['edge_kind'] ?? $hop['kind'] ?? ''));
            if ($edgeKind === '') {
                return null;
            }
            $parts[] = $edgeKind;
            $parts[] = $nodes[$index + 1]['kind'];
            if (is_numeric($hop['confidence'] ?? null)) {
                $confidences[] = (float) $hop['confidence'];
            }
        }

        return [
            'type' => 'atlas_reality_path',
            'id' => implode('>', $nodeIds),
            'chain_label' => implode('→', $parts),
            'nodes' => $nodes,
            'confidence_min' => $confidences === [] ? null : round(min($confidences), 4),
            'cross_layer' => (bool) ($path['cross_layer'] ?? false),
            'provider_safe' => true,
        ];
    }

    private function codeGraphRefs(string $input, array $payload, array $pack, ?string $workspace): array
    {
        if (! (bool) config('atlas.code_graph.auto_context', false)) {
            return [];
        }

        try {
            $retriever = $this->codeGraph ?? app(CodeGraphContextRetriever::class);
            $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);
            $budget = (int) config('atlas.code_graph.auto_context_budget', CodeGraphContextRetriever::DEFAULT_BUDGET);

            $result = $retriever->packFor(
                $input,
                $workspaceId,
                $budget,
                $this->codeGraphChangedFiles($payload, $pack),
            );

            $included = $result['included'] ?? [];

            return is_array($included)
                ? array_values(array_filter($included, static fn (mixed $ref): bool => is_array($ref)))
                : [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The changed/selected file set for code-graph retrieval, mirroring the sources
     * {@see programmingContextSummary()} draws `selected_files` from (the context pack's
     * `selected_files`, the engineering contract's `likely_files`, and the dev plan's
     * `selected_files`). Deduped, blank-stripped, capped — used purely to bias the BM25
     * retrieval toward the files the task touches.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<int,string>
     */
    private function codeGraphChangedFiles(array $payload, array $pack): array
    {
        $devPlan = (array) data_get($payload, 'dev_execution_plan', []);
        $messagePlan = (array) data_get($payload, 'programming_message_plan', []);
        $contract = (array) (data_get($devPlan, 'engineering_contract')
            ?: data_get($messagePlan, 'engineering_contract')
            ?: data_get($pack, 'engineering.contract')
            ?: []);

        return collect([
            ...(array) data_get($pack, 'selected_files', []),
            ...(array) data_get($contract, 'likely_files', []),
            ...(array) data_get($devPlan, 'selected_files', []),
        ])
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (mixed $file): string => trim((string) $file))
            ->unique()
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * R4 (PART A) — pull the operator's accrued, provider-safe SEMANTIC memory recall
     * (decisions/learnings) for this task through the SHARED, now-pgvector
     * {@see AtlasHybridMemoryRetrievalService::recall} (the same engine behind
     * `atlas_memory_recall` / `atlas:memory:recall`). This is the live-prompt wiring of
     * that retrieval path — NOT a second retrieval engine.
     *
     * FLAG-GATED, default-OFF: when `config('atlas.open_brain.injection.include_memory_recall')`
     * is false this returns [] WITHOUT resolving the service, touching the DB, or reading
     * the clock, so the surrounding injection (hash, refs, prompt) stays byte-identical to
     * before. recall() is best-effort but the call is still wrapped so any fault degrades
     * to [] rather than failing the injection (fail-open).
     *
     * Provider-safety is enforced INSIDE recall() (registry rows pass
     * AtlasMemoryPrivacyService::providerAllowed + provider title/summary/body; verbatim
     * rows require external_ai_allowed===true + redacted_text). Here we expose only the
     * already-redacted title/summary/reason — never the raw `text`/`body` — so no PII or
     * non-provider-safe content can leak into the prompt.
     *
     * @param  array<string,mixed>  $context  the engineering context (scope/tags) for recall
     * @param  array<string,mixed>  $pack
     * @return array<int,array<string,mixed>> provider-safe recall refs, or []
     */
    private function memoryRecallRefs(string $input, array $context, array $pack): array
    {
        if (! (bool) config('atlas.open_brain.injection.include_memory_recall', false)) {
            return [];
        }

        try {
            $service = $this->memoryRecall ?? app(AtlasHybridMemoryRetrievalService::class);
            $limit = max(1, (int) config('atlas.open_brain.injection.memory_recall_limit', 6));

            $result = $service->recall(
                trim($input),
                $this->memoryRecallContext($context, $pack),
                [],
                [
                    'limit' => $limit,
                    'requester' => 'atlas_open_brain_context_injection',
                ],
            );

            $recall = $result['recall'] ?? [];

            return collect(is_array($recall) ? $recall : [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'type' => 'atlas_memory_recall',
                    'id' => is_scalar($item['source_ref_id'] ?? null) && trim((string) $item['source_ref_id']) !== ''
                        ? (string) $item['source_ref_type'].':'.(string) $item['source_ref_id']
                        : (string) ($item['type'] ?? 'memory').':'.hash('sha256', (string) ($item['title'] ?? '').'|'.(string) ($item['summary'] ?? '')),
                    'memory_type' => (string) ($item['type'] ?? 'memory'),
                    'scope' => (string) ($item['scope'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'summary' => (string) ($item['summary'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                    // The source recall row carries the unsafe-marker verdict; propagate it
                    // verbatim instead of hard-coding true, so the hygiene gate downstream can
                    // still see/drop quarantined or otherwise unsafe recalled memory.
                    'provider_safe' => ($item['provider_safe'] ?? true) !== false,
                    'quarantine' => (bool) ($item['quarantine'] ?? false),
                    'require_sanitization' => (bool) ($item['require_sanitization'] ?? false),
                    'non_instructional_context' => (bool) ($item['non_instructional_context'] ?? false),
                    'hostile_memory' => (bool) ($item['hostile_memory'] ?? false),
                    'raw_prompt_leakage' => (bool) ($item['raw_prompt_leakage'] ?? false),
                    'raw_prompt_detected' => (bool) ($item['raw_prompt_detected'] ?? false),
                ])
                ->values()
                ->all();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The scope/tags handed to recall() so the operator's memory is biased to this task's
     * project/workspace, mirroring the same context {@see engineeringContext()} builds.
     *
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function memoryRecallContext(array $context, array $pack): array
    {
        return array_filter([
            'project_id' => $context['project_id'] ?? null,
            'task_id' => $context['task_id'] ?? null,
            'engineering_run_id' => $context['engineering_run_id'] ?? null,
            'workspace' => $context['workspace'] ?? null,
            'domain' => data_get($pack, 'task.domain'),
            'tags' => array_values(array_filter((array) ($context['tags'] ?? []), 'is_string')),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Stable, order-independent projection of the recall refs for the deterministic context
     * hash — keyed on id only (drops the redacted prose so two runs over the same recalled
     * memory rows hash identically regardless of summary phrasing/order).
     *
     * @param  array<int,array<string,mixed>>  $memoryRecallRefs
     * @return array<int,string>
     */
    private function stableMemoryRecallForHash(array $memoryRecallRefs): array
    {
        return collect($memoryRecallRefs)
            ->map(fn (array $ref): string => (string) ($ref['id'] ?? ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * F3 (Salto 1 — AURG vivo) — read-back: the fused Unified Reality Graph
     * (atlas_aurg_nodes/atlas_aurg_edges, built by atlas:aurg:ingest) answers the task
     * query through the SHARED {@see AtlasRealityGraphQueryService} (the same engine
     * behind `atlas:aurg:query`) and its TOP cross-layer chains become compact,
     * provenance-tagged prompt refs. This is the live-prompt wiring of the existing
     * brain query — NOT a second graph engine.
     *
     * FLAG-GATED, default-OFF: when `config('atlas.open_brain.injection.include_reality_graph')`
     * is false this returns [] WITHOUT resolving the service, touching the DB, or
     * reading the clock, so the surrounding injection (hash, refs, prompt) stays
     * byte-identical to before. Any fault degrades to [] rather than failing the
     * injection (fail-open), same contract as code_graph/memory_recall above.
     *
     * PROVIDER-BOUND ALWAYS: `provider_bound` is HARD-CODED true on this path — the
     * assembled section is a provider prompt by definition, so seeds AND every BFS
     * step are restricted to provider_safe && !sensitive nodes inside the query
     * service (structural exclusion, never post-filtering). Node labels are
     * provider-safe by F1 construction (redacted memory titles, ids/hashes for
     * evidence, module paths for code) — payloads never live in the brain.
     *
     * "Top" paths = ranked target order: the query's `nodes` array is already ranked
     * (Python networkx via GraphRankRuntimeClient when it ran, HONEST insertion order
     * otherwise), so paths are ordered by their target's rank position — no PHP
     * re-scoring stand-in. Mapping is deterministic cite-or-omit: a chain is kept ONLY
     * when every node id on it resolves against the query result and its hops line up.
     *
     * @return array<int,array<string,mixed>> compact provider-safe path refs, or []
     */
    private function realityGraphRefs(string $input): array
    {
        if (! (bool) config('atlas.open_brain.injection.include_reality_graph', false)) {
            return [];
        }

        try {
            $service = $this->realityGraph ?? app(AtlasRealityGraphQueryService::class);
            $limit = max(1, (int) config('atlas.open_brain.injection.reality_graph_limit', 6));

            $result = $service->query(trim($input), [
                // The prompt path is provider-bound by definition — never optional here.
                'provider_bound' => true,
            ]);

            $nodesById = [];
            foreach ((array) ($result['nodes'] ?? []) as $node) {
                if (is_array($node) && is_scalar($node['id'] ?? null) && (string) $node['id'] !== '') {
                    $nodesById[(string) $node['id']] = $node;
                }
            }
            $rankPosition = array_flip(array_keys($nodesById));

            $paths = collect((array) ($result['paths'] ?? []))
                ->filter(fn (mixed $path): bool => is_array($path))
                ->sortBy(fn (array $path): int => $rankPosition[(string) ($path['target'] ?? '')] ?? PHP_INT_MAX)
                ->values();

            $refs = [];
            foreach ($paths as $path) {
                if (count($refs) >= $limit) {
                    break;
                }
                $ref = $this->realityGraphPathRef($path, $nodesById);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }

            return $refs;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * One compact, provider-safe ref per cross-layer chain: the REAL node kinds and
     * stored edge kinds joined as a chain label (e.g. 'memory_entry→references→module'),
     * the resolved nodes as compact refs (kind/label/source_kind/source_id — never
     * payloads), and the weakest hop confidence. Cite-or-omit: returns null when any
     * chain node is missing from the result, a hop carries no stored edge kind, or the
     * hop count does not line up with the chain — partial chains are dropped, never
     * patched or invented.
     *
     * @param  array<string,mixed>  $path  one F2 path ({target, seed, nodes, hops, cross_layer})
     * @param  array<string,array<string,mixed>>  $nodesById  the query's nodes keyed by id
     * @return array<string,mixed>|null
     */
    private function realityGraphPathRef(array $path, array $nodesById): ?array
    {
        $chainIds = [];
        foreach ((array) ($path['nodes'] ?? []) as $nodeId) {
            if (! is_scalar($nodeId) || trim((string) $nodeId) === '') {
                return null;
            }
            $chainIds[] = (string) $nodeId;
        }

        $hops = array_values(array_filter((array) ($path['hops'] ?? []), 'is_array'));
        if (count($chainIds) < 2 || count($hops) !== count($chainIds) - 1) {
            return null;
        }

        $nodes = [];
        foreach ($chainIds as $nodeId) {
            $node = $nodesById[$nodeId] ?? null;
            if ($node === null) {
                return null;
            }
            $nodes[] = [
                'kind' => (string) ($node['kind'] ?? ''),
                'label' => (string) ($node['label'] ?? ''),
                'source_kind' => (string) ($node['source_kind'] ?? ''),
                'source_id' => (string) ($node['source_id'] ?? ''),
            ];
        }

        $chainParts = [$nodes[0]['kind']];
        $confidences = [];
        foreach ($hops as $index => $hop) {
            $edgeKind = is_scalar($hop['edge_kind'] ?? null) ? trim((string) $hop['edge_kind']) : '';
            if ($edgeKind === '') {
                return null;
            }
            $chainParts[] = $edgeKind;
            $chainParts[] = $nodes[$index + 1]['kind'];
            if (is_numeric($hop['confidence'] ?? null)) {
                $confidences[] = (float) $hop['confidence'];
            }
        }

        return [
            'type' => 'atlas_reality_path',
            'id' => implode('>', $chainIds),
            'chain_label' => implode('→', $chainParts),
            'nodes' => $nodes,
            'confidence_min' => $confidences === [] ? null : round(min($confidences), 4),
            'cross_layer' => (bool) ($path['cross_layer'] ?? false),
            'provider_safe' => true,
        ];
    }

    /**
     * Stable, order-independent projection of the reality-graph refs for the
     * deterministic context hash — keyed on the chain id only (the joined deterministic
     * node ids), sorted, so two runs over the same reached chains hash identically
     * regardless of path order.
     *
     * @param  array<int,array<string,mixed>>  $realityGraphRefs
     * @return array<int,string>
     */
    private function stableRealityGraphForHash(array $realityGraphRefs): array
    {
        return collect($realityGraphRefs)
            ->map(fn (array $ref): string => (string) ($ref['id'] ?? ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Consume a context delivery policy already computed by ATER/ACRS. Open Brain never
     * computes this policy itself; it only projects the provider-safe subset into the
     * prompt so external providers receive a small first packet plus expansion handles.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>|null
     */
    private function contextDeliveryPolicy(array $payload, array $pack): ?array
    {
        foreach ([
            data_get($payload, 'context_delivery_policy'),
            data_get($payload, 'open_brain.context_delivery_policy'),
            data_get($payload, 'token_economy.context_delivery_policy'),
            data_get($pack, 'context_delivery_policy'),
            data_get($pack, 'token_economy.context_delivery_policy'),
        ] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $policy = $this->providerSafeContextDeliveryPolicy($candidate);
            if ($policy !== null) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>|null
     */
    private function providerSafeContextDeliveryPolicy(array $policy): ?array
    {
        if ((string) ($policy['status'] ?? '') !== 'active') {
            return null;
        }

        if (data_get($policy, 'policy.raw_text_exposed') === true || data_get($policy, 'policy.provider_safe_only') === false) {
            return null;
        }

        return [
            'schema_version' => $this->stringValue($policy['schema_version'] ?? null, 'atlas.token_economy.context_delivery_policy.v1'),
            'status' => 'active',
            'source' => $this->stringValue($policy['source'] ?? null, 'unknown'),
            'delivery_mode' => $this->stringValue($policy['delivery_mode'] ?? null, 'standard_compiled_pack'),
            'reason' => $this->stringValue($policy['reason'] ?? null, 'context_delivery_policy_active'),
            'initial_context_token_budget' => max(0, (int) ($policy['initial_context_token_budget'] ?? 0)),
            'expansion_token_reserve' => max(0, (int) ($policy['expansion_token_reserve'] ?? 0)),
            'initial_ref_limit' => max(0, (int) ($policy['initial_ref_limit'] ?? 0)),
            'initial_source_types' => $this->stringList($policy['initial_source_types'] ?? []),
            'deferred_source_types' => $this->stringList($policy['deferred_source_types'] ?? []),
            'guarded_required_source_types' => $this->stringList($policy['guarded_required_source_types'] ?? []),
            'expansion_triggers' => $this->stringList($policy['expansion_triggers'] ?? []),
            'quality_gate_hint' => $this->stringValue($policy['quality_gate_hint'] ?? null, 'feedback_guided_staging_allowed'),
            'advisory_only' => true,
            'policy' => [
                'provider_safe_only' => true,
                'raw_text_exposed' => false,
                'providers_invoked' => false,
                'writes' => false,
                'auto_apply_learning' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<int,array<string,mixed>>
     */
    private function contextDeliveryRefs(array $policy): array
    {
        $refs = [];
        foreach ((array) ($policy['initial_source_types'] ?? []) as $sourceType) {
            $refs[] = [
                'type' => 'atlas_context_initial_source',
                'id' => 'initial:'.$sourceType,
                'source_type' => $sourceType,
                'reason' => 'context_delivery_initial_source',
                'provider_safe' => true,
            ];
        }
        foreach ((array) ($policy['deferred_source_types'] ?? []) as $sourceType) {
            $refs[] = [
                'type' => 'atlas_context_expansion_handle',
                'id' => 'expand:'.$sourceType,
                'source_type' => $sourceType,
                'reason' => 'context_delivery_deferred_source',
                'provider_safe' => true,
            ];
        }
        foreach ((array) ($policy['guarded_required_source_types'] ?? []) as $sourceType) {
            $refs[] = [
                'type' => 'atlas_context_required_recheck',
                'id' => 'recheck:'.$sourceType,
                'source_type' => $sourceType,
                'reason' => 'context_delivery_required_source_recheck',
                'provider_safe' => true,
            ];
        }

        return $this->mergeRefs($refs);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function contextDeliveryPolicySummary(array $policy): array
    {
        return [
            'schema_version' => (string) ($policy['schema_version'] ?? 'atlas.token_economy.context_delivery_policy.v1'),
            'status' => 'active',
            'delivery_mode' => (string) ($policy['delivery_mode'] ?? 'standard_compiled_pack'),
            'source' => (string) ($policy['source'] ?? 'unknown'),
            'initial_context_token_budget' => (int) ($policy['initial_context_token_budget'] ?? 0),
            'expansion_token_reserve' => (int) ($policy['expansion_token_reserve'] ?? 0),
            'initial_ref_limit' => (int) ($policy['initial_ref_limit'] ?? 0),
            'initial_source_types' => (array) ($policy['initial_source_types'] ?? []),
            'deferred_source_types' => (array) ($policy['deferred_source_types'] ?? []),
            'guarded_required_source_types' => (array) ($policy['guarded_required_source_types'] ?? []),
            'expansion_handle_count' => count((array) ($policy['deferred_source_types'] ?? [])) + count((array) ($policy['guarded_required_source_types'] ?? [])),
            'quality_gate_hint' => (string) ($policy['quality_gate_hint'] ?? 'feedback_guided_staging_allowed'),
            'advisory_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function stableContextDeliveryPolicyForHash(array $policy): array
    {
        return [
            'schema_version' => (string) ($policy['schema_version'] ?? ''),
            'source' => (string) ($policy['source'] ?? ''),
            'delivery_mode' => (string) ($policy['delivery_mode'] ?? ''),
            'initial_context_token_budget' => (int) ($policy['initial_context_token_budget'] ?? 0),
            'expansion_token_reserve' => (int) ($policy['expansion_token_reserve'] ?? 0),
            'initial_ref_limit' => (int) ($policy['initial_ref_limit'] ?? 0),
            'initial_source_types' => $this->sortedStrings((array) ($policy['initial_source_types'] ?? [])),
            'deferred_source_types' => $this->sortedStrings((array) ($policy['deferred_source_types'] ?? [])),
            'guarded_required_source_types' => $this->sortedStrings((array) ($policy['guarded_required_source_types'] ?? [])),
            'expansion_triggers' => $this->sortedStrings((array) ($policy['expansion_triggers'] ?? [])),
            'quality_gate_hint' => (string) ($policy['quality_gate_hint'] ?? ''),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function contextDeliveryPolicyWarnings(?array $policy): array
    {
        if ($policy === null) {
            return [];
        }

        return (array) ($policy['guarded_required_source_types'] ?? []) !== []
            ? ['context_delivery_required_source_recheck']
            : [];
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->take(24)
            ->all();
    }

    /**
     * @param  array<int,string>  $strings
     * @return array<int,string>
     */
    private function sortedStrings(array $strings): array
    {
        return collect($strings)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function operatorContext(array $payload, AiTaskRequest $task, array $policy, array $options): array
    {
        if (! (bool) config('atlas_operator_intelligence.enabled', true)) {
            return [
                'schema_version' => 'atlas.operator_context.v1',
                'enabled' => false,
                'status' => 'skipped',
                'reason' => 'operator_intelligence_disabled',
                'items' => [],
                'omitted' => [],
            ];
        }

        foreach ([
            'operator_profile_items',
            'operator_profile_policy_rules',
            'operator_profile_feedback_events',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return [
                    'schema_version' => 'atlas.operator_context.v1',
                    'enabled' => true,
                    'status' => 'unavailable',
                    'reason' => 'operator_profile_tables_missing:'.$table,
                    'items' => [],
                    'omitted' => [],
                ];
            }
        }

        try {
            $composer = $this->operatorContext ?? app(OperatorContextComposer::class);
            $context = $composer->compose([
                'operator_id' => $this->operatorId($payload),
                'flow' => $this->operatorFlow($payload, $task),
                'provider_external' => true,
                'limit' => (int) config('atlas_operator_intelligence.max_injected_profile_items', 8),
                'trace_id' => is_string(data_get($payload, 'trace_id')) ? (string) data_get($payload, 'trace_id') : null,
                'session_id' => is_string(data_get($payload, 'session_id')) ? (string) data_get($payload, 'session_id') : null,
                'record_usage' => ! $this->isPreview($options, $payload),
            ]);

            return array_merge($context, [
                'enabled' => true,
                'status' => 'ready',
                'reason' => 'operator_profile_context_composed',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'schema_version' => 'atlas.operator_context.v1',
                'enabled' => true,
                'status' => 'unavailable',
                'reason' => 'operator_context_exception:'.class_basename($exception),
                'items' => [],
                'omitted' => [],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function operatorId(array $payload): string
    {
        $candidate = data_get($payload, 'operator_id')
            ?: data_get($payload, 'operator.id')
            ?: data_get($payload, 'user_id')
            ?: data_get($payload, 'auth.operator_id');

        return is_scalar($candidate) && trim((string) $candidate) !== ''
            ? trim((string) $candidate)
            : (string) config('atlas_operator_intelligence.default_operator_id', 'default');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function operatorFlow(array $payload, AiTaskRequest $task): ?string
    {
        foreach ([
            data_get($payload, 'programming_flow'),
            data_get($payload, 'dev_execution_plan.programming_flow'),
            data_get($payload, 'programming_message_plan.programming_flow'),
            data_get($payload, 'routing_task'),
            data_get($payload, 'task_type'),
            $task->taskType(),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<int,array<string,mixed>>
     */
    private function operatorContextRefs(array $operatorContext): array
    {
        return collect((array) ($operatorContext['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'type' => 'operator_profile_item',
                'id' => (string) ($item['id'] ?? ''),
                'profile_key' => (string) ($item['profile_key'] ?? ''),
                'taxonomy_item_id' => (string) ($item['taxonomy_item_id'] ?? ''),
                'effect' => (string) ($item['effect'] ?? ''),
                'provider_safe' => true,
                'reason' => 'operator_intelligence_profile_match',
            ])
            ->filter(fn (array $ref): bool => $ref['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<string,mixed>
     */
    private function operatorContextSummary(array $operatorContext): array
    {
        $items = collect((array) ($operatorContext['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        return [
            'schema_version' => 'atlas.operator_open_brain_context.v1',
            'enabled' => (bool) ($operatorContext['enabled'] ?? true),
            'status' => (string) ($operatorContext['status'] ?? 'unknown'),
            'reason' => (string) ($operatorContext['reason'] ?? 'unknown'),
            'operator_id_hash' => is_string($operatorContext['operator_id'] ?? null) ? hash('sha256', (string) $operatorContext['operator_id']) : null,
            'flow' => $operatorContext['flow'] ?? null,
            'provider_external' => (bool) ($operatorContext['provider_external'] ?? true),
            'item_count' => $items->count(),
            'omitted_count' => count((array) ($operatorContext['omitted'] ?? [])),
            'profile_keys' => $items
                ->map(fn (array $item): string => (string) ($item['profile_key'] ?? ''))
                ->filter()
                ->take(12)
                ->values()
                ->all(),
            'effects' => $items
                ->map(fn (array $item): string => (string) ($item['effect'] ?? ''))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<int,string>
     */
    private function operatorContextWarnings(array $operatorContext): array
    {
        if (($operatorContext['status'] ?? null) === 'unavailable') {
            return ['operator_context_unavailable'];
        }

        if (($operatorContext['enabled'] ?? true) === false) {
            return ['operator_context_disabled'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>|null
     */
    private function memoryQuality(array $context, array $policy): ?array
    {
        if (! (bool) config('atlas.open_brain.injection.include_memory_quality', true)) {
            return null;
        }

        try {
            $service = $this->memoryQuality ?? app(AtlasMemoryQualityService::class);

            return $service->scorecard([
                'workspace' => $context['workspace'] ?? null,
                'project_id' => $context['project_id'] ?? null,
                'task_id' => $context['task_id'] ?? null,
                'engineering_run_id' => $context['engineering_run_id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'status' => 'unavailable',
                'score' => null,
                'counts' => [],
                'issues' => [[
                    'code' => 'memory_quality_unavailable',
                    'severity' => 'warning',
                ]],
                'recommendations' => ['Inspect logs and run atlas memory quality before relying on Open Brain.'],
                'generated_at' => now()->toJSON(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>|null  $memoryQuality
     * @return array<string,mixed>
     */
    private function memoryQualitySummary(?array $memoryQuality): array
    {
        if ($memoryQuality === null) {
            return [
                'included' => false,
            ];
        }

        $issues = collect((array) ($memoryQuality['issues'] ?? []))
            ->filter(fn (mixed $issue): bool => is_array($issue))
            ->map(fn (array $issue): array => array_filter([
                'code' => is_scalar($issue['code'] ?? null) ? (string) $issue['code'] : null,
                'severity' => is_scalar($issue['severity'] ?? null) ? (string) $issue['severity'] : null,
                'count' => isset($issue['count']) && is_numeric($issue['count']) ? (int) $issue['count'] : null,
                'score' => isset($issue['score']) && is_numeric($issue['score']) ? (int) $issue['score'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();

        return [
            'included' => true,
            'ok' => (bool) ($memoryQuality['ok'] ?? false),
            'status' => is_scalar($memoryQuality['status'] ?? null) ? (string) $memoryQuality['status'] : 'unknown',
            'score' => isset($memoryQuality['score']) && is_numeric($memoryQuality['score']) ? (int) $memoryQuality['score'] : null,
            'active' => isset($memoryQuality['counts']['active']) && is_numeric($memoryQuality['counts']['active']) ? (int) $memoryQuality['counts']['active'] : null,
            'provider_safe_active' => isset($memoryQuality['counts']['provider_safe_active']) && is_numeric($memoryQuality['counts']['provider_safe_active'])
                ? (int) $memoryQuality['counts']['provider_safe_active']
                : null,
            'latest_snapshot' => is_array($memoryQuality['latest_snapshot'] ?? null)
                ? [
                    'status' => $memoryQuality['latest_snapshot']['status'] ?? null,
                    'score' => $memoryQuality['latest_snapshot']['score'] ?? null,
                    'snapshot_at' => $memoryQuality['latest_snapshot']['snapshot_at'] ?? null,
                ]
                : null,
            'trend' => $this->memoryQualityTrendSummary($memoryQuality),
            'issues' => array_slice($issues, 0, 8),
        ];
    }

    /**
     * @param  array<string,mixed>  $memoryQuality
     * @return array<string,mixed>|null
     */
    private function memoryQualityTrendSummary(array $memoryQuality): ?array
    {
        if (! is_array($memoryQuality['trend'] ?? null)) {
            return null;
        }

        $trend = $memoryQuality['trend'];
        $drivers = collect((array) ($trend['drivers'] ?? []))
            ->filter(fn (mixed $driver): bool => is_array($driver))
            ->map(fn (array $driver): array => array_filter([
                'kind' => is_scalar($driver['kind'] ?? null) ? (string) $driver['kind'] : null,
                'key' => is_scalar($driver['key'] ?? null) ? (string) $driver['key'] : null,
                'severity' => is_scalar($driver['severity'] ?? null) ? (string) $driver['severity'] : null,
                'delta' => isset($driver['delta']) && is_numeric($driver['delta']) ? (int) $driver['delta'] : null,
                'current' => isset($driver['current']) && is_numeric($driver['current']) ? (int) $driver['current'] : null,
                'previous' => isset($driver['previous']) && is_numeric($driver['previous']) ? (int) $driver['previous'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->take(5)
            ->all();

        $summary = array_filter([
            'status' => is_scalar($trend['status'] ?? null) ? (string) $trend['status'] : null,
            'current_score' => isset($trend['current_score']) && is_numeric($trend['current_score']) ? (int) $trend['current_score'] : null,
            'latest_snapshot_score' => isset($trend['latest_snapshot_score']) && is_numeric($trend['latest_snapshot_score']) ? (int) $trend['latest_snapshot_score'] : null,
            'previous_snapshot_score' => isset($trend['previous_snapshot_score']) && is_numeric($trend['previous_snapshot_score']) ? (int) $trend['previous_snapshot_score'] : null,
            'snapshot_count' => isset($trend['snapshot_count']) && is_numeric($trend['snapshot_count']) ? (int) $trend['snapshot_count'] : null,
            'current_delta_from_latest' => isset($trend['current_delta_from_latest']) && is_numeric($trend['current_delta_from_latest']) ? (int) $trend['current_delta_from_latest'] : null,
            'latest_delta_from_previous' => isset($trend['latest_delta_from_previous']) && is_numeric($trend['latest_delta_from_previous']) ? (int) $trend['latest_delta_from_previous'] : null,
            'window_delta' => isset($trend['window_delta']) && is_numeric($trend['window_delta']) ? (int) $trend['window_delta'] : null,
            'drivers' => $drivers,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return $summary === [] ? null : $summary;
    }

    /**
     * @param  array<string,mixed>|null  $memoryQuality
     * @return array<int,string>
     */
    private function memoryQualityWarnings(?array $memoryQuality): array
    {
        if ($memoryQuality === null) {
            return [];
        }

        $status = is_scalar($memoryQuality['status'] ?? null) ? (string) $memoryQuality['status'] : 'unknown';
        $score = isset($memoryQuality['score']) && is_numeric($memoryQuality['score']) ? (int) $memoryQuality['score'] : null;
        $warnings = [];

        if ($status === 'not_migrated') {
            $warnings[] = 'memory_quality_not_migrated';
        } elseif ($status === 'empty') {
            $warnings[] = 'memory_quality_empty';
        } elseif ($status === 'critical') {
            $warnings[] = 'memory_quality_critical';
        } elseif (in_array($status, ['needs_review', 'watch', 'unavailable'], true)) {
            $warnings[] = 'memory_quality_'.$status;
        }

        if ($score !== null && $score < 70) {
            $warnings[] = 'memory_quality_score_low';
        }

        $trendStatus = data_get($memoryQuality, 'trend.status');
        if ($trendStatus === 'regressed') {
            $warnings[] = 'memory_quality_trend_regressed';
        } elseif ($trendStatus === 'watch_regressed') {
            $warnings[] = 'memory_quality_trend_watch_regressed';
        }

        $issueCodes = collect((array) ($memoryQuality['issues'] ?? []))
            ->filter(fn (mixed $issue): bool => is_array($issue) && is_scalar($issue['code'] ?? null))
            ->map(fn (array $issue): string => (string) $issue['code'])
            ->values()
            ->all();

        foreach (['no_provider_safe_memory', 'accepted_learning_not_promoted', 'negative_memory_feedback'] as $issueCode) {
            if (in_array($issueCode, $issueCodes, true)) {
                $warnings[] = 'memory_quality_'.$issueCode;
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<int,array<string,mixed>>  $codeGraphRefs
     * @param  array<int,array<string,mixed>>  $memoryRecallRefs
     * @param  array<int,array<string,mixed>>  $realityGraphRefs
     * @param  list<array<string,mixed>|mixed>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function orderFusedSourceRefs(
        array $codeGraphRefs,
        array $memoryRecallRefs,
        array $realityGraphRefs,
        array $candidates,
    ): array {
        $pools = [
            'code' => $codeGraphRefs,
            'memory' => $memoryRecallRefs,
            'reality' => $realityGraphRefs,
        ];
        $ordered = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $source = (string) ($candidate['source'] ?? '');
            $ref = trim((string) ($candidate['ref'] ?? ''));
            if ($ref === '' || ! isset($pools[$source])) {
                continue;
            }
            foreach ($pools[$source] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = (string) ($item['id'] ?? '');
                if ($itemId === '' || isset($seen[$source.':'.$itemId])) {
                    continue;
                }
                if (! $this->fusionCandidateMatchesRef($source, $ref, $itemId)) {
                    continue;
                }
                $ordered[] = $item;
                $seen[$source.':'.$itemId] = true;
                break;
            }
        }
        foreach (['code', 'memory', 'reality'] as $source) {
            foreach ($pools[$source] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = (string) ($item['id'] ?? '');
                $key = $source.':'.($itemId !== '' ? $itemId : md5(json_encode($item) ?: ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $ordered[] = $item;
                $seen[$key] = true;
            }
        }

        return $ordered;
    }

    private function fusionCandidateMatchesRef(string $source, string $candidateRef, string $itemId): bool
    {
        if ($itemId === $candidateRef) {
            return true;
        }
        if ($source === 'memory') {
            $suffix = ':'.$candidateRef;

            return str_ends_with($itemId, $suffix);
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  ...$refGroups
     * @return array<int,array<string,mixed>>
     */
    private function mergeRefs(array ...$refGroups): array
    {
        return collect($refGroups)
            ->flatten(1)
            ->filter(fn (mixed $ref): bool => is_array($ref))
            ->unique(fn (array $ref): string => (string) ($ref['type'] ?? 'unknown').':'.(string) ($ref['id'] ?? $ref['slug'] ?? $ref['canonical_path'] ?? $ref['root_path'] ?? md5(json_encode($ref) ?: '')))
            ->values()
            ->all();
    }

    /**
     * Hardening: a ref carrying any unsafe marker (quarantine, require_sanitization,
     * non_instructional_context, hostile_memory, raw_prompt_leakage, raw_prompt_detected)
     * or an explicit provider_safe===false must NEVER reach a rendered provider prompt
     * section. This is the single shared choke point all ref lists pass through before
     * being merged/rendered/hashed.
     */
    private function isRefProviderSafe(array $ref): bool
    {
        foreach (['quarantine', 'require_sanitization', 'non_instructional_context', 'hostile_memory', 'raw_prompt_leakage', 'raw_prompt_detected'] as $marker) {
            if ((bool) ($ref[$marker] ?? false)) {
                return false;
            }
        }

        return ($ref['provider_safe'] ?? true) !== false;
    }

    /**
     * Filters a ref list through {@see isRefProviderSafe()}, recording which marker(s)
     * caused each omission into $omissionReasons (deduplicated by caller) and bumping
     * $omittedCount for every dropped ref.
     *
     * @param  array<int,array<string,mixed>>  $refs
     * @param  array<int,string>  $omissionReasons
     * @return array<int,array<string,mixed>>
     */
    private function filterProviderUnsafeRefs(array $refs, array &$omissionReasons, int &$omittedCount): array
    {
        $markers = ['quarantine', 'require_sanitization', 'non_instructional_context', 'hostile_memory', 'raw_prompt_leakage', 'raw_prompt_detected'];

        return array_values(array_filter($refs, function (mixed $ref) use ($markers, &$omissionReasons, &$omittedCount): bool {
            if (! is_array($ref)) {
                return true;
            }

            $reasons = [];
            foreach ($markers as $marker) {
                if ((bool) ($ref[$marker] ?? false)) {
                    $reasons[] = $marker;
                }
            }
            if (($ref['provider_safe'] ?? true) === false) {
                $reasons[] = 'provider_safe_false';
            }

            if ($reasons === []) {
                return true;
            }

            $omittedCount++;
            $omissionReasons = [...$omissionReasons, ...$reasons];

            return false;
        }));
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function summary(array $contextRefs, array $knowledgeRefs, array $codeRefs, array $policy, array $contextPack): array
    {
        $refs = collect($contextRefs);
        $retrievalPlan = $this->retrievalPlanSummary((array) data_get($contextPack, 'retrieval', []), $contextRefs, $knowledgeRefs, $codeRefs, $contextPack);

        return [
            'context_refs' => $refs->count(),
            'memory_refs' => $refs->where('type', 'atlas_memory_entry')->count(),
            'verbatim_refs' => $refs->where('type', 'atlas_verbatim_memory')->count(),
            'semantic_refs' => $refs->where('type', 'semantic_note')->count(),
            'memory_recall_refs' => $refs->where('type', 'atlas_memory_recall')->count(),
            'reality_graph_refs' => $refs->where('type', 'atlas_reality_path')->count(),
            'operator_profile_refs' => $refs->where('type', 'operator_profile_item')->count(),
            'context_expansion_handles' => $refs->whereIn('type', ['atlas_context_expansion_handle', 'atlas_context_required_recheck'])->count(),
            'knowledge_refs' => count($knowledgeRefs),
            'code_refs' => count($codeRefs),
            'budget_chars' => (int) $policy['budget_chars'],
            'used_chars' => 0,
            'provider_safe' => true,
            'retrieval_plan' => $retrievalPlan,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function programmingContextSummary(array $payload, AiContextPack $contextPack): array
    {
        $devPlan = (array) data_get($payload, 'dev_execution_plan', []);
        $messagePlan = (array) data_get($payload, 'programming_message_plan', []);
        $repair = (array) data_get($payload, 'programming_repair', []);
        $agenticRag = (array) (
            data_get($payload, 'agentic_rag_plan')
            ?: data_get($devPlan, 'agentic_rag_plan')
            ?: data_get($messagePlan, 'agentic_rag_plan')
            ?: []
        );
        $pack = $contextPack->toArray();
        $contract = (array) (data_get($devPlan, 'engineering_contract')
            ?: data_get($messagePlan, 'engineering_contract')
            ?: data_get($pack, 'engineering.contract')
            ?: []);

        $flow = $this->string(
            data_get($payload, 'programming_flow')
            ?: data_get($devPlan, 'programming_flow')
            ?: data_get($messagePlan, 'programming_flow')
            ?: data_get($payload, 'routing_task')
        );
        $profile = $this->string(
            data_get($payload, 'programming_profile')
            ?: data_get($devPlan, 'programming_profile')
            ?: data_get($messagePlan, 'programming_profile')
        );
        $selectedFiles = collect([
            ...(array) data_get($pack, 'selected_files', []),
            ...(array) data_get($contract, 'likely_files', []),
            ...(array) data_get($devPlan, 'selected_files', []),
        ])
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (mixed $file): string => trim((string) $file))
            ->unique()
            ->values()
            ->take(20)
            ->all();
        $priorRuns = collect((array) data_get($pack, 'prior_runs', []))
            ->filter(fn (mixed $run): bool => is_array($run))
            ->values()
            ->take(5)
            ->all();
        $previousTraces = collect((array) data_get($pack, 'evidence.previous_traces', []))
            ->filter(fn (mixed $trace): bool => is_array($trace))
            ->values()
            ->take(5)
            ->all();
        $priorDecisions = collect([
            ...(array) data_get($pack, 'decisions', []),
            ...(array) data_get($pack, 'continuity.active_state.decisions', []),
            ...(array) data_get($devPlan, 'prior_decisions', []),
            ...(array) data_get($messagePlan, 'prior_decisions', []),
        ])
            ->filter(fn (mixed $decision): bool => is_array($decision) || (is_scalar($decision) && trim((string) $decision) !== ''))
            ->map(fn (mixed $decision): array => is_array($decision) ? $decision : ['text' => trim((string) $decision)])
            ->values()
            ->take(8)
            ->all();

        return [
            'schema_version' => 'atlas.programming.open_brain_context.v1',
            'flow' => $flow,
            'profile' => $profile,
            'intent' => $this->string(data_get($payload, 'programming_intent', data_get($devPlan, 'operator_options.programming_intent'))),
            'resume' => [
                'resumed' => (bool) data_get($devPlan, 'resumed_at') || (bool) data_get($messagePlan, 'resumed_at'),
                'parent_plan_id' => $this->string(data_get($devPlan, 'parent_plan_id', data_get($messagePlan, 'parent_plan_id'))),
                'plan_id' => $this->string(data_get($devPlan, 'plan_id', data_get($messagePlan, 'plan_id'))),
            ],
            'stage_contract' => [
                'plan' => true,
                'review' => in_array($flow, ['programming.review', 'programming.refactor', 'programming.forge'], true),
                'patch' => ! in_array($flow, ['programming.review'], true),
                'test' => (bool) data_get($devPlan, 'operator_options.auto_test', data_get($messagePlan, 'execution_profile.auto_test', false)),
                'repair' => $flow === 'programming.repair' || (bool) data_get($repair, 'enabled', false),
            ],
            'selected_files' => $selectedFiles,
            'selected_file_count' => count($selectedFiles),
            'prior_run_count' => count($priorRuns),
            'prior_runs' => $priorRuns,
            'previous_trace_count' => count($previousTraces),
            'previous_traces' => $previousTraces,
            'prior_decision_count' => count($priorDecisions),
            'prior_decisions' => $priorDecisions,
            'agentic_rag' => $agenticRag === [] ? null : [
                'schema_version' => data_get($agenticRag, 'schema_version'),
                'status' => data_get($agenticRag, 'status'),
                'required_sources' => (array) data_get($agenticRag, 'required_sources', []),
                'missing_required_sources' => (array) data_get($agenticRag, 'missing_required_sources', []),
                'retrieval_receipt_id' => data_get($agenticRag, 'retrieval_receipt.receipt_id'),
                'context_gate_status' => data_get($agenticRag, 'context_sufficiency_gate.status'),
                'semantic_node_count' => data_get($agenticRag, 'semantic_code_graph.node_count'),
                'semantic_edge_count' => data_get($agenticRag, 'semantic_code_graph.edge_count'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<string,mixed>|null
     */
    private function retrievalPlanSummary(array $retrievalPlan, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): ?array
    {
        if ($retrievalPlan === []) {
            return null;
        }

        $selected = array_values(array_filter((array) ($retrievalPlan['selected_sources'] ?? []), 'is_array'));
        $required = array_values(array_filter($selected, fn (array $source): bool => (bool) ($source['required'] ?? false)));
        $availability = $this->retrievalSourceAvailability($selected, $contextRefs, $knowledgeRefs, $codeRefs, $contextPack);

        $reviewSignal = $this->retrievalReviewSignal($availability);

        return [
            'schema_version' => $retrievalPlan['schema_version'] ?? null,
            'mode' => $retrievalPlan['mode'] ?? null,
            'selected_source_count' => count($selected),
            'selected_sources' => array_values(array_map(fn (array $source): string => (string) ($source['type'] ?? 'unknown'), $selected)),
            'required_sources' => array_values(array_map(fn (array $source): string => (string) ($source['type'] ?? 'unknown'), $required)),
            'available_sources' => array_values(array_keys(array_filter($availability, fn (array $source): bool => (bool) $source['available']))),
            'unavailable_sources' => array_values(array_keys(array_filter($availability, fn (array $source): bool => ! (bool) $source['available']))),
            'required_unavailable_sources' => array_values(array_keys(array_filter($availability, fn (array $source): bool => (bool) $source['required'] && ! (bool) $source['available']))),
            'availability' => $availability,
            'review_signal' => $reviewSignal,
            'provider_safe_only' => (bool) data_get($retrievalPlan, 'policy.provider_safe_only', true),
            'max_context_refs' => data_get($retrievalPlan, 'budgets.max_context_refs'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<string,mixed>  $contextPack
     * @return array<string,array<string,mixed>>
     */
    private function retrievalSourceAvailability(array $selected, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): array
    {
        $refs = collect($contextRefs);
        $counts = [
            'vector_retrieval' => $refs->where('type', 'semantic_note')->count(),
            'memory_signals' => $refs->whereIn('type', ['atlas_memory_entry', 'atlas_verbatim_memory', 'semantic_note'])->count(),
            'code_intelligence' => count($codeRefs),
            'evidence_replay' => $this->evidenceReplayCount($contextRefs, $contextPack),
            'graph_retrieval' => $this->graphRetrievalCount($contextRefs, $contextPack),
            'knowledge_base' => count($knowledgeRefs),
        ];

        $availability = [];
        foreach ($selected as $source) {
            $type = (string) ($source['type'] ?? 'unknown');
            $count = (int) ($counts[$type] ?? 0);
            $availability[$type] = [
                'available' => $count > 0,
                'count' => $count,
                'required' => (bool) ($source['required'] ?? false),
                'unavailable_action' => (string) ($source['unavailable_action'] ?? 'degrade_with_review_signal'),
            ];
        }

        return $availability;
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<string,mixed>  $contextPack
     */
    private function evidenceReplayCount(array $contextRefs, array $contextPack): int
    {
        $refCount = collect($contextRefs)
            ->whereIn('type', ['atlas_ledger_event', 'atlas_replay_event', 'evidence_replay'])
            ->count();

        return $refCount
            + count((array) data_get($contextPack, 'evidence.previous_traces', []))
            + count((array) data_get($contextPack, 'evidence.replay_events', []))
            + count((array) data_get($contextPack, 'evidence.replay_refs', []));
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<string,mixed>  $contextPack
     */
    private function graphRetrievalCount(array $contextRefs, array $contextPack): int
    {
        $refCount = collect($contextRefs)
            ->whereIn('type', ['graph_relation', 'knowledge_graph_edge', 'graph_retrieval'])
            ->count();

        return $refCount + count((array) data_get($contextPack, 'graph.relations', []));
    }

    /**
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<int,string>
     */
    private function retrievalPlanWarnings(array $retrievalPlan): array
    {
        $unavailable = array_values((array) ($retrievalPlan['unavailable_sources'] ?? []));
        $requiredUnavailable = array_values((array) ($retrievalPlan['required_unavailable_sources'] ?? []));
        $warnings = [];

        if ($unavailable !== []) {
            $warnings[] = 'retrieval_source_unavailable';
        }

        if ($requiredUnavailable !== []) {
            $warnings[] = 'retrieval_required_source_unavailable';
        }

        return $warnings;
    }

    /**
     * @param  array<string,array<string,mixed>>  $availability
     * @return array<string,mixed>
     */
    private function retrievalReviewSignal(array $availability): array
    {
        $unavailable = array_values(array_keys(array_filter($availability, fn (array $source): bool => ! (bool) $source['available'])));
        $requiredUnavailable = array_values(array_keys(array_filter($availability, fn (array $source): bool => (bool) $source['required'] && ! (bool) $source['available'])));

        if ($requiredUnavailable !== []) {
            return [
                'status' => 'blocking',
                'severity' => 'high',
                'reason' => 'required_retrieval_source_unavailable',
                'sources' => $requiredUnavailable,
                'recommended_action' => $this->retrievalRecommendedAction($requiredUnavailable),
            ];
        }

        if ($unavailable !== []) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reason' => 'optional_retrieval_source_unavailable',
                'sources' => $unavailable,
                'recommended_action' => $this->retrievalRecommendedAction($unavailable),
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reason' => 'all_selected_retrieval_sources_available',
            'sources' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  array<int,string>  $sources
     */
    private function retrievalRecommendedAction(array $sources): string
    {
        if (in_array('evidence_replay', $sources, true)) {
            return 'refresh_evidence_replay_or_attach_trace_before_retry';
        }

        if (in_array('code_intelligence', $sources, true)) {
            return 'refresh_code_intelligence_before_retry';
        }

        if (in_array('memory_signals', $sources, true) || in_array('vector_retrieval', $sources, true)) {
            return 'refresh_memory_context_before_retry';
        }

        if (in_array('graph_retrieval', $sources, true)) {
            return 'degrade_graph_context_or_attach_relationship_evidence';
        }

        return 'refresh_context_sources_before_retry';
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $policy
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<int,array<string,mixed>>  $codeGraphRefs
     * @param  array<int,array<string,mixed>>  $memoryRecallRefs
     * @param  array<int,array<string,mixed>>  $realityGraphRefs
     * @param  array<string,mixed>|null  $contextDeliveryPolicy
     * @param  array<int,string>  $warnings
     */
    private function promptSection(
        AiTaskRequest $task,
        AiContextPack $contextPack,
        string $contextPackHash,
        array $summary,
        array $policy,
        ?array $memoryQuality,
        array $knowledgeRefs,
        array $codeRefs,
        array $codeGraphRefs,
        array $memoryRecallRefs,
        array $realityGraphRefs,
        ?array $contextDeliveryPolicy,
        array $warnings,
    ): string {
        $lines = [
            '# Atlas Open Brain Context',
            '',
            'Use este bloco como contexto provider-safe montado pelo Atlas. Ele e auditavel, pequeno e subordinado a privacy/redaction.',
            '- status: pending_audit',
            '- surface: '.($policy['surface'] ?? 'unknown'),
            '- task_type: '.$task->taskType(),
            '- desired_mode: '.$task->desiredMode(),
            '- context_pack_hash: '.$contextPackHash,
            '- refs: memory='.$summary['memory_refs'].'; verbatim='.$summary['verbatim_refs'].'; semantic='.$summary['semantic_refs'].'; operator='.$summary['operator_profile_refs'].'; knowledge='.$summary['knowledge_refs'].'; code='.$summary['code_refs'],
        ];

        if (is_array($summary['retrieval_plan'] ?? null)) {
            $retrieval = $summary['retrieval_plan'];
            $lines[] = '- retrieval_plan: mode='.($retrieval['mode'] ?? 'unknown')
                .'; selected='.implode(',', (array) ($retrieval['selected_sources'] ?? []))
                .'; required='.implode(',', (array) ($retrieval['required_sources'] ?? []));
        }

        if ($warnings !== []) {
            $lines[] = '- warnings: '.implode(', ', $warnings);
        }

        if ($contextDeliveryPolicy !== null) {
            $lines[] = '- context_delivery: mode='.($contextDeliveryPolicy['delivery_mode'] ?? 'unknown')
                .'; initial_tokens='.(int) ($contextDeliveryPolicy['initial_context_token_budget'] ?? 0)
                .'; expansion_reserve='.(int) ($contextDeliveryPolicy['expansion_token_reserve'] ?? 0)
                .'; handles='.(int) data_get($summary, 'context_delivery_policy.expansion_handle_count', 0);
        }

        if ($memoryQuality !== null) {
            $qualitySummary = $this->memoryQualitySummary($memoryQuality);
            $lines[] = '';
            $lines[] = '## Memory Quality Gate';
            $lines[] = '- status: '.($qualitySummary['status'] ?? 'unknown').'; score='.($qualitySummary['score'] ?? 'n/a').'; ok='.(($qualitySummary['ok'] ?? false) ? 'true' : 'false');
            $lines[] = '- active: '.($qualitySummary['active'] ?? 'n/a').'; provider_safe_active='.($qualitySummary['provider_safe_active'] ?? 'n/a');
            if (is_array($qualitySummary['latest_snapshot'] ?? null) && $qualitySummary['latest_snapshot'] !== []) {
                $snapshot = $qualitySummary['latest_snapshot'];
                $lines[] = '- latest_snapshot: status='.($snapshot['status'] ?? 'n/a').'; score='.($snapshot['score'] ?? 'n/a').'; at='.($snapshot['snapshot_at'] ?? 'n/a');
            }
            if (is_array($qualitySummary['trend'] ?? null) && $qualitySummary['trend'] !== []) {
                $trend = $qualitySummary['trend'];
                $lines[] = '- trend: status='.($trend['status'] ?? 'n/a')
                    .'; current_delta_from_latest='.($trend['current_delta_from_latest'] ?? 'n/a')
                    .'; latest_delta_from_previous='.($trend['latest_delta_from_previous'] ?? 'n/a')
                    .'; snapshots='.($trend['snapshot_count'] ?? 'n/a');
                $drivers = array_values((array) ($trend['drivers'] ?? []));
                if ($drivers !== []) {
                    $lines[] = '- trend_drivers: '.collect($drivers)
                        ->map(fn (array $driver): string => (string) ($driver['key'] ?? 'unknown').':'.(string) ($driver['delta'] ?? 'n/a'))
                        ->implode(', ');
                }
            }
            $issues = array_values((array) ($qualitySummary['issues'] ?? []));
            if ($issues !== []) {
                $lines[] = '- issues: '.collect($issues)
                    ->map(fn (array $issue): string => (string) ($issue['severity'] ?? 'info').':'.(string) ($issue['code'] ?? 'unknown'))
                    ->implode(', ');
            }
        }

        if (is_array($summary['self_reflection'] ?? null)) {
            $reflection = $summary['self_reflection'];
            $lines[] = '';
            $lines[] = '## Context Pack Self-Reflection Gate';
            $lines[] = '- schema: '.($reflection['schema_version'] ?? 'unknown');
            $lines[] = '- status: '.($reflection['status'] ?? 'unknown').'; recommended_action='.($reflection['recommended_action'] ?? 'n/a');
            $reasons = array_values((array) ($reflection['reasons'] ?? []));
            if ($reasons !== []) {
                $lines[] = '- reasons: '.implode(', ', $reasons);
            }
        }

        if (is_array($summary['programming_context'] ?? null)) {
            $programming = $summary['programming_context'];
            $lines[] = '';
            $lines[] = '## Programming Context';
            $lines[] = '- schema: '.($programming['schema_version'] ?? 'unknown');
            $lines[] = '- flow: '.($programming['flow'] ?: 'n/a').'; profile='.($programming['profile'] ?: 'n/a').'; intent='.($programming['intent'] ?: 'n/a');
            $lines[] = '- resume: '.(data_get($programming, 'resume.resumed') ? 'true' : 'false')
                .'; plan_id='.(data_get($programming, 'resume.plan_id') ?: 'n/a')
                .'; parent_plan_id='.(data_get($programming, 'resume.parent_plan_id') ?: 'n/a');
            $stage = (array) ($programming['stage_contract'] ?? []);
            $lines[] = '- stages: plan='.(($stage['plan'] ?? false) ? 'true' : 'false')
                .'; review='.(($stage['review'] ?? false) ? 'true' : 'false')
                .'; patch='.(($stage['patch'] ?? false) ? 'true' : 'false')
                .'; test='.(($stage['test'] ?? false) ? 'true' : 'false')
                .'; repair='.(($stage['repair'] ?? false) ? 'true' : 'false');
            if (is_array($programming['agentic_rag'] ?? null)) {
                $rag = $programming['agentic_rag'];
                $lines[] = '- agentic_rag: status='.($rag['status'] ?? 'unknown')
                    .'; context_gate='.($rag['context_gate_status'] ?? 'unknown')
                    .'; receipt='.($rag['retrieval_receipt_id'] ?? 'n/a');
                $required = array_values((array) ($rag['required_sources'] ?? []));
                if ($required !== []) {
                    $lines[] = '- agentic_rag_required_sources: '.implode(', ', $required);
                }
                $missing = array_values((array) ($rag['missing_required_sources'] ?? []));
                if ($missing !== []) {
                    $lines[] = '- agentic_rag_missing_sources: '.implode(', ', $missing);
                }
            }
            $selectedFiles = array_values((array) ($programming['selected_files'] ?? []));
            if ($selectedFiles !== []) {
                $lines[] = '- selected_files: '.implode(', ', array_slice($selectedFiles, 0, 12));
            }
            $lines[] = '- history: prior_runs='.(int) ($programming['prior_run_count'] ?? 0)
                .'; previous_traces='.(int) ($programming['previous_trace_count'] ?? 0)
                .'; prior_decisions='.(int) ($programming['prior_decision_count'] ?? 0);
        }

        if ($contextDeliveryPolicy !== null) {
            $lines[] = '';
            $lines[] = '## Context Delivery Policy';
            $lines[] = '- schema: '.($contextDeliveryPolicy['schema_version'] ?? 'unknown');
            $lines[] = '- mode: '.($contextDeliveryPolicy['delivery_mode'] ?? 'unknown')
                .'; status=active'
                .'; source='.($contextDeliveryPolicy['source'] ?? 'unknown')
                .'; advisory=true';
            $lines[] = '- initial: tokens='.(int) ($contextDeliveryPolicy['initial_context_token_budget'] ?? 0)
                .'; ref_limit='.(int) ($contextDeliveryPolicy['initial_ref_limit'] ?? 0)
                .'; expansion_reserve='.(int) ($contextDeliveryPolicy['expansion_token_reserve'] ?? 0);
            foreach ([
                'initial_source_types' => 'initial_sources',
                'deferred_source_types' => 'deferred_sources',
                'guarded_required_source_types' => 'guarded_required_sources',
                'expansion_triggers' => 'expansion_triggers',
            ] as $key => $label) {
                $values = array_values((array) ($contextDeliveryPolicy[$key] ?? []));
                if ($values !== []) {
                    $lines[] = '- '.$label.': '.implode(', ', array_slice($values, 0, 12));
                }
            }
            $lines[] = '- quality_gate_hint: '.($contextDeliveryPolicy['quality_gate_hint'] ?? 'feedback_guided_staging_allowed');
            $deferredHandles = array_map(static fn (mixed $source): string => 'expand:'.(string) $source, array_values((array) ($contextDeliveryPolicy['deferred_source_types'] ?? [])));
            $guardedHandles = array_map(static fn (mixed $source): string => 'recheck:'.(string) $source, array_values((array) ($contextDeliveryPolicy['guarded_required_source_types'] ?? [])));
            $expansionHandles = array_values(array_filter(array_merge($deferredHandles, $guardedHandles)));
            if ($expansionHandles !== []) {
                $lines[] = '- expansion_tool: mcp=atlas_context_expand; cli="./bin/atlas open-brain expand-context <handle> \"<objective>\" --json"';
                $lines[] = '- expansion_handles: '.implode(', ', array_slice($expansionHandles, 0, 16));
            }
            if ($guardedHandles !== []) {
                $lines[] = '- implementation_gate: expand guarded handles before code changes.';
            }
            $lines[] = '- policy: provider_safe_only=true; raw_text_exposed=false; providers_invoked=false; writes=false';
        }

        if (is_array($summary['operator_context'] ?? null)) {
            $operator = $summary['operator_context'];
            $lines[] = '';
            $lines[] = '## Operator Intelligence';
            $lines[] = '- schema: '.($operator['schema_version'] ?? 'unknown');
            $lines[] = '- status: '.($operator['status'] ?? 'unknown')
                .'; reason='.($operator['reason'] ?? 'unknown')
                .'; items='.(int) ($operator['item_count'] ?? 0)
                .'; omitted='.(int) ($operator['omitted_count'] ?? 0)
                .'; flow='.(($operator['flow'] ?? null) ?: 'n/a');
            $profileKeys = array_values((array) ($operator['profile_keys'] ?? []));
            if ($profileKeys !== []) {
                $lines[] = '- profile_keys: '.implode(', ', $profileKeys);
            }
            $effects = array_values((array) ($operator['effects'] ?? []));
            if ($effects !== []) {
                $lines[] = '- effects: '.implode(', ', $effects);
            }
            $operatorItems = collect((array) data_get($summary, 'operator_context_items', []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->take(8)
                ->values()
                ->all();
            foreach ($operatorItems as $item) {
                $lines[] = '- '.$this->providerSafeOperatorItemLine($item);
            }
        }

        // F3/F5 (Salto 1 — AURG vivo): the brain's TOP cross-layer chains, one line per path —
        // the REAL node kinds + stored edge kinds as the chain label (read from the ref's
        // own fields, never the 'atlas_reality_path' envelope type), then the
        // human-readable node labels with [src=source_kind] provenance tags.
        // Empty (flag OFF or no reached paths) → nothing rendered → byte-identical prompt.
        // PLACEMENT IS LOAD-BEARING (F5 live-proof finding): this block renders BEFORE the
        // bulky knowledge/code/code-graph ref lists because the section budget truncates
        // from the TAIL (Str::limit) — at the old tail position a routine >budget section
        // (code-graph auto-context ON) silently dropped these ~6 compact lines every time,
        // making the include_reality_graph flag a no-op in exactly the prompts it serves.
        if ($realityGraphRefs !== []) {
            $lines[] = '';
            $lines[] = '## Atlas Unified Reality Graph';
            foreach ($realityGraphRefs as $ref) {
                $chain = collect((array) ($ref['nodes'] ?? []))
                    ->filter(fn (mixed $node): bool => is_array($node))
                    ->map(function (array $node): string {
                        $label = is_scalar($node['label'] ?? null) ? trim((string) $node['label']) : '';

                        return ($label !== '' ? Str::limit($label, 100, '...') : 'n/a')
                            .' [src='.(($node['source_kind'] ?? '') !== '' ? $node['source_kind'] : 'n/a').']';
                    })
                    ->implode(' -> ');
                $confidenceMin = $ref['confidence_min'] ?? null;
                $lines[] = '- '.(($ref['chain_label'] ?? '') !== '' ? $ref['chain_label'] : 'path').': '.$chain
                    .'; cross_layer='.(($ref['cross_layer'] ?? false) ? 'true' : 'false')
                    .'; confidence_min='.(is_numeric($confidenceMin) ? (string) $confidenceMin : 'n/a');
            }
        }

        // R4 (PART A): the operator's accrued, provider-safe SEMANTIC memory recall
        // (decisions/learnings) ranked by AtlasHybridMemoryRetrievalService::recall.
        // Empty (flag OFF or no matched memory) → nothing rendered → byte-identical prompt.
        // PLACEMENT IS LOAD-BEARING (same F5 live-proof finding as the reality-graph block
        // above): this block renders BEFORE the bulky knowledge/code/code-graph ref lists
        // because the section budget truncates from the TAIL (Str::limit) — at the old tail
        // position a routine >budget section (code-graph auto-context ON) silently dropped
        // the recall block from every prompt, making the include_memory_recall flag a no-op
        // in exactly the prompts it serves.
        if ($memoryRecallRefs !== []) {
            $lines[] = '';
            $lines[] = '## Atlas Memory Recall';
            foreach ($memoryRecallRefs as $ref) {
                $title = is_scalar($ref['title'] ?? null) ? trim((string) $ref['title']) : '';
                $summaryText = is_scalar($ref['summary'] ?? null) ? trim((string) $ref['summary']) : '';
                // The recalled memory's REAL type (decision/learning/principle/...) is carried
                // in `memory_type`; `type` is the ref envelope ('atlas_memory_recall') and would
                // mislabel every line. Read memory_type first, fall back to the envelope type.
                $memoryType = is_scalar($ref['memory_type'] ?? null) && trim((string) $ref['memory_type']) !== ''
                    ? trim((string) $ref['memory_type'])
                    : (is_scalar($ref['type'] ?? null) ? trim((string) $ref['type']) : '');
                $lines[] = '- '.($title !== '' ? $title : 'memoria')
                    .' [type='.($memoryType !== '' ? $memoryType : 'n/a')
                    .'; scope='.(($ref['scope'] ?? '') !== '' ? $ref['scope'] : 'n/a').']'
                    .($summaryText !== '' ? ' - '.Str::limit($summaryText, 220, '...') : '')
                    .'; reason='.(($ref['reason'] ?? '') !== '' ? $ref['reason'] : 'recall provider-safe');
            }
        }

        if ($knowledgeRefs !== []) {
            $lines[] = '';
            $lines[] = '## Canonical Engineering Knowledge';
            foreach ($knowledgeRefs as $ref) {
                $lines[] = '- '.($ref['title'] ?? $ref['slug'] ?? 'doc').' ['.($ref['canonical_path'] ?? 'n/a').'] - '.($ref['summary'] ?? $ref['reason'] ?? 'canonical doc');
            }
        }

        if ($codeRefs !== []) {
            $lines[] = '';
            $lines[] = '## Code Intelligence Refs';
            foreach ($codeRefs as $ref) {
                $lines[] = '- '.($ref['name'] ?? $ref['slug'] ?? 'module').' ['.($ref['root_path'] ?? 'n/a').'] layer='.($ref['layer'] ?? 'n/a').'; symbols='.($ref['symbol_count'] ?? 0).'; tests='.($ref['test_count'] ?? 0).'; reason='.($ref['reason'] ?? 'code context');
            }
        }

        // AP-815 I-4 (Stage 2): the budgeted, BM25-ranked code-graph symbols for this task.
        // Empty (flag OFF or no matches) → nothing rendered → byte-identical prompt.
        if ($codeGraphRefs !== []) {
            $lines[] = '';
            $lines[] = '## Code Graph Context';
            foreach ($codeGraphRefs as $ref) {
                // Render the signature VERBATIM (case-preserving) — it is code, not a
                // normalisable token, so the lowercasing string() helper is NOT used here.
                $signature = is_scalar($ref['signature'] ?? null) ? trim((string) $ref['signature']) : '';
                $lines[] = '- '.($ref['id'] ?? 'symbol')
                    .' ['.($ref['file_path'] ?? 'n/a').']'
                    .' type='.(($ref['symbol_type'] ?? '') !== '' ? $ref['symbol_type'] : 'n/a')
                    .'; tokens='.(int) ($ref['tokens'] ?? 0)
                    .($signature !== '' ? '; sig='.Str::limit($signature, 200, '...') : '');
            }
        }

        $lines[] = '';
        $lines[] = $contextPack->toPromptSection();

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function providerSafeOperatorItemLine(array $item): string
    {
        $summary = Str::limit((string) ($item['summary'] ?? ''), 220, '...');

        return 'profile_key='.($item['profile_key'] ?? 'n/a')
            .'; taxonomy='.($item['taxonomy_item_id'] ?? 'n/a')
            .'; effect='.($item['effect'] ?? 'n/a')
            .'; confidence='.($item['confidence'] ?? 'n/a')
            .'; automation='.($item['automation_level'] ?? 'n/a')
            .'; summary='.$summary;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $policy
     * @param  array<int,string>  $warnings
     * @return array<string,mixed>|null
     */
    private function recordAudit(string $input, ?string $workspace, string $contextPackHash, array $summary, array $policy, string $status, array $warnings, string $action = 'context_injection'): ?array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return null;
        }

        $log = AtlasOpenBrainAccessLog::query()->create([
            'surface' => (string) $policy['surface'],
            'requester' => $this->requester($policy),
            'action' => $action,
            'status' => $status,
            'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
            'workspace_label' => $workspace ? basename($workspace) : null,
            'context_pack_hash' => $contextPackHash,
            'context_refs_count' => (int) $summary['context_refs'],
            'memory_refs_count' => (int) $summary['memory_refs'],
            'provider_safe' => true,
            'query_json' => [
                'objective_hash' => hash('sha256', $input),
                'objective_excerpt_redacted' => true,
                'objective_length' => Str::length($input),
                'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
                'workspace_label' => $workspace ? basename($workspace) : null,
            ],
            'result_summary_json' => $summary + ['warnings' => $warnings],
            'metadata' => [
                'schema_version' => 1,
                'source' => 'atlas_open_brain_context_injection',
                'preview' => $action === 'context_injection_preview',
                'policy' => $policy,
                'query_redaction' => 'hash_only_no_raw_objective_or_workspace_path',
            ],
            'accessed_at' => now(),
        ]);

        return [
            'id' => $log->id,
            'surface' => $log->surface,
            'action' => $log->action,
            'status' => $log->status,
            'context_pack_hash' => $log->context_pack_hash,
            'context_refs_count' => $log->context_refs_count,
            'memory_refs_count' => $log->memory_refs_count,
            'provider_safe' => $log->provider_safe,
            'accessed_at' => $log->accessed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    private function isPreview(array $options, array $payload): bool
    {
        return (bool) data_get($options, 'open_brain.preview', data_get($payload, 'open_brain.preview', false));
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function requester(array $policy): string
    {
        return match ((string) $policy['surface']) {
            'cli_dev' => 'atlas-dev',
            'cli_continue' => 'atlas-continue',
            'cli_chat' => 'atlas-chat',
            'app_ai' => 'atlas-ai-app',
            default => 'atlas',
        };
    }

    /**
     * @param  array<int,string>  $warnings
     * @return array<int,string>
     */
    private function nextActions(array $warnings, array $summary = []): array
    {
        $actions = [];
        $retrievalAction = data_get($summary, 'retrieval_plan.review_signal.recommended_action');
        if (is_string($retrievalAction) && $retrievalAction !== '' && $retrievalAction !== 'none') {
            $actions[] = match ($retrievalAction) {
                'refresh_evidence_replay_or_attach_trace_before_retry' => 'Refresh evidence replay or attach trace/envelope evidence before retrying.',
                'refresh_code_intelligence_before_retry' => 'Refresh code intelligence before retrying.',
                'refresh_memory_context_before_retry' => 'Refresh Atlas memory/context sources before retrying.',
                'degrade_graph_context_or_attach_relationship_evidence' => 'Attach relationship evidence or explicitly degrade graph context before retrying.',
                default => 'Refresh unavailable retrieval sources before retrying.',
            };
        }
        if (in_array('no_engineering_knowledge_refs', $warnings, true) || in_array('no_code_intelligence_refs', $warnings, true)) {
            $actions[] = 'Run atlas memory maintain to sync docs and code intelligence.';
        }
        if (in_array('open_brain_audit_table_missing', $warnings, true)) {
            $actions[] = 'Run migrations before requiring Open Brain injection.';
        }
        if (in_array('context_delivery_required_source_recheck', $warnings, true)) {
            $actions[] = 'Call atlas_context_expand for guarded required sources before implementation.';
        }
        if ((int) data_get($summary, 'context_delivery_policy.expansion_token_reserve', 0) > 0) {
            $actions[] = 'Call atlas_context_expand for deferred sources before dumping full docs, tests or graph output.';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function skipped(array $policy, string $reason): array
    {
        return [
            'enabled' => false,
            'status' => 'skipped',
            'reason' => $reason,
            'surface' => $policy['surface'] ?? 'unknown',
            'mode' => $policy['mode'] ?? 'off',
            'workspace' => null,
            'context_pack_hash' => null,
            'audit_id' => null,
            'prompt_section' => null,
            'summary' => [
                'context_refs' => 0,
                'memory_refs' => 0,
                'knowledge_refs' => 0,
                'code_refs' => 0,
                'budget_chars' => $policy['budget_chars'] ?? null,
                'used_chars' => 0,
                'provider_safe' => true,
            ],
            'warnings' => [],
            'next_actions' => [],
            'context_refs' => [],
            'policy' => $policy,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function failed(array $policy, Throwable $exception): array
    {
        $required = ($policy['mode'] ?? null) === 'required';

        return [
            'enabled' => true,
            'status' => $required ? 'failed_closed' : 'failed_open',
            'reason' => $required ? 'required_open_brain_failed' : 'open_brain_failed_open',
            'surface' => $policy['surface'] ?? 'unknown',
            'mode' => $policy['mode'] ?? 'auto',
            'workspace' => null,
            'context_pack_hash' => null,
            'audit_id' => null,
            'prompt_section' => null,
            'summary' => [
                'context_refs' => 0,
                'memory_refs' => 0,
                'knowledge_refs' => 0,
                'code_refs' => 0,
                'budget_chars' => $policy['budget_chars'] ?? null,
                'used_chars' => 0,
                'provider_safe' => true,
            ],
            'warnings' => ['open_brain_exception:'.class_basename($exception)],
            'next_actions' => ['Inspect logs and run atlas memory maintain before retrying.'],
            'context_refs' => [],
            'policy' => $policy,
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? Str::of((string) $value)->lower()->trim()->value() : null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    public function assertAllowed(array $result): void
    {
        if (($result['status'] ?? null) === 'failed_closed') {
            throw new RuntimeException('Open Brain context injection is required but not ready.');
        }
    }
}
