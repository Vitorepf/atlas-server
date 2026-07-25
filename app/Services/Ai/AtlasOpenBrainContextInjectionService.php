<?php

namespace App\Services\Ai;

use App\Services\Ai\OpenBrainContextInjection\StableHashSupport;
use App\Services\Ai\OpenBrainContextInjection\TextNormalizeSupport;
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
        $summary = $this->summary($contextRefs, $knowledgeRefs, $codeRefs, $policy, $pack);
        if ($contextDeliveryPolicy !== null) {
            $summary['context_delivery_policy'] = $this->contextDeliveryPolicySummary($contextDeliveryPolicy);
        }
        if ($memoryQuality !== null) {
            $summary['memory_quality'] = $memoryQualitySummary;
        }
        $summary['self_reflection'] = $selfReflection;
        $summary['programming_context'] = PromptAssemblySupport::programmingContextSummary($payload, $contextPack->toArray());
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
            ...PromptAssemblySupport::memoryQualityWarnings($memoryQuality),
            ...$this->selfReflectionWarnings($selfReflection),
            ...$this->retrievalPlan->retrievalPlanWarnings((array) ($summary['retrieval_plan'] ?? [])),
            ...PromptAssemblySupport::operatorContextWarnings($operatorContext),
            ...$this->contextDeliveryPolicyWarnings($contextDeliveryPolicy),
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

        return PromptAssemblySupport::mergeRefs($refs);
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
        return StableHashSupport::stableContextDeliveryPolicyForHash($policy);
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
        return TextNormalizeSupport::stringValue($value, $default);
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return TextNormalizeSupport::stringList($value);
    }

    /**
     * @param  array<int,string>  $strings
     * @return array<int,string>
     */
    private function sortedStrings(array $strings): array
    {
        return TextNormalizeSupport::sortedStrings($strings);
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
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function summary(array $contextRefs, array $knowledgeRefs, array $codeRefs, array $policy, array $contextPack): array
    {
        $refs = collect($contextRefs);
        $retrievalPlan = $this->retrievalPlan->retrievalPlanSummary((array) data_get($contextPack, 'retrieval', []), $contextRefs, $knowledgeRefs, $codeRefs, $contextPack);

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
