<?php

namespace App\Services\Engineering\EngineeringHarness;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Tools\AtlasToolGateService;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\Services\Engineering\EngineeringControlRegistryService;
use App\Services\Engineering\EngineeringProviderRuntimeService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReviewFindingService;
use App\Services\Engineering\EngineeringPatchArtifactService;
use App\Services\Engineering\EngineeringTaskContractService;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
use App\Services\Engineering\EngineeringHarnessabilityService;
use App\Services\Engineering\EngineeringModelPolicyService;
use App\Services\Engineering\EngineeringContextPackService;
use App\Services\Engineering\EngineeringDockerHarnessService;
use App\Services\Engineering\EngineeringTestMatrixService;
use App\Services\Engineering\EngineeringRunScoringService;
use App\Services\Engineering\EngineeringHarnessRunnerInput;

class HarnessControlsSection
{
    public function __construct(
        private readonly HarnessRunnerSupport $support,
        private readonly EngineeringControlRegistryService $controlRegistry,
        private readonly AtlasToolGateService $toolGate,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $policy
     */
    public function recordAutonomyPolicyControl(AtlasEngineeringRun $run, array $policy): void
    {
        $actions = (array) ($policy['actions'] ?? []);
        $status = $actions === [] ? 'passed' : 'warning';

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'harnessability_autonomy_policy',
                'name' => 'Harnessability autonomy policy',
                'direction' => 'feedforward',
                'execution_type' => 'mixed',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => false,
                'failure_policy' => 'advisory',
            ],
            status: $status,
            summary: $actions === []
                ? 'Autonomia mantida pelo score de harnessability.'
                : 'Autonomia ajustada pelo score de harnessability: '.implode(', ', array_map('strval', $actions)),
            metadata: [
                'required' => false,
                'policy' => $policy,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $selection
     */
    public function recordModelSelectionControl(AtlasEngineeringRun $run, array $selection): void
    {
        $statusValue = (string) ($selection['status'] ?? 'skipped');
        $selectedModel = is_string($selection['selected_model'] ?? null) && trim((string) $selection['selected_model']) !== ''
            ? trim((string) $selection['selected_model'])
            : null;
        $summary = $selectedModel
            ? 'Modelo selecionado pelo Harness: '.$selectedModel.' via '.(string) ($selection['source'] ?? 'unknown').'.'
            : 'Selecao automatica de modelo nao aplicada: '.(string) ($selection['reason'] ?? 'fixed_default');

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'model_selection_policy',
                'name' => 'Model selection policy',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_quality',
                'timing' => 'prepare',
                'required' => false,
                'failure_policy' => 'advisory',
            ],
            status: $statusValue === 'selected' ? 'passed' : 'skipped',
            summary: $summary,
            metadata: [
                'required' => false,
                'selection' => $selection,
            ],
        );
    }

    /**
     * @param  array<string,mixed>|null  $replay
     */
    public function recordReplayControl(AtlasEngineeringRun $run, ?array $replay): void
    {
        if (! $replay) {
            return;
        }

        $providerReplay = (bool) ($replay['provider_replay'] ?? false);
        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'harness_replay_contract',
                'name' => 'Harness replay contract',
                'direction' => 'feedforward',
                'execution_type' => 'mixed',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => true,
                'failure_policy' => 'blocks_resolved',
            ],
            status: 'passed',
            summary: $providerReplay
                ? 'Replay controlado vai reexecutar provider em sandbox auditavel.'
                : 'Replay controlado em modo sensores, sem reexecutar provider.',
            metadata: array_merge($replay, ['required' => true]),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     */
    public function recordPrepareControls(AtlasEngineeringRun $run, array $controls, array $contract, array $blueprint): void
    {
        foreach ($controls as $control) {
            if (($control['timing'] ?? null) !== 'prepare') {
                continue;
            }

            $slug = (string) ($control['slug'] ?? '');
            $passed = match ($slug) {
                'engineering_task_contract' => trim((string) ($contract['goal'] ?? '')) !== '',
                'engineering_blueprint_snapshot' => trim((string) ($blueprint['blueprint_id'] ?? '')) !== '',
                default => true,
            };

            $this->controlRegistry->recordResult(
                run: $run,
                attempt: null,
                control: $control,
                status: $passed ? 'passed' : 'failed',
                summary: $passed ? 'Guide preparado: '.$slug : 'Guide incompleto: '.$slug,
                metadata: ['required' => (bool) ($control['required'] ?? false)],
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     */
    public function recordPostAttemptControls(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $controls,
        mixed $patch,
    ): void {
        foreach ($controls as $control) {
            $slug = (string) ($control['slug'] ?? '');
            if (! in_array($slug, ['git_status_snapshot', 'patch_artifact'], true)) {
                continue;
            }

            $dirtyCount = count((array) ($patch?->changed_files_json ?? []));
            $summary = $slug === 'git_status_snapshot'
                ? "Git status capturado com {$dirtyCount} arquivo(s) alterado(s)."
                : ($patch?->diff_hash ? 'Patch artifact capturado.' : 'Patch artifact capturado sem diff rastreavel.');

            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'passed',
                summary: $summary,
                outputExcerpt: $patch?->diff_excerpt,
                metadata: [
                    'required' => (bool) ($control['required'] ?? false),
                    'dirty_count' => $dirtyCount,
                    'diff_hash' => $patch?->diff_hash,
                ],
            );
        }
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     */
    public function recordChangedFilesScopeControl(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $contract,
        array $blueprint,
        mixed $patch,
    ): void {
        $changedFiles = $this->normalizedFileList((array) ($patch?->changed_files_json ?? []));
        $scope = $this->changedFilesScope($contract, $blueprint);
        $declaredScope = $scope['entries'];
        $strict = (bool) $scope['strict'];
        $outsideScope = $declaredScope === []
            ? []
            : collect($changedFiles)
                ->reject(fn (string $file): bool => $this->pathMatchesScope($file, $declaredScope))
                ->values()
                ->all();

        $required = $strict && $declaredScope !== [];
        $status = match (true) {
            $changedFiles === [] => 'passed',
            $declaredScope === [] => 'warning',
            $outsideScope === [] => 'passed',
            $strict => 'failed',
            default => 'warning',
        };
        $summary = match ($status) {
            'passed' => $changedFiles === []
                ? 'Nenhum arquivo alterado para validar contra o escopo.'
                : 'Arquivos alterados respeitam o escopo declarado.',
            'failed' => 'Diff alterou arquivo(s) fora do escopo estrito declarado.',
            default => $declaredScope === []
                ? 'Diff possui arquivos alterados, mas o contrato nao declarou escopo de arquivos.'
                : 'Diff alterou arquivo(s) fora da lista provavel; revisao de escopo recomendada.',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: [
                'slug' => 'changed_files_scope_policy',
                'name' => 'Changed files scope policy',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'post_attempt',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $status,
            summary: $summary,
            metadata: [
                'required' => $required,
                'strict' => $strict,
                'scope_source' => $scope['source'],
                'declared_scope' => $declaredScope,
                'changed_files' => $changedFiles,
                'outside_scope' => $outsideScope,
                'outside_scope_count' => count($outsideScope),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array{entries:array<int,string>,strict:bool,source:string}
     */
    public function changedFilesScope(array $contract, array $blueprint): array
    {
        $fileScope = is_array($contract['file_scope'] ?? null) ? $contract['file_scope'] : [];
        $scopePolicy = is_array($contract['scope_policy'] ?? null) ? $contract['scope_policy'] : [];
        $blueprintScope = is_array($blueprint['file_scope'] ?? null) ? $blueprint['file_scope'] : [];
        $explicitScope = $this->normalizedFileList(array_merge(
            (array) ($contract['allowed_files'] ?? []),
            (array) ($contract['allowed_paths'] ?? []),
            (array) ($fileScope['allowed_files'] ?? []),
            (array) ($fileScope['allowed_paths'] ?? []),
            (array) ($scopePolicy['allowed_files'] ?? []),
            (array) ($scopePolicy['allowed_paths'] ?? []),
            (array) ($blueprintScope['allowed_files'] ?? []),
            (array) ($blueprintScope['allowed_paths'] ?? []),
        ));
        $likelyScope = $this->normalizedFileList(array_merge(
            (array) ($contract['likely_files'] ?? []),
            (array) ($contract['files'] ?? []),
            (array) ($contract['target_files'] ?? []),
            (array) ($fileScope['likely_files'] ?? []),
            (array) ($scopePolicy['likely_files'] ?? []),
            (array) ($blueprintScope['likely_files'] ?? []),
        ));
        $strict = $explicitScope !== []
            || (bool) ($contract['strict_file_scope'] ?? false)
            || (bool) ($fileScope['strict'] ?? false)
            || in_array((string) ($fileScope['mode'] ?? ''), ['strict', 'allowlist'], true)
            || in_array((string) ($scopePolicy['mode'] ?? ''), ['strict', 'allowlist'], true)
            || in_array((string) ($scopePolicy['changed_files'] ?? ''), ['strict', 'allowlist'], true);

        if ($explicitScope !== []) {
            return ['entries' => $explicitScope, 'strict' => true, 'source' => 'explicit_allowed_scope'];
        }

        if ($likelyScope !== []) {
            return ['entries' => $likelyScope, 'strict' => $strict, 'source' => 'likely_files'];
        }

        return ['entries' => [], 'strict' => false, 'source' => 'none'];
    }

    /**
     * @param  array<int,mixed>  $files
     * @return array<int,string>
     */
    public function normalizedFileList(array $files): array
    {
        return collect($files)
            ->filter(fn (mixed $file): bool => is_scalar($file) && trim((string) $file) !== '')
            ->map(function (mixed $file): string {
                $path = str_replace('\\', '/', trim((string) $file));
                $path = preg_replace('#/+#', '/', $path) ?: $path;
                $path = preg_replace('#^\./#', '', $path) ?: $path;

                return ltrim(trim($path), '/');
            })
            ->filter(fn (string $file): bool => $file !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $scope
     */
    public function pathMatchesScope(string $file, array $scope): bool
    {
        $file = $this->normalizedFileList([$file])[0] ?? '';
        if ($file === '') {
            return false;
        }

        foreach ($scope as $entry) {
            $entry = $this->normalizedFileList([$entry])[0] ?? '';
            if ($entry === '') {
                continue;
            }

            if ($entry === $file) {
                return true;
            }

            if (str_ends_with($entry, '/*')) {
                $entry = substr($entry, 0, -1);
            }

            if (str_ends_with($entry, '/') && str_starts_with($file, $entry)) {
                return true;
            }

            if (str_contains($entry, '*') && fnmatch($entry, $file, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     */
    public function recordWorkspacePlanControl(AtlasEngineeringRun $run, array $workspacePlan): void
    {
        $requested = (string) ($workspacePlan['requested_mode'] ?? $workspacePlan['mode'] ?? 'workspace');
        if (! in_array($requested, ['worktree', 'docker'], true)) {
            return;
        }

        $dockerRequested = $requested === 'docker';
        $ready = $dockerRequested
            ? (bool) ($workspacePlan['containerized_execution'] ?? false)
            : (bool) ($workspacePlan['isolated'] ?? false);
        $status = $ready ? 'passed' : ($dockerRequested ? 'failed' : 'warning');
        $reason = (string) ($workspacePlan['fallback_reason'] ?? 'workspace_plan_not_ready');

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => $dockerRequested ? 'docker_harness_profile' : 'workspace_isolation',
                'name' => $dockerRequested ? 'Docker harness profile' : 'Workspace isolation',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => $dockerRequested,
                'failure_policy' => $dockerRequested ? 'blocks_resolved' : 'advisory',
            ],
            status: $status,
            summary: $ready
                ? ($dockerRequested ? 'Docker harness pronto para testes containerizados.' : 'Workspace isolado preparado.')
                : ($dockerRequested ? 'Docker harness indisponivel: '.$reason : 'Workspace isolado indisponivel: '.$reason),
            metadata: [
                'required' => $dockerRequested,
                'requested_mode' => $requested,
                'mode' => $workspacePlan['mode'] ?? null,
                'status' => $workspacePlan['status'] ?? null,
                'fallback_reason' => $workspacePlan['fallback_reason'] ?? null,
                'isolation_type' => $workspacePlan['isolation_type'] ?? null,
                'containerized_execution' => (bool) ($workspacePlan['containerized_execution'] ?? false),
                'docker' => $this->support->compactDockerPlan((array) ($workspacePlan['docker'] ?? [])),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $providerRuntimePlan
     */
    public function recordProviderRuntimeControl(AtlasEngineeringRun $run, array $providerRuntimePlan): void
    {
        $requested = (string) ($providerRuntimePlan['requested_runtime'] ?? 'host');
        $runtime = (string) ($providerRuntimePlan['runtime'] ?? 'host');
        $statusValue = (string) ($providerRuntimePlan['status'] ?? 'ready');
        if ($requested === 'host' && $runtime === 'host') {
            return;
        }
        if ($statusValue === 'skipped') {
            return;
        }

        $required = (bool) ($providerRuntimePlan['required'] ?? false);
        $ready = $runtime === 'docker' && $statusValue === 'ready';
        $status = $ready ? 'passed' : ($required ? 'failed' : 'warning');
        $reason = (string) ($providerRuntimePlan['fallback_reason'] ?? 'provider_runtime_not_ready');

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'provider_runtime_isolation',
                'name' => 'Provider runtime isolation',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $status,
            summary: $ready
                ? 'Provider runtime Docker pronto para executar atlas:cli:dev.'
                : 'Provider runtime Docker indisponivel: '.$reason,
            metadata: array_merge($this->support->compactProviderRuntimePlan($providerRuntimePlan), [
                'required' => $required,
            ]),
        );
    }

    /**
     * @param  array<string,mixed>  $networkPolicy
     * @param  array<string,mixed>  $workspacePlan
     */
    public function recordDockerNetworkControl(AtlasEngineeringRun $run, array $networkPolicy, array $workspacePlan): void
    {
        $statusValue = (string) ($networkPolicy['status'] ?? 'not_applicable');
        if ($statusValue === 'not_applicable') {
            return;
        }

        $mode = (string) ($networkPolicy['mode'] ?? 'profile');
        if ($mode === 'profile') {
            return;
        }

        $required = ($workspacePlan['mode'] ?? null) === 'docker' && (bool) ($networkPolicy['required'] ?? false);
        $passed = $statusValue === 'passed';

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'docker_network_policy',
                'name' => 'Docker network policy',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'security_privacy',
                'timing' => 'prepare',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $passed ? 'passed' : ($required ? 'failed' : 'warning'),
            summary: $passed
                ? 'Politica de rede Docker aplicada: '.$mode
                : 'Politica de rede Docker nao aplicavel: '.(string) ($networkPolicy['reason'] ?? 'network_policy_not_enforced'),
            metadata: array_merge($this->support->compactDockerNetworkPolicy($networkPolicy), [
                'required' => $required,
            ]),
        );
    }

    /**
     * @param  array<string,mixed>  $healthchecks
     * @param  array<string,mixed>  $workspacePlan
     */
    public function recordDockerHealthcheckControl(AtlasEngineeringRun $run, array $healthchecks, array $workspacePlan): void
    {
        $statusValue = (string) ($healthchecks['status'] ?? 'skipped');
        if (in_array($statusValue, ['not_applicable', 'skipped'], true)) {
            return;
        }

        $required = ($workspacePlan['mode'] ?? null) === 'docker';
        $passed = $statusValue === 'passed';

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'docker_service_healthchecks',
                'name' => 'Docker service healthchecks',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'pre_validation',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $passed ? 'passed' : ($required ? 'failed' : 'warning'),
            summary: $passed
                ? 'Servicos Docker dependentes estao prontos antes dos testes.'
                : 'Servicos Docker dependentes nao ficaram prontos: '.(string) ($healthchecks['reason'] ?? 'healthcheck_failed'),
            outputExcerpt: (string) ($healthchecks['stderr_excerpt'] ?? ''),
            metadata: array_merge($this->support->compactDockerHealthchecks($healthchecks), [
                'required' => $required,
            ]),
        );
    }

    /**
     * @param  array{quality_scan?:string,quality_profile?:string}  $options
     */
    public function recordToolRuntimeGateControl(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        string $workspace,
        array $options,
    ): void {
        $qualityScanMode = $this->support->qualityScanMode($options['quality_scan'] ?? 'off');
        if ($qualityScanMode === 'off') {
            return;
        }

        $qualityProfile = $this->support->qualityScanProfile($options['quality_profile'] ?? 'auto');
        $required = $qualityScanMode === 'required' || in_array($qualityProfile, ['release', 'deep'], true);
        $filters = [
            'workspace' => $workspace,
            'surface' => 'engineering_quality_scan',
            'run_context_type' => 'engineering_run',
            'run_context_id' => $run->id,
            'limit' => 100,
        ];
        $gate = $this->toolGate->evaluate($filters, [
            'require_evidence' => $required,
        ]);
        $gateStatus = (string) ($gate['status'] ?? 'warning');
        $controlStatus = match ($gateStatus) {
            'passed' => 'passed',
            'warning' => 'passed',
            'blocked' => $required ? 'failed' : 'warning',
            default => $required ? 'failed' : 'warning',
        };
        $summary = match ($gateStatus) {
            'passed' => 'Atlas Tool Runtime gate passou para evidencias do quality scan deste run.',
            'blocked' => 'Atlas Tool Runtime gate bloqueou evidencias do quality scan deste run.',
            default => 'Atlas Tool Runtime gate registrou avisos para evidencias do quality scan deste run.',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: [
                'slug' => 'atlas_tool_runtime_gate',
                'name' => 'Atlas Tool Runtime gate',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_quality',
                'timing' => 'post_attempt',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $controlStatus,
            summary: $summary,
            outputExcerpt: ($gate['blocking_failures'] ?? []) || ($gate['warnings'] ?? [])
                ? json_encode([
                    'blocking_failures' => $gate['blocking_failures'] ?? [],
                    'warnings' => $gate['warnings'] ?? [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            metadata: [
                'required' => $required,
                'quality_scan_mode' => $qualityScanMode,
                'quality_profile' => $qualityProfile,
                'tool_runtime_gate' => true,
                'gate' => $gate,
            ],
        );
    }

    public function recordVisualToolRuntimeGateControl(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        string $workspace,
        string $visualE2eMode,
        mixed $testRuns,
    ): void {
        $hasVisualRun = collect($testRuns)->contains(fn (mixed $testRun): bool => (bool) data_get($testRun, 'metadata.visual_e2e.managed_by_atlas', false));
        $required = $hasVisualRun && (
            $visualE2eMode === 'required'
            || collect($testRuns)->contains(fn (mixed $testRun): bool => (bool) data_get($testRun, 'metadata.visual_e2e.required', false))
        );

        if (! $hasVisualRun && ! $required) {
            return;
        }

        $filters = [
            'workspace' => $workspace,
            'surface' => 'engineering_visual_smoke',
            'run_context_type' => 'engineering_run',
            'run_context_id' => $run->id,
            'limit' => 50,
        ];
        $gate = $this->toolGate->evaluate($filters, [
            'require_evidence' => $required,
        ]);
        $gateStatus = (string) ($gate['status'] ?? 'warning');
        $controlStatus = match ($gateStatus) {
            'passed' => 'passed',
            'warning' => 'passed',
            'blocked' => $required ? 'failed' : 'warning',
            default => $required ? 'failed' : 'warning',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: [
                'slug' => 'atlas_tool_runtime_visual_gate',
                'name' => 'Atlas Tool Runtime visual gate',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'behaviour',
                'timing' => 'post_attempt',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $controlStatus,
            summary: $gateStatus === 'blocked'
                ? 'Atlas Tool Runtime visual gate bloqueou evidencias visuais deste run.'
                : 'Atlas Tool Runtime visual gate validou evidencias visuais deste run.',
            outputExcerpt: ($gate['blocking_failures'] ?? []) || ($gate['warnings'] ?? [])
                ? json_encode([
                    'blocking_failures' => $gate['blocking_failures'] ?? [],
                    'warnings' => $gate['warnings'] ?? [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            metadata: [
                'required' => $required,
                'visual_e2e_mode' => $visualE2eMode,
                'tool_runtime_gate' => true,
                'gate' => $gate,
            ],
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     */
    public function recordSkippedRequiredControls(AtlasEngineeringRun $run, AtlasEngineeringRunAttempt $attempt, array $controls): void
    {
        $recorded = AtlasEngineeringControlResult::query()
            ->where('engineering_run_id', $run->id)
            ->pluck('control_slug')
            ->all();

        foreach ($controls as $control) {
            $slug = (string) ($control['slug'] ?? '');
            if (! (bool) ($control['required'] ?? false) || in_array($slug, $recorded, true)) {
                continue;
            }

            if (($control['direction'] ?? null) !== 'feedback') {
                continue;
            }

            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'skipped',
                summary: 'Controle requerido nao foi executado neste run: '.$slug,
                metadata: ['required' => true],
            );
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordHarnessLedgerEvent(LedgerEventType $type, AtlasEngineeringRun $run, array $payload = []): void
    {
        $envelopeId = 'engineering_run:'.$run->id;

        try {
            $this->ledger->record($type, array_merge([
                'envelope_id' => $envelopeId,
                'engineering_run_id' => $run->id,
                'task_id' => $run->task_id,
                'project_id' => $run->project_id,
                'project_step_id' => $run->project_step_id,
                'trace_id' => $run->trace_id,
                'status' => $run->status,
                'decision' => $run->decision,
                'score' => $run->score,
                'workspace_path_hash' => $run->workspace_path_hash,
                'workspace_label_hash' => $run->workspace_label ? hash('sha256', $run->workspace_label) : null,
                'provider' => data_get($run->provider_strategy_json, 'provider'),
                'model' => data_get($run->provider_strategy_json, 'model'),
            ], $payload), [
                'tenant_id' => (string) data_get($run->metadata, 'tenant_id', 'default'),
                'operator_id' => (string) data_get($run->metadata, 'operator_id', 'system'),
                'envelope_id' => $envelopeId,
                'trace_id' => $this->support->uuidOrNull($run->trace_id),
                'correlation_id' => $envelopeId,
                'emitter_stage' => 'engineering.harness',
                'emitter_version' => 'engineering-harness-v1',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
