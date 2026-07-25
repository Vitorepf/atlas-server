<?php

namespace App\Services\Ai;

use App\Services\Ai\OpenBrainContextInjection\ContextInjectionProjectionSupport;
use App\Services\Ai\OpenBrainContextInjection\StableHashSupport;
use App\Services\Ai\OpenBrainContextInjection\PromptAssemblySupport;
use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AtlasOpenBrainContextInjectionService
{
    private readonly OpenBrainContextInjection\RetrievalPlanSection $retrievalPlan;

    private readonly OpenBrainContextInjection\RefsSection $refs;

    public function __construct(
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly ?AtlasMemoryQualityService $memoryQuality = null,
        private readonly ?ContextPackSelfReflectionGate $contextReflection = null,
        private readonly ?OperatorContextComposer $operatorContext = null,
        private readonly ?CodeGraphContextRetriever $codeGraph = null,
        private readonly ?AtlasHybridMemoryRetrievalService $memoryRecall = null,
        private readonly ?AtlasRealityGraphQueryService $realityGraph = null,
    ) {
        // Godfile split (GOD-DEBULK D3, 2026-07-22): family sections wired from injected deps.
        $this->retrievalPlan = new OpenBrainContextInjection\RetrievalPlanSection();
        $this->refs = new OpenBrainContextInjection\RefsSection($knowledge, $code, $codeGraph, $memoryRecall, $realityGraph);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function inject(string $input, AiTaskRequest $task, AiContextPack $contextPack, array $options = []): array
    {
        $policy = $this->policy($task, $options);

        if ($policy['mode'] === 'off') {
            return ContextInjectionProjectionSupport::skipped($policy, 'policy_off');
        }

        if (! $policy['enabled'] && $policy['mode'] !== 'required') {
            return ContextInjectionProjectionSupport::skipped($policy, 'global_disabled');
        }

        if (! $this->requiresInjection($task, $options) && $policy['mode'] !== 'required') {
            return ContextInjectionProjectionSupport::skipped($policy, 'mode_not_open_brain');
        }

        try {
            return $this->buildInjection($input, $task, $contextPack, $options, $policy);
        } catch (Throwable $exception) {
            report($exception);

            return ContextInjectionProjectionSupport::failed($policy, $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function policy(AiTaskRequest $task, array $options): array
    {
        $payload = ContextInjectionProjectionSupport::payload($options);
        $requested = ContextInjectionProjectionSupport::lowerString(data_get($options, 'open_brain.mode', data_get($payload, 'open_brain.mode')));
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
            'surface' => ContextInjectionProjectionSupport::surface($options, $payload),
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
        $payload = ContextInjectionProjectionSupport::payload($options);
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

        return ContextInjectionProjectionSupport::anySignalRequiresInjection($signals);
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function buildInjection(string $input, AiTaskRequest $task, AiContextPack $contextPack, array $options, array $policy): array
    {
        $payload = ContextInjectionProjectionSupport::payload($options);
        $pack = $contextPack->toArray();
        $precomputedAobg = is_array($options['precomputed_aobg_pack'] ?? null)
            ? $options['precomputed_aobg_pack']
            : null;
        if ($precomputedAobg !== null && is_array($precomputedAobg['context_delivery_policy'] ?? null)) {
            $pack['context_delivery_policy'] = $precomputedAobg['context_delivery_policy'];
        }
        $workspace = $this->workspace(data_get($pack, 'surface.workspace', data_get($payload, 'workspace')));
        $engineeringContext = ContextInjectionProjectionSupport::engineeringContext($workspace, $payload);
        $memoryQuality = $this->memoryQuality($engineeringContext, $policy);
        $memoryQualitySummary = PromptAssemblySupport::memoryQualitySummary($memoryQuality);
        $selfReflection = $this->selfReflection($contextPack);
        $operatorContext = $this->operatorContext($payload, $task, $policy, $options);
        $knowledgeRefs = $this->refs->knowledgeRefs($engineeringContext);
        $codeRefs = $this->refs->codeRefs($engineeringContext);
        // AP-815 I-4 (Stage 2): the precise, BM25-ranked code-graph context pack reaches
        // the provider prompt through THIS shared seam — flag-gated, default-OFF. When the
        // flag is off this resolves to [] (no DB touch, no app() resolution, no hash key)
        // so the injection stays byte-identical to the pre-wiring behaviour.
        $codeGraphRefs = $precomputedAobg === null
            ? $this->refs->codeGraphRefs($input, $payload, $pack, $workspace)
            : $this->refs->precomputedCodeGraphRefs($precomputedAobg);
        // R4 (PART A): the operator's accrued SEMANTIC memory recall (decisions/learnings)
        // pulled through the now-pgvector AtlasHybridMemoryRetrievalService::recall — the
        // single shared semantic recall path (NOT a new retrieval engine). Flag-gated,
        // default-OFF: when off this resolves to [] (no service resolution, no DB, no hash
        // key) so the injection stays byte-identical to the pre-wiring behaviour.
        $memoryRecallRefs = $precomputedAobg === null
            ? $this->refs->memoryRecallRefs($input, $engineeringContext, $pack)
            : $this->refs->precomputedMemoryRecallRefs($precomputedAobg);
        // F3 (Salto 1 — AURG vivo): the fused reality graph's CROSS-LAYER chains enter the
        // LIVE prompt through this same seam — flag-gated, default-OFF. PROVIDER-BOUND
        // ALWAYS (hard-coded true inside realityGraphRefs): this section IS a provider
        // prompt, so the unbounded local-only view of the brain is structurally
        // unreachable from here. When the flag is off this resolves to [] (no service
        // resolution, no DB, no hash key) so the injection stays byte-identical to the
        // pre-wiring behaviour.
        $realityGraphRefs = $precomputedAobg === null
            ? $this->refs->realityGraphRefs($input)
            : $this->refs->precomputedRealityGraphRefs($precomputedAobg);
        $operatorRefs = ContextInjectionProjectionSupport::operatorContextRefs($operatorContext);
        $contextDeliveryPolicy = ContextInjectionProjectionSupport::contextDeliveryPolicy($payload, $pack);
        $contextDeliveryRefs = $contextDeliveryPolicy !== null
            ? ContextInjectionProjectionSupport::contextDeliveryRefs($contextDeliveryPolicy)
            : [];

        // Hygiene gate: drop any ref carrying an unsafe marker (quarantine,
        // require_sanitization, non_instructional_context, hostile_memory,
        // raw_prompt_leakage, raw_prompt_detected) or provider_safe===false BEFORE it can
        // reach the merged refs, the deterministic hash or the rendered prompt section.
        // Omitted refs are dropped from prompt text but counted into hygiene_receipt below.
        $omissionReasons = [];
        $omittedRefCount = 0;
        $contextPackRefs = PromptAssemblySupport::filterProviderUnsafeRefs($contextPack->contextRefs(), $omissionReasons, $omittedRefCount);
        $knowledgeRefs = PromptAssemblySupport::filterProviderUnsafeRefs($knowledgeRefs, $omissionReasons, $omittedRefCount);
        $codeRefs = PromptAssemblySupport::filterProviderUnsafeRefs($codeRefs, $omissionReasons, $omittedRefCount);
        $codeGraphRefs = PromptAssemblySupport::filterProviderUnsafeRefs($codeGraphRefs, $omissionReasons, $omittedRefCount);
        $memoryRecallRefs = PromptAssemblySupport::filterProviderUnsafeRefs($memoryRecallRefs, $omissionReasons, $omittedRefCount);
        $realityGraphRefs = PromptAssemblySupport::filterProviderUnsafeRefs($realityGraphRefs, $omissionReasons, $omittedRefCount);
        $operatorRefs = PromptAssemblySupport::filterProviderUnsafeRefs($operatorRefs, $omissionReasons, $omittedRefCount);
        $contextDeliveryRefs = PromptAssemblySupport::filterProviderUnsafeRefs($contextDeliveryRefs, $omissionReasons, $omittedRefCount);
        $omissionReasons = array_values(array_unique($omissionReasons));
        sort($omissionReasons);

        $fusedSourceRefs = null;
        if ($precomputedAobg !== null
            && (bool) data_get($precomputedAobg, 'retrieval_fusion.applied_to_sections', false)
        ) {
            $fusedSourceRefs = PromptAssemblySupport::orderFusedSourceRefs(
                $codeGraphRefs,
                $memoryRecallRefs,
                $realityGraphRefs,
                (array) data_get($precomputedAobg, 'retrieval_fusion.candidates', []),
            );
            $contextRefs = PromptAssemblySupport::mergeRefs(
                $contextPackRefs,
                $knowledgeRefs,
                $codeRefs,
                $fusedSourceRefs,
                $operatorRefs,
                $contextDeliveryRefs,
            );
        } else {
            $contextRefs = PromptAssemblySupport::mergeRefs($contextPackRefs, $knowledgeRefs, $codeRefs, $codeGraphRefs, $memoryRecallRefs, $realityGraphRefs, $operatorRefs, $contextDeliveryRefs);
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
            $hashPayload['memory_recall_refs'] = $this->refs->stableMemoryRecallForHash($memoryRecallRefs);
        }
        // Same byte-identity contract again: only fold the reality-graph chains into the
        // deterministic hash when they actually produced refs (flag ON + reached paths),
        // via an order-independent stable projection (sorted chain ids).
        if ($realityGraphRefs !== []) {
            $hashPayload['reality_graph_refs'] = $this->refs->stableRealityGraphForHash($realityGraphRefs);
        }
        if ($contextDeliveryPolicy !== null) {
            $hashPayload['context_delivery_policy'] = $this->stableContextDeliveryPolicyForHash($contextDeliveryPolicy);
        }
        if ($precomputedAobg !== null) {
            $hashPayload['retrieval_core_hash'] = (string) ($precomputedAobg['context_pack_hash'] ?? '');
        }
        $contextPackHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $retrievalPlanSummary = $this->retrievalPlan->retrievalPlanSummary(
            (array) data_get($pack, 'retrieval', []),
            $contextRefs,
            $knowledgeRefs,
            $codeRefs,
            $pack,
        );
        $summary = ContextInjectionProjectionSupport::injectionSummary(
            $contextRefs,
            $knowledgeRefs,
            $codeRefs,
            $policy,
            $retrievalPlanSummary,
        );
        if ($contextDeliveryPolicy !== null) {
            $summary['context_delivery_policy'] = ContextInjectionProjectionSupport::contextDeliveryPolicySummary($contextDeliveryPolicy);
        }
        if ($memoryQuality !== null) {
            $summary['memory_quality'] = $memoryQualitySummary;
        }
        $summary['self_reflection'] = $selfReflection;
        $summary['programming_context'] = PromptAssemblySupport::programmingContextSummary($payload, $contextPack->toArray());
        $summary['operator_context'] = ContextInjectionProjectionSupport::operatorContextSummary($operatorContext);
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
            ...PromptAssemblySupport::memoryQualityWarnings($memoryQuality),
            ...ContextInjectionProjectionSupport::selfReflectionWarnings($selfReflection),
            ...$this->retrievalPlan->retrievalPlanWarnings((array) ($summary['retrieval_plan'] ?? [])),
            ...PromptAssemblySupport::operatorContextWarnings($operatorContext),
            ...ContextInjectionProjectionSupport::contextDeliveryPolicyWarnings($contextDeliveryPolicy),
        ]));

        $promptSection = PromptAssemblySupport::promptSection(
            taskType: $task->taskType(),
            desiredMode: $task->desiredMode(),
            contextPackPromptSection: $contextPack->toPromptSection(),
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
        $auditAction = ContextInjectionProjectionSupport::isPreview($options, $payload) ? 'context_injection_preview' : 'context_injection';
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
                'next_actions' => ContextInjectionProjectionSupport::nextActions($warnings, $summary),
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
            'next_actions' => ContextInjectionProjectionSupport::nextActions($warnings, $summary),
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
        return StableHashSupport::stableContextPackForHash($pack);
    }

    /**
     * @param  array<string,mixed>  $selfReflection
     * @return array<string,mixed>
     */
    private function stableSelfReflectionForHash(array $selfReflection): array
    {
        return StableHashSupport::stableSelfReflectionForHash($selfReflection);
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<string,mixed>
     */
    private function stableOperatorContextForHash(array $operatorContext): array
    {
        return StableHashSupport::stableOperatorContextForHash($operatorContext);
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
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function stableContextDeliveryPolicyForHash(array $policy): array
    {
        return StableHashSupport::stableContextDeliveryPolicyForHash($policy);
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
                'record_usage' => ! ContextInjectionProjectionSupport::isPreview($options, $payload),
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
        return ContextInjectionProjectionSupport::firstNonEmptyString([
            data_get($payload, 'programming_flow'),
            data_get($payload, 'dev_execution_plan.programming_flow'),
            data_get($payload, 'programming_message_plan.programming_flow'),
            data_get($payload, 'routing_task'),
            data_get($payload, 'task_type'),
            $task->taskType(),
        ]);
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
            'requester' => ContextInjectionProjectionSupport::requester($policy),
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
     * @param  array<string,mixed>  $result
     */
    public function assertAllowed(array $result): void
    {
        if (($result['status'] ?? null) === 'failed_closed') {
            throw new RuntimeException('Open Brain context injection is required but not ready.');
        }
    }
}
