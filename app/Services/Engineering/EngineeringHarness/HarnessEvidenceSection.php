<?php

namespace App\Services\Engineering\EngineeringHarness;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use Illuminate\Support\Str;
use App\Services\Engineering\EngineeringControlRegistryService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReviewFindingService;

class HarnessEvidenceSection
{
    public function __construct(
        private readonly EngineeringRunArtifactService $artifacts,
        private readonly EngineeringControlRegistryService $controlRegistry,
        private readonly EngineeringWorkspaceService $workspaces,
        private readonly EngineeringReviewFindingService $reviewFindings,
        private readonly ?AtlasWorkspacePathResolverService $workspacePaths = null,
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate = null,
    ) {}

    public function recordEvidence(AtlasTask $task, AtlasEngineeringRun $run, array $scoring, mixed $patch): void
    {
        $decision = (string) ($scoring['decision'] ?? 'partial');
        $status = match ($decision) {
            'resolved' => 'passed',
            'unsafe', 'unresolved' => 'failed',
            default => 'needs_review',
        };

        $this->artifacts->recordEvidence($task, [
            'evidence_type' => 'validation_evidence',
            'target_id' => 'engineering_harness_run:'.$run->id,
            'status' => $status,
            'confidence' => $decision === 'resolved' ? 0.92 : 0.7,
            'summary' => 'Engineering Harness Runner finalizou com decision='.$decision.' score='.(string) ($scoring['score'] ?? 0).'.',
            'files' => array_values((array) ($patch?->changed_files_json ?? [])),
            'metadata' => [
                'engineering_run_id' => $run->id,
                'decision' => $decision,
                'score' => $scoring['score'] ?? null,
                'components' => $scoring['components'] ?? [],
                'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
            ],
        ], 'atlas:engineering:runner');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function awisMutationBlock(
        string $workspace,
        AtlasTask $task,
        bool $dryRun,
        bool $noProvider,
        string $requestedSandbox,
        bool $applyIsolatedPatch,
    ): ?array {
        $mutative = ! $dryRun && (! $noProvider || ($applyIsolatedPatch && in_array($requestedSandbox, ['worktree', 'docker'], true)));
        if (! $mutative) {
            return null;
        }

        $resolver = $this->workspacePaths ?? app(AtlasWorkspacePathResolverService::class);
        $gateService = $this->workspaceGate ?? app(AtlasWorkspaceIntelligenceExecutionGateService::class);
        $resolution = $resolver->resolveForExecution($workspace);

        if (($resolution['status'] ?? null) !== 'ready') {
            return $this->awisBlockedPayload($task, [
                'schema_version' => 'atlas.engineering_runner.awis_gate.v1',
                'status' => 'blocked',
                'error' => 'awis_workspace_required_for_engineering_run',
                'workspace_resolution' => $resolution,
            ]);
        }

        $gate = $gateService->gate(
            workspace: (string) $resolution['workspace_slug'],
            mode: 'dev',
            task: trim((string) ($task->title ?? $task->body ?? $task->id)),
        );
        if ((bool) ($gate['allowed'] ?? false)) {
            return null;
        }

        return $this->awisBlockedPayload($task, [
            'schema_version' => 'atlas.engineering_runner.awis_gate.v1',
            'status' => 'blocked',
            'error' => 'awis_execution_gate_blocked',
            'workspace_resolution' => $resolution,
            'awis_execution_gate' => $gate,
        ]);
    }

    /**
     * @param  array<string,mixed>  $awis
     * @return array<string,mixed>
     */
    public function awisBlockedPayload(AtlasTask $task, array $awis): array
    {
        return [
            'run' => [
                'id' => null,
                'task_id' => $task->id,
                'status' => 'blocked',
                'decision' => 'blocked',
                'score' => 0,
                'blocking_reasons' => [$awis['error'] ?? 'awis_execution_gate_blocked'],
                'awis_execution_gate' => $awis,
            ],
            'score' => [
                'decision' => 'blocked',
                'score' => 0,
                'blocking_reasons' => [$awis['error'] ?? 'awis_execution_gate_blocked'],
            ],
            'awis_execution_gate' => $awis,
            'harnessability' => ['status' => 'skipped', 'reason' => 'awis_blocked_before_harness'],
            'test_run_count' => 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     * @param  array<string,mixed>  $scoring
     * @return array<string,mixed>
     */
    public function applyIsolatedPatch(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $workspacePlan,
        mixed $patch,
        array $scoring,
        bool $enabled,
    ): array {
        $control = [
            'slug' => 'isolated_patch_apply',
            'name' => 'Apply isolated patch to original workspace',
            'direction' => 'feedback',
            'execution_type' => 'computational',
            'regulation_category' => 'delivery_safety',
            'timing' => 'post_validation',
            'required' => false,
            'failure_policy' => 'blocks_resolved',
        ];

        if (! (bool) ($workspacePlan['isolated'] ?? false)) {
            return ['status' => 'not_applicable', 'reason' => 'workspace_not_isolated'];
        }

        if (! $enabled) {
            $result = ['status' => 'skipped', 'reason' => 'apply_isolated_patch_disabled'];
            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'skipped',
                summary: 'Aplicacao do patch isolado desabilitada.',
                metadata: ['required' => false],
            );

            return $result;
        }

        if (($scoring['decision'] ?? null) !== 'resolved') {
            $result = ['status' => 'skipped', 'reason' => 'preliminary_decision_not_resolved', 'decision' => $scoring['decision'] ?? null];
            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'skipped',
                summary: 'Patch isolado nao aplicado porque o run ainda nao esta resolved.',
                metadata: ['required' => false, 'decision' => $scoring['decision'] ?? null],
            );

            return $result;
        }

        $control['required'] = true;
        $result = $this->workspaces->applyPatchToOriginal($workspacePlan, $patch);
        $status = match ($result['status'] ?? null) {
            'applied' => 'passed',
            'blocked' => 'blocked',
            'failed' => 'failed',
            default => 'skipped',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: $control,
            status: $status,
            summary: match ($status) {
                'passed' => 'Patch isolado aplicado no workspace original.',
                'blocked' => 'Aplicacao do patch isolado bloqueada: '.(string) ($result['reason'] ?? 'blocked'),
                'failed' => 'Aplicacao do patch isolado falhou: '.(string) ($result['reason'] ?? 'failed'),
                default => 'Aplicacao do patch isolado nao executada: '.(string) ($result['reason'] ?? 'skipped'),
            },
            outputExcerpt: (string) ($result['stderr_excerpt'] ?? ''),
            metadata: array_merge($result, ['required' => true]),
        );

        return $result;
    }

    /**
     * @param  array<string,mixed>  $providerRun
     */
    public function recordProviderReviewFindings(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $providerRun,
    ): void {
        $completionStatus = (string) data_get($providerRun, 'decoded.completion.status', '');
        $severity = $completionStatus === 'failed' ? 'p1' : 'p2';
        $traceId = $attempt->trace_id ?: ($providerRun['trace_id'] ?? null);
        $risks = collect((array) data_get($providerRun, 'decoded.completion.completion_packet.risks', []))
            ->filter(fn (mixed $risk): bool => is_scalar($risk) && trim((string) $risk) !== '')
            ->map(fn (mixed $risk): string => trim((string) $risk))
            ->unique()
            ->values();

        foreach ($risks as $risk) {
            $this->reviewFindings->record($run, [
                'attempt_id' => $attempt->id,
                'source' => 'provider_quality_gate',
                'severity' => $severity,
                'title' => Str::limit($risk, 120, ''),
                'body' => $risk,
                'evidence' => [
                    'completion_status' => $completionStatus,
                    'trace_id' => $traceId,
                ],
            ]);
        }

        $failedTests = collect((array) data_get($providerRun, 'decoded.completion.completion_packet.tests', []))
            ->filter(fn (mixed $entry): bool => is_array($entry) && ! (bool) ($entry['ok'] ?? false))
            ->values();
        foreach ($failedTests as $entry) {
            $command = trim((string) ($entry['command'] ?? 'unknown command'));
            $this->reviewFindings->record($run, [
                'attempt_id' => $attempt->id,
                'source' => 'provider_quality_gate',
                'severity' => 'p1',
                'title' => 'Provider quality test failed: '.$command,
                'body' => trim((string) (($entry['stderr'] ?? null) ?: ($entry['stdout'] ?? null) ?: 'Provider quality gate reported a failed test.')),
                'evidence' => [
                    'completion_status' => $completionStatus,
                    'trace_id' => $traceId,
                    'test' => $entry,
                ],
            ]);
        }

        if ($completionStatus === 'failed' && $risks->isEmpty() && $failedTests->isEmpty()) {
            $this->reviewFindings->record($run, [
                'attempt_id' => $attempt->id,
                'source' => 'provider_quality_gate',
                'severity' => 'p1',
                'title' => 'Provider quality gate failed',
                'body' => 'The provider workflow returned a failed completion status without a structured risk item.',
                'evidence' => [
                    'trace_id' => $traceId,
                    'exit_code' => $providerRun['exit_code'] ?? null,
                ],
            ]);
        }
    }

    public function persistTaskSummary(AtlasTask $task, AtlasEngineeringRun $run, array $scoring): void
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $history = is_array($metadata['engineering_harness_run_history'] ?? null)
            ? $metadata['engineering_harness_run_history']
            : [];
        $summary = [
            'run_id' => $run->id,
            'status' => $run->status,
            'decision' => $run->decision,
            'score' => $run->score,
            'attempt_count' => $run->attempt_count,
            'context_pack_hash' => $run->context_pack_hash,
            'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
            'created_at' => $run->created_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];

        $metadata['latest_engineering_harness_run'] = $summary;
        $metadata['engineering_harness_run_history'] = array_slice([$summary, ...$history], 0, 20);
        $task->forceFill(['metadata' => $metadata])->save();
    }
}
