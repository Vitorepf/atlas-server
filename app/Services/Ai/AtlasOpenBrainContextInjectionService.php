<?php

namespace App\Services\Ai;

use App\Models\AtlasOpenBrainAccessLog;
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
        $knowledgeRefs = $this->knowledgeRefs($engineeringContext);
        $codeRefs = $this->codeRefs($engineeringContext);
        $contextRefs = $this->mergeRefs($contextPack->contextRefs(), $knowledgeRefs, $codeRefs);
        $hashPayload = [
            'context_pack' => $pack,
            'knowledge_refs' => $knowledgeRefs,
            'code_refs' => $codeRefs,
            'policy' => $policy,
        ];
        $contextPackHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $summary = $this->summary($contextRefs, $knowledgeRefs, $codeRefs, $policy);
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

        $promptSection = $this->promptSection(
            task: $task,
            contextPack: $contextPack,
            contextPackHash: $contextPackHash,
            summary: $summary,
            policy: $policy,
            knowledgeRefs: $knowledgeRefs,
            codeRefs: $codeRefs,
            warnings: $warnings,
        );

        $usedChars = Str::length($promptSection);
        $summary['used_chars'] = $usedChars;
        if ($usedChars > (int) $policy['budget_chars']) {
            $warnings[] = 'open_brain_context_budget_exceeded';
            $promptSection = Str::limit($promptSection, (int) $policy['budget_chars'], "\n[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]");
            $summary['used_chars'] = Str::length($promptSection);
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
                'next_actions' => ['Run atlas memory maintain and retry with Open Brain ready.'],
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
            'next_actions' => $this->nextActions($warnings),
            'context_refs' => $contextRefs,
            'policy' => $policy,
        ];
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
    private function summary(array $contextRefs, array $knowledgeRefs, array $codeRefs, array $policy): array
    {
        $refs = collect($contextRefs);

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
        ];
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

        if ($warnings !== []) {
            $lines[] = '- warnings: '.implode(', ', $warnings);
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
                'objective_excerpt' => Str::limit($input, 500, ''),
                'workspace' => $workspace,
            ],
            'result_summary_json' => $summary + ['warnings' => $warnings],
            'metadata' => [
                'schema_version' => 1,
                'source' => 'atlas_open_brain_context_injection',
                'preview' => $action === 'context_injection_preview',
                'policy' => $policy,
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
    private function nextActions(array $warnings): array
    {
        $actions = [];
        if (in_array('no_engineering_knowledge_refs', $warnings, true) || in_array('no_code_intelligence_refs', $warnings, true)) {
            $actions[] = 'Run atlas memory maintain to sync docs and code intelligence.';
        }
        if (in_array('open_brain_audit_table_missing', $warnings, true)) {
            $actions[] = 'Run migrations before requiring Open Brain injection.';
        }

        return $actions;
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
