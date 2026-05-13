<?php

namespace App\Services\Ai;

use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
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
            data_get($payload, 'task_type'),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (mixed $value): string => Str::of((string) $value)->lower()->trim()->value())
            ->values()
            ->all();

        return collect($signals)->contains(fn (string $signal): bool => in_array($signal, [
            'dev',
            'debug',
            'review',
            'programming',
            'quality_repair',
            'execute',
        ], true));
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
        $workspace = $this->workspace(data_get($pack, 'surface.workspace', data_get($payload, 'workspace')));
        $engineeringContext = $this->engineeringContext($workspace, $payload);
        $memoryQuality = $this->memoryQuality($engineeringContext, $policy);
        $memoryQualitySummary = $this->memoryQualitySummary($memoryQuality);
        $selfReflection = $this->selfReflection($contextPack);
        $knowledgeRefs = $this->knowledgeRefs($engineeringContext);
        $codeRefs = $this->codeRefs($engineeringContext);
        $contextRefs = $this->mergeRefs($contextPack->contextRefs(), $knowledgeRefs, $codeRefs);
        $hashPayload = [
            'context_pack' => $this->stableContextPackForHash($pack),
            'knowledge_refs' => $knowledgeRefs,
            'code_refs' => $codeRefs,
            'memory_quality' => $memoryQualitySummary,
            'self_reflection' => $this->stableSelfReflectionForHash($selfReflection),
            'policy' => $policy,
        ];
        $contextPackHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $summary = $this->summary($contextRefs, $knowledgeRefs, $codeRefs, $policy, $pack);
        if ($memoryQuality !== null) {
            $summary['memory_quality'] = $memoryQualitySummary;
        }
        $summary['self_reflection'] = $selfReflection;
        $warnings = [];

        if ((int) $summary['memory_refs'] === 0) {
            $warnings[] = 'no_provider_safe_memory_refs';
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
            'knowledge_refs' => count($knowledgeRefs),
            'code_refs' => count($codeRefs),
            'budget_chars' => (int) $policy['budget_chars'],
            'used_chars' => 0,
            'provider_safe' => true,
            'retrieval_plan' => $retrievalPlan,
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
            '- refs: memory='.$summary['memory_refs'].'; verbatim='.$summary['verbatim_refs'].'; semantic='.$summary['semantic_refs'].'; knowledge='.$summary['knowledge_refs'].'; code='.$summary['code_refs'],
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

        $lines[] = '';
        $lines[] = $contextPack->toPromptSection();

        return implode("\n", $lines);
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
