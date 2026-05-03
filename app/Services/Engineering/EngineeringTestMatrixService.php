<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringControl;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasEngineeringTestCase;
use App\Models\AtlasEngineeringTestRun;
use App\Models\AtlasTask;
use App\Models\AtlasToolRun;
use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\WorkspaceProfile;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Services\Tools\AtlasToolEvidenceStore;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EngineeringTestMatrixService
{
    public function __construct(
        private readonly WorkspaceProfiler $profiler,
        private readonly AiToolRuntime $runtime,
        private readonly EngineeringControlRegistryService $controls,
        private readonly EngineeringDockerHarnessService $dockerHarness,
        private readonly AtlasToolEvidenceStore $toolEvidence,
    ) {}

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<int,array<string,mixed>>  $controlDefinitions
     * @return Collection<int,AtlasEngineeringTestCase>
     */
    public function ensureCases(
        AtlasTask $task,
        array $blueprint,
        string $workspace,
        array $controlDefinitions,
        ?string $explicitTestCommand = null,
        bool $autoTest = false,
        array $options = [],
    ): Collection {
        if (! Schema::hasTable('atlas_engineering_test_cases')) {
            return collect();
        }

        $workspace = realpath($workspace) ?: $workspace;
        $profile = $this->profiler->profile($workspace);
        $commands = collect();

        foreach ($controlDefinitions as $control) {
            $command = $control['command'] ?? null;
            $controlSlug = (string) ($control['slug'] ?? '');
            if (
                ($control['direction'] ?? null) === 'feedback'
                && is_string($command)
                && $command !== ''
                && ! in_array($controlSlug, ['git_status_snapshot', 'patch_artifact'], true)
            ) {
                $commands->push([
                    'command' => $command,
                    'source' => 'control',
                    'required' => (bool) ($control['required'] ?? false),
                    'control_slug' => $controlSlug,
                ]);
            }
        }

        if ($explicitTestCommand && ! $commands->contains(fn (array $entry): bool => ($entry['command'] ?? null) === $explicitTestCommand)) {
            $commands->push(['command' => $explicitTestCommand, 'source' => 'operator', 'required' => true]);
        }

        if ($autoTest && $commands->isEmpty()) {
            foreach ($profile->testCommands as $command) {
                $commands->push(['command' => $command, 'source' => 'detected', 'required' => true]);
            }
        }

        if ($autoTest) {
            foreach ($this->visualE2eCommands($workspace, $profile, $blueprint, $options) as $entry) {
                $commands->push($entry);
            }

            foreach ($this->qualityScanCommands($workspace, $profile, $blueprint, $options) as $entry) {
                $commands->push($entry);
            }
        }

        return $commands
            ->filter(fn (array $entry): bool => trim((string) ($entry['command'] ?? '')) !== '')
            ->unique(fn (array $entry): string => (string) $entry['command'])
            ->values()
            ->map(function (array $entry) use ($task, $blueprint): AtlasEngineeringTestCase {
                $command = (string) $entry['command'];
                $control = $this->controlForSlug($entry['control_slug'] ?? null);
                $type = $this->typeForCommand($command);
                $caseCode = $this->caseCode($type, $command);

                return AtlasEngineeringTestCase::query()->updateOrCreate(
                    [
                        'task_id' => $task->id,
                        'case_code' => $caseCode,
                    ],
                    [
                        'blueprint_id' => $blueprint['blueprint_id'] ?? null,
                        'control_id' => $control?->id,
                        'source' => (string) ($entry['source'] ?? 'detected'),
                        'type' => $type,
                        'priority' => (bool) ($entry['required'] ?? false) ? 'p0' : 'p2',
                        'command' => $command,
                        'expected_signal' => 'exit_code_0',
                        'timeout_seconds' => 900,
                        'required' => (bool) ($entry['required'] ?? false),
                        'metadata' => array_filter([
                            'blueprint_id' => $blueprint['blueprint_id'] ?? null,
                            'verification_methods' => collect((array) ($blueprint['acceptance_matrix'] ?? []))
                                ->pluck('verification_method')
                                ->unique()
                                ->values()
                                ->all(),
                            'visual_e2e' => $entry['visual_e2e'] ?? null,
                            'quality_scan' => $entry['quality_scan'] ?? null,
                            'atlas_managed' => $entry['atlas_managed'] ?? null,
                        ], fn (mixed $value): bool => $value !== null),
                    ],
                );
            });
    }

    /**
     * @param  Collection<int,AtlasEngineeringTestCase>  $cases
     * @param  array<string,mixed>  $options
     * @return Collection<int,AtlasEngineeringTestRun>
     */
    public function runCases(
        AtlasEngineeringRun $run,
        ?AtlasEngineeringRunAttempt $attempt,
        string $workspace,
        Collection $cases,
        bool $approved = true,
        array $options = [],
    ): Collection {
        if (! Schema::hasTable('atlas_engineering_test_runs')) {
            return collect();
        }

        $workspace = realpath($workspace) ?: $workspace;

        return $cases
            ->filter(fn (AtlasEngineeringTestCase $case): bool => is_string($case->command) && trim($case->command) !== '')
            ->map(function (AtlasEngineeringTestCase $case) use ($run, $attempt, $workspace, $approved, $options): AtlasEngineeringTestRun {
                $workspacePlan = (array) ($options['workspace_plan'] ?? []);
                $caseMetadata = is_array($case->metadata) ? $case->metadata : [];
                $atlasManagedSensor = (bool) ($caseMetadata['atlas_managed'] ?? false);
                $caseCommand = $this->commandWithRunContext((string) $case->command, $caseMetadata, $run);
                $runtimeCommand = $atlasManagedSensor
                    ? [
                        'command' => $caseCommand,
                        'runtime' => [
                            'type' => 'host',
                            'reason' => 'atlas_managed_sensor',
                        ],
                    ]
                    : $this->dockerHarness->testRuntimeCommand($caseCommand, $workspace, $workspacePlan);
                $result = $this->runtime->execute(ToolInvocation::make('test.run', $workspace, [
                    'command' => $runtimeCommand['command'],
                    'timeout' => $case->timeout_seconds,
                ], [
                    'permission_mode' => 'write',
                    'metadata' => [
                        'approved' => $approved,
                        'approval_source' => 'atlas_engineering_runner',
                        'runtime' => $runtimeCommand['runtime'],
                    ],
                ]));
                $qualityScanResult = $this->compactQualityScanOutput($result->stdout, $caseMetadata);
                $stdoutExcerpt = $qualityScanResult !== null
                    ? json_encode($qualityScanResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $result->stdout;

                $testRun = AtlasEngineeringTestRun::query()->create([
                    'engineering_run_id' => $run->id,
                    'attempt_id' => $attempt?->id,
                    'test_case_id' => $case->id,
                    'command' => $case->command,
                    'exit_code' => $result->exitCode,
                    'status' => $result->ok ? 'passed' : 'failed',
                    'duration_ms' => $result->durationMs,
                    'stdout_excerpt' => $stdoutExcerpt !== '' ? Str::limit(AtlasSecurity::redactString((string) $stdoutExcerpt), 4000, "\n...[truncated]") : null,
                    'stderr_excerpt' => $result->stderr !== '' ? Str::limit(AtlasSecurity::redactString($result->stderr), 4000, "\n...[truncated]") : null,
                    'metadata' => [
                        'summary' => $result->summary,
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                        'runtime' => $runtimeCommand['runtime'],
                        'runtime_command' => $runtimeCommand['command'] === $case->command
                            ? null
                            : AtlasSecurity::redactString($runtimeCommand['command']),
                        'test_case_type' => $case->type,
                        'test_case_source' => $case->source,
                        'visual_e2e' => $caseMetadata['visual_e2e'] ?? null,
                        'quality_scan' => $caseMetadata['quality_scan'] ?? null,
                        'quality_scan_result' => $qualityScanResult,
                        'atlas_managed' => $atlasManagedSensor,
                    ],
                ]);
                $artifactExport = $this->dockerHarness->captureArtifacts($run, $testRun->refresh(), $workspace, $workspacePlan);
                $visualArtifactExport = $this->captureVisualArtifacts($run, $testRun->refresh(), $workspace, $caseMetadata);
                $this->recordVisualSmokeToolEvidence($run, $workspace, $visualArtifactExport, $caseMetadata);
                $qualityArtifactExport = $this->captureQualityScanArtifacts($run, $testRun->refresh(), $result->stdout, $caseMetadata);
                $this->recordQualityScanToolEvidence($run, $workspace, $qualityScanResult);

                $this->controls->recordResult(
                    run: $run,
                    attempt: $attempt,
                    control: $case->control ?: [
                        'slug' => 'test_case_'.$case->case_code,
                    ],
                    status: $result->ok ? 'passed' : 'failed',
                    summary: $result->ok
                        ? 'Teste passou: '.$case->command
                        : 'Teste falhou: '.$case->command,
                    outputExcerpt: $result->errorMessage ?: trim($result->stdout."\n".$result->stderr),
                    durationMs: $result->durationMs,
                    metadata: [
                        'test_case_id' => $case->id,
                        'command' => $case->command,
                        'runtime' => $runtimeCommand['runtime'],
                        'artifact_export_status' => $artifactExport['status'] ?? 'not_applicable',
                        'visual_artifact_export_status' => $visualArtifactExport['status'] ?? 'not_applicable',
                        'quality_artifact_export_status' => $qualityArtifactExport['status'] ?? 'not_applicable',
                        'quality_scan_status' => data_get($qualityScanResult, 'status'),
                        'required' => $case->required,
                    ],
                );

                return $testRun->refresh();
            })
            ->values();
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,mixed>>
     */
    private function visualE2eCommands(string $workspace, WorkspaceProfile $profile, array $blueprint, array $options): array
    {
        $mode = $this->visualE2eMode($options['visual_e2e'] ?? config('atlas.engineering.visual_e2e.mode', 'auto'));
        if ($mode === 'off') {
            return [];
        }

        $blueprintNeedsVisual = $this->blueprintNeedsVisualEvidence($blueprint);
        $profileHasVisualSignals = $this->profileHasVisualE2eSignals($profile);
        if ($mode === 'auto' && ! $blueprintNeedsVisual && ! $profileHasVisualSignals) {
            return [];
        }

        $commands = $this->detectedVisualE2eCommands($workspace, $profile);
        if ($commands === []) {
            $managed = $this->managedVisualSmokeCommand($workspace, $profile, $mode, $blueprintNeedsVisual);
            if ($managed !== null) {
                $commands[] = $managed;
            }
        }

        if ($commands === []) {
            return [];
        }

        $required = $mode === 'required' || $blueprintNeedsVisual;

        return collect($commands)
            ->map(fn (array $command): array => [
                'command' => $command['command'],
                'source' => 'detected_visual_e2e',
                'required' => $required,
                'control_slug' => $blueprintNeedsVisual ? 'manual_behaviour_evidence' : null,
                'visual_e2e' => [
                    'mode' => $mode,
                    'required' => $required,
                    'detected_by' => $command['detected_by'],
                    'artifact_paths' => $this->visualArtifactPaths(),
                    'satisfies_control_slug' => $blueprintNeedsVisual ? 'manual_behaviour_evidence' : null,
                    'managed_by_atlas' => (bool) ($command['managed_by_atlas'] ?? false),
                ],
                'atlas_managed' => (bool) ($command['managed_by_atlas'] ?? false),
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,mixed>>
     */
    private function qualityScanCommands(string $workspace, WorkspaceProfile $profile, array $blueprint, array $options): array
    {
        $mode = $this->qualityScanMode($options['quality_scan'] ?? config('atlas.engineering.quality_scan.mode', 'off'));
        if ($mode === 'off') {
            return [];
        }

        if ($mode === 'auto' && ! $this->shouldRunAutoQualityScan($workspace, $profile, $blueprint)) {
            return [];
        }

        $profileName = $this->qualityScanProfile($options['quality_profile'] ?? config('atlas.engineering.quality_scan.profile', 'auto'));
        $changedOnly = array_key_exists('quality_changed_only', $options)
            ? (bool) $options['quality_changed_only']
            : (bool) config('atlas.engineering.quality_scan.changed_only', true);
        $timeout = max(10, (int) config('atlas.engineering.quality_scan.timeout_seconds', 300));
        $required = $mode === 'required' || $profileName === 'release' || $profileName === 'deep';

        return [[
            'command' => $this->qualityScanCommand($workspace, $profileName, $changedOnly, $timeout),
            'source' => 'atlas_quality_scan',
            'required' => $required,
            'atlas_managed' => true,
            'quality_scan' => [
                'mode' => $mode,
                'profile' => $profileName,
                'changed_only' => $changedOnly,
                'timeout_seconds' => $timeout,
                'required' => $required,
                'detected_by' => 'atlas_managed_quality_scan',
            ],
        ]];
    }

    /**
     * @return array{command:string,detected_by:string,managed_by_atlas:bool}|null
     */
    private function managedVisualSmokeCommand(string $workspace, WorkspaceProfile $profile, string $mode, bool $blueprintNeedsVisual): ?array
    {
        if (! (bool) config('atlas.engineering.visual_e2e.managed_smoke_enabled', true)) {
            return null;
        }

        if ($mode === 'auto' && ! $blueprintNeedsVisual) {
            return null;
        }

        if (! $this->canStartManagedVisualSmoke($workspace, $profile)) {
            return null;
        }

        $timeout = max(5, (int) config('atlas.engineering.visual_e2e.managed_smoke_timeout_seconds', 45));
        $baseline = (string) config('atlas.engineering.visual_e2e.baseline_mode', 'observe');
        $screenshotDriver = (string) config('atlas.engineering.visual_e2e.screenshot_driver', 'auto');
        $command = implode(' ', array_filter([
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            'atlas:engineering:visual-smoke',
            '--workspace='.escapeshellarg($workspace),
            '--artifact-dir='.escapeshellarg('atlas-visual-report'),
            '--timeout='.escapeshellarg((string) $timeout),
            '--baseline='.escapeshellarg($baseline),
            '--screenshot-driver='.escapeshellarg($screenshotDriver),
            '--json',
        ]));

        return [
            'command' => $command,
            'detected_by' => 'atlas_managed_visual_smoke',
            'managed_by_atlas' => true,
        ];
    }

    private function qualityScanMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'off';

        return in_array($mode, ['auto', 'off', 'required'], true) ? $mode : 'off';
    }

    private function qualityScanProfile(mixed $value): string
    {
        $profile = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($profile, ['auto', 'fast', 'standard', 'release', 'deep'], true) ? $profile : 'auto';
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    private function shouldRunAutoQualityScan(string $workspace, WorkspaceProfile $profile, array $blueprint): bool
    {
        $signals = strtolower(implode(' ', array_merge(
            array_keys($profile->scripts),
            array_values($profile->scripts),
            $profile->files,
            (array) ($blueprint['tags'] ?? []),
            collect((array) data_get($blueprint, 'risk_register.open_risks', []))->map(fn (mixed $risk): string => (string) $risk)->all(),
            collect((array) ($blueprint['acceptance_matrix'] ?? []))->map(fn (mixed $entry): string => is_array($entry) ? (string) ($entry['verification_method'] ?? '') : '')->all(),
        )));

        return File::isFile($workspace.'/composer.json')
            || File::isFile($workspace.'/package.json')
            || File::isFile($workspace.'/Dockerfile')
            || str_contains($signals, 'security')
            || str_contains($signals, 'privacy')
            || str_contains($signals, 'lint')
            || str_contains($signals, 'typecheck')
            || str_contains($signals, 'quality')
            || str_contains($signals, 'static');
    }

    private function qualityScanCommand(string $workspace, string $profile, bool $changedOnly, int $timeout): string
    {
        return implode(' ', array_filter([
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            'atlas:engineering:quality-scan',
            '--workspace='.escapeshellarg($workspace),
            '--profile='.escapeshellarg($profile),
            '--timeout='.escapeshellarg((string) $timeout),
            $changedOnly ? '--changed-only' : null,
            '--json',
        ], fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * Attach the Engineering run id only at execution time so persisted test case
     * definitions remain stable and reusable across runs.
     *
     * @param  array<string,mixed>  $caseMetadata
     */
    private function commandWithRunContext(string $command, array $caseMetadata, AtlasEngineeringRun $run): string
    {
        $isContextAwareSensor = is_array($caseMetadata['quality_scan'] ?? null)
            || is_array($caseMetadata['visual_e2e'] ?? null);
        if (! $isContextAwareSensor) {
            return $command;
        }

        if (! str_contains($command, 'atlas:engineering:quality-scan') && ! str_contains($command, 'atlas:engineering:visual-smoke')) {
            return $command;
        }

        return $command
            .' --run-context-type='.escapeshellarg('engineering_run')
            .' --run-context-id='.escapeshellarg($run->id);
    }

    /**
     * @param  array<string,mixed>  $artifactResult
     * @param  array<string,mixed>  $caseMetadata
     */
    private function recordVisualSmokeToolEvidence(AtlasEngineeringRun $run, string $workspace, array $artifactResult, array $caseMetadata): void
    {
        if (! is_array($caseMetadata['visual_e2e'] ?? null) || ! Schema::hasTable('atlas_tool_runs')) {
            return;
        }

        if (($artifactResult['status'] ?? null) !== 'captured' || ! is_string($artifactResult['artifact_path'] ?? null)) {
            return;
        }

        $artifactRoot = rtrim((string) $artifactResult['artifact_path'], DIRECTORY_SEPARATOR).'/atlas-visual-report';
        $manifestPath = $artifactRoot.'/manifest.json';
        if (! File::isFile($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            return;
        }

        $alreadyRecorded = AtlasToolRun::query()
            ->where('tool_slug', 'atlas_visual_smoke')
            ->where('surface', 'engineering_visual_smoke')
            ->where('run_context_type', 'engineering_run')
            ->where('run_context_id', $run->id)
            ->exists();
        if ($alreadyRecorded) {
            return;
        }

        $this->toolEvidence->recordExternalToolResult('atlas_visual_smoke', $workspace, [
            'status' => (string) ($manifest['status'] ?? 'unknown'),
            'required' => (bool) data_get($caseMetadata, 'visual_e2e.required', false),
            'failure_policy' => (bool) data_get($caseMetadata, 'visual_e2e.required', false) ? 'blocks_resolved' : 'advisory',
            'policy_decision' => 'allowed',
            'duration_ms' => (int) ($manifest['duration_ms'] ?? 0),
            'exit_code' => ($manifest['status'] ?? null) === 'passed' ? 0 : 1,
            'category' => 'browser_automation',
            'metrics' => [
                'route_count' => count((array) ($manifest['routes'] ?? [])),
                'route_failed_count' => data_get($manifest, 'strict_failure_summary.route_failed_count', 0),
                'dom_baseline_changed_count' => data_get($manifest, 'strict_failure_summary.dom_baseline_changed_count', 0),
                'screenshot_failed_count' => data_get($manifest, 'strict_failure_summary.screenshot_failed_count', 0),
                'screenshot_baseline_changed_count' => data_get($manifest, 'strict_failure_summary.screenshot_baseline_changed_count', 0),
            ],
            'artifact_paths' => [
                'manifest' => $manifestPath,
            ],
        ], [
            'surface' => 'engineering_visual_smoke',
            'source' => 'engineering_test_matrix_visual_smoke_result',
            'run_context_type' => 'engineering_run',
            'run_context_id' => $run->id,
            'metadata' => [
                'artifact_root_hash' => hash('sha256', $artifactRoot),
                'baseline_mode' => $manifest['baseline_mode'] ?? null,
                'screenshot_baseline_mode' => $manifest['screenshot_baseline_mode'] ?? null,
                'mirrored_from_test_run_artifacts' => true,
            ],
        ]);
    }

    /**
     * Subprocess quality scans record evidence themselves in normal databases.
     * The parent process mirrors captured output when evidence is not visible
     * yet, which also keeps sqlite in-memory test runs representative.
     *
     * @param  array<string,mixed>|null  $qualityScanResult
     */
    private function recordQualityScanToolEvidence(AtlasEngineeringRun $run, string $workspace, ?array $qualityScanResult): void
    {
        if ($qualityScanResult === null || ! Schema::hasTable('atlas_tool_runs')) {
            return;
        }

        foreach ((array) ($qualityScanResult['tools'] ?? []) as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $slug = (string) ($tool['slug'] ?? 'unknown');
            $alreadyRecorded = AtlasToolRun::query()
                ->where('tool_slug', $slug)
                ->where('surface', 'engineering_quality_scan')
                ->where('run_context_type', 'engineering_run')
                ->where('run_context_id', $run->id)
                ->exists();
            if ($alreadyRecorded) {
                continue;
            }

            $this->toolEvidence->recordExternalToolResult($slug, $workspace, $tool, [
                'surface' => 'engineering_quality_scan',
                'source' => 'engineering_test_matrix_quality_scan_result',
                'run_context_type' => 'engineering_run',
                'run_context_id' => $run->id,
                'metadata' => [
                    'scan_artifact_root_hash' => $qualityScanResult['artifact_root_hash'] ?? null,
                    'profile' => $qualityScanResult['profile'] ?? null,
                    'changed_only' => $qualityScanResult['changed_only'] ?? false,
                    'mirrored_from_test_run_output' => true,
                ],
            ]);
        }
    }

    private function canStartManagedVisualSmoke(string $workspace, WorkspaceProfile $profile): bool
    {
        if (File::isFile($workspace.'/artisan') || File::isFile($workspace.'/public/index.php')) {
            return true;
        }

        $scripts = $profile->scripts;

        return isset($scripts['dev']) || isset($scripts['web']) || isset($scripts['start']);
    }

    /**
     * @return array<int,array{command:string,detected_by:string}>
     */
    private function detectedVisualE2eCommands(string $workspace, WorkspaceProfile $profile): array
    {
        $packageManager = $profile->packageManager ?: 'npm';
        $scriptPriority = [
            'test:e2e',
            'e2e',
            'e2e:ci',
            'test:visual',
            'visual',
            'visual:ci',
            'test:playwright',
            'playwright',
            'playwright:ci',
            'test:cypress',
            'cypress',
            'cypress:ci',
            'test:browser',
            'browser',
        ];
        $commands = [];

        foreach ($scriptPriority as $script) {
            if (array_key_exists($script, $profile->scripts)) {
                $commands[] = [
                    'command' => $packageManager.' run '.$script,
                    'detected_by' => 'package_script:'.$script,
                ];
            }
        }

        foreach ($profile->scripts as $name => $scriptCommand) {
            $haystack = strtolower($name.' '.$scriptCommand);
            if (preg_match('/(^|[:\\-])(e2e|visual|playwright|cypress|browser)([:\\-]|$)/', $haystack) !== 1) {
                continue;
            }

            $commands[] = [
                'command' => str_starts_with($name, 'composer:')
                    ? 'composer '.substr($name, strlen('composer:'))
                    : $packageManager.' run '.$name,
                'detected_by' => 'script_signal:'.$name,
            ];
        }

        if ($commands === [] && $this->hasAnyFile($workspace, ['playwright.config.ts', 'playwright.config.js', 'playwright.config.mjs']) && File::isFile($workspace.'/node_modules/.bin/playwright')) {
            $commands[] = [
                'command' => './node_modules/.bin/playwright test --reporter=line',
                'detected_by' => 'playwright_config',
            ];
        }

        if ($commands === [] && $this->hasAnyFile($workspace, ['cypress.config.ts', 'cypress.config.js']) && File::isFile($workspace.'/node_modules/.bin/cypress')) {
            $commands[] = [
                'command' => './node_modules/.bin/cypress run',
                'detected_by' => 'cypress_config',
            ];
        }

        return collect($commands)
            ->unique('command')
            ->values()
            ->all();
    }

    private function visualE2eMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'off', 'required'], true) ? $mode : 'auto';
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    private function blueprintNeedsVisualEvidence(array $blueprint): bool
    {
        $signals = collect((array) ($blueprint['acceptance_matrix'] ?? []))
            ->map(fn (mixed $entry): string => is_array($entry) ? strtolower((string) ($entry['verification_method'] ?? '')) : '')
            ->merge(collect((array) data_get($blueprint, 'risk_register.open_risks', []))->map(fn (mixed $risk): string => strtolower((string) $risk)))
            ->implode(' ');

        return str_contains($signals, 'manual_qa')
            || str_contains($signals, 'playwright')
            || str_contains($signals, 'visual')
            || str_contains($signals, 'browser')
            || str_contains($signals, 'screenshot');
    }

    private function profileHasVisualE2eSignals(WorkspaceProfile $profile): bool
    {
        $signals = strtolower(implode(' ', array_merge(
            array_keys($profile->scripts),
            array_values($profile->scripts),
            $profile->files,
        )));

        return str_contains($signals, 'playwright')
            || str_contains($signals, 'cypress')
            || str_contains($signals, 'e2e')
            || str_contains($signals, 'visual');
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function hasAnyFile(string $workspace, array $paths): bool
    {
        foreach ($paths as $path) {
            if (File::isFile($workspace.'/'.$path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $caseMetadata
     * @return array<string,mixed>
     */
    private function captureVisualArtifacts(AtlasEngineeringRun $run, AtlasEngineeringTestRun $testRun, string $workspace, array $caseMetadata): array
    {
        $visual = (array) ($caseMetadata['visual_e2e'] ?? []);
        if ($visual === []) {
            return ['status' => 'not_applicable', 'reason' => 'test_case_not_visual_e2e'];
        }

        $paths = array_values(array_filter((array) ($visual['artifact_paths'] ?? $this->visualArtifactPaths()), fn (mixed $path): bool => is_string($path) && trim($path) !== ''));
        if ($paths === []) {
            return ['status' => 'skipped', 'reason' => 'no_visual_artifact_paths_configured'];
        }

        $workspace = realpath($workspace) ?: $workspace;
        $targetRoot = storage_path('app/engineering-runs/'.$run->id.'/visual-artifacts/'.$testRun->id);
        $maxFiles = max(1, (int) config('atlas.engineering.visual_e2e.artifact_max_files', 200));
        $maxBytes = max(1, (int) config('atlas.engineering.visual_e2e.artifact_max_bytes', 52_428_800));
        $copied = [];
        $totalBytes = 0;

        foreach ($paths as $relativePath) {
            $relativePath = trim((string) $relativePath, "/ \t\n\r\0\x0B");
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }

            $source = AtlasSecurity::canonicalPath($relativePath, $workspace, allowMissing: true);
            if (! AtlasSecurity::pathIsInside($source, $workspace) || ! File::exists($source)) {
                continue;
            }

            if (File::isFile($source)) {
                $bytes = File::size($source);
                if (count($copied) >= $maxFiles || $totalBytes + $bytes > $maxBytes) {
                    break;
                }
                $destination = $targetRoot.'/'.$relativePath;
                File::ensureDirectoryExists(dirname($destination));
                File::copy($source, $destination);
                $copied[] = ['path' => $relativePath, 'bytes' => $bytes];
                $totalBytes += $bytes;

                continue;
            }

            foreach (File::allFiles($source) as $file) {
                if (count($copied) >= $maxFiles) {
                    break 2;
                }

                $filePath = $file->getPathname();
                $bytes = $file->getSize();
                if ($totalBytes + $bytes > $maxBytes) {
                    break 2;
                }

                $artifactRelative = trim($relativePath.'/'.ltrim(Str::after($filePath, rtrim($source, DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
                $destination = $targetRoot.'/'.$artifactRelative;
                File::ensureDirectoryExists(dirname($destination));
                File::copy($filePath, $destination);
                $copied[] = ['path' => $artifactRelative, 'bytes' => $bytes];
                $totalBytes += $bytes;
            }
        }

        $result = $copied === []
            ? [
                'status' => 'empty',
                'paths' => $paths,
                'max_files' => $maxFiles,
                'max_bytes' => $maxBytes,
            ]
            : [
                'status' => 'captured',
                'artifact_path' => $targetRoot,
                'file_count' => count($copied),
                'total_bytes' => $totalBytes,
                'files' => array_slice($copied, 0, 50),
                'truncated' => count($copied) >= $maxFiles || $totalBytes >= $maxBytes,
            ];

        $testRun->forceFill([
            'artifact_path' => $result['artifact_path'] ?? $testRun->artifact_path,
            'metadata' => array_merge($testRun->metadata ?? [], [
                'visual_artifact_export' => $this->compactVisualArtifactResult($result),
                'visual_smoke' => $this->compactVisualSmokeManifest($result),
            ]),
        ])->save();

        return $result;
    }

    /**
     * @param  array<string,mixed>  $artifactResult
     * @return array<string,mixed>|null
     */
    private function compactVisualSmokeManifest(array $artifactResult): ?array
    {
        if (($artifactResult['status'] ?? null) !== 'captured' || ! is_string($artifactResult['artifact_path'] ?? null)) {
            return null;
        }

        $manifestPath = rtrim((string) $artifactResult['artifact_path'], DIRECTORY_SEPARATOR).'/atlas-visual-report/manifest.json';
        if (! File::isFile($manifestPath)) {
            return null;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            return null;
        }

        $routes = collect((array) ($manifest['routes'] ?? []))
            ->filter(fn (mixed $route): bool => is_array($route))
            ->map(fn (array $route): array => [
                'route' => $route['route'] ?? null,
                'ok' => (bool) ($route['ok'] ?? false),
                'status_code' => $route['status_code'] ?? null,
                'body_hash' => $route['body_hash'] ?? null,
                'baseline_status' => data_get($route, 'baseline.status'),
                'baseline_strict' => (bool) data_get($route, 'baseline.strict', false),
                'baseline_file_hash' => data_get($route, 'baseline.baseline_file_hash'),
                'screenshot_status' => data_get($route, 'screenshot.status'),
                'screenshot_sha256' => data_get($route, 'screenshot.sha256'),
                'screenshot_baseline_status' => data_get($route, 'screenshot.baseline.status'),
                'screenshot_baseline_strict' => (bool) data_get($route, 'screenshot.baseline.strict', false),
                'screenshot_changed_pixels' => data_get($route, 'screenshot.baseline.changed_pixels'),
                'screenshot_diff_ratio' => data_get($route, 'screenshot.baseline.diff_ratio'),
            ])
            ->values();

        return [
            'status' => $manifest['status'] ?? null,
            'failure' => $manifest['failure'] ?? null,
            'baseline_mode' => $manifest['baseline_mode'] ?? null,
            'route_count' => $routes->count(),
            'baseline_changed_count' => $routes->where('baseline_status', 'changed')->count(),
            'baseline_first_count' => $routes->where('baseline_status', 'first_baseline')->count(),
            'baseline_stable_count' => $routes->where('baseline_status', 'stable')->count(),
            'screenshot_baseline_changed_count' => $routes->where('screenshot_baseline_status', 'changed')->count(),
            'screenshot_baseline_first_count' => $routes->where('screenshot_baseline_status', 'first_baseline')->count(),
            'screenshot_baseline_stable_count' => $routes->where('screenshot_baseline_status', 'stable')->count(),
            'screenshot_status' => data_get($manifest, 'screenshot.status'),
            'screenshot_baseline_mode' => $manifest['screenshot_baseline_mode'] ?? null,
            'screenshot_driver' => $manifest['screenshot_driver'] ?? null,
            'strict_failure_summary' => $manifest['strict_failure_summary'] ?? null,
            'routes' => $routes->take(20)->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $caseMetadata
     * @return array<string,mixed>
     */
    private function captureQualityScanArtifacts(AtlasEngineeringRun $run, AtlasEngineeringTestRun $testRun, string $stdout, array $caseMetadata): array
    {
        if (! is_array($caseMetadata['quality_scan'] ?? null)) {
            return ['status' => 'not_applicable', 'reason' => 'test_case_not_quality_scan'];
        }

        $payload = json_decode(trim($stdout), true);
        if (! is_array($payload)) {
            return ['status' => 'skipped', 'reason' => 'quality_scan_json_parse_failed'];
        }

        $artifactRoot = $payload['artifact_root'] ?? null;
        if (! is_string($artifactRoot) || trim($artifactRoot) === '') {
            return ['status' => 'skipped', 'reason' => 'quality_scan_artifact_root_missing'];
        }

        $sourceRoot = realpath($artifactRoot);
        $allowedRoot = realpath(storage_path('app/engineering-quality-scans'));
        if (! $sourceRoot || ! $allowedRoot || ! File::isDirectory($sourceRoot)) {
            return ['status' => 'skipped', 'reason' => 'quality_scan_artifact_root_unavailable'];
        }

        $sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
        $allowedRoot = rtrim($allowedRoot, DIRECTORY_SEPARATOR);
        if ($sourceRoot === $allowedRoot || ! Str::startsWith($sourceRoot.DIRECTORY_SEPARATOR, $allowedRoot.DIRECTORY_SEPARATOR)) {
            return ['status' => 'skipped', 'reason' => 'quality_scan_artifact_root_outside_storage'];
        }

        $targetRoot = storage_path('app/engineering-runs/'.$run->id.'/quality-artifacts/'.$testRun->id);
        $maxFiles = max(1, (int) config('atlas.engineering.quality_scan.artifact_max_files', 100));
        $maxBytes = max(1, (int) config('atlas.engineering.quality_scan.artifact_max_bytes', 10_485_760));
        $copied = [];
        $totalBytes = 0;

        foreach (File::allFiles($sourceRoot) as $file) {
            if (count($copied) >= $maxFiles) {
                break;
            }

            $source = $file->getPathname();
            $bytes = $file->getSize();
            if ($totalBytes + $bytes > $maxBytes) {
                break;
            }

            $relativePath = ltrim(Str::after($source, $sourceRoot), DIRECTORY_SEPARATOR);
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }

            $destination = $targetRoot.'/'.$relativePath;
            File::ensureDirectoryExists(dirname($destination));
            File::copy($source, $destination);
            $copied[] = ['path' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), 'bytes' => $bytes];
            $totalBytes += $bytes;
        }

        $result = $copied === []
            ? [
                'status' => 'empty',
                'source_artifact_root_hash' => hash('sha256', $sourceRoot),
                'max_files' => $maxFiles,
                'max_bytes' => $maxBytes,
            ]
            : [
                'status' => 'captured',
                'artifact_path' => $targetRoot,
                'source_artifact_root_hash' => hash('sha256', $sourceRoot),
                'file_count' => count($copied),
                'total_bytes' => $totalBytes,
                'files' => array_slice($copied, 0, 50),
                'truncated' => count($copied) >= $maxFiles || $totalBytes >= $maxBytes,
            ];

        $testRun->forceFill([
            'artifact_path' => $result['artifact_path'] ?? $testRun->artifact_path,
            'metadata' => array_merge($testRun->metadata ?? [], [
                'quality_artifact_export' => $this->compactQualityArtifactResult($result),
            ]),
        ])->save();

        return $result;
    }

    /**
     * @param  array<string,mixed>  $caseMetadata
     * @return array<string,mixed>|null
     */
    private function compactQualityScanOutput(string $stdout, array $caseMetadata): ?array
    {
        if (! is_array($caseMetadata['quality_scan'] ?? null)) {
            return null;
        }

        $payload = json_decode(trim($stdout), true);
        if (! is_array($payload)) {
            return [
                'status' => 'unknown',
                'parse_error' => true,
            ];
        }

        return [
            'status' => $payload['status'] ?? null,
            'profile' => $payload['profile'] ?? null,
            'changed_only' => (bool) ($payload['changed_only'] ?? false),
            'summary' => $payload['summary'] ?? [],
            'artifact_root_hash' => $payload['artifact_root_hash'] ?? null,
            'cost_posture' => $payload['cost_posture'] ?? null,
            'paid_tool_required' => (bool) ($payload['paid_tool_required'] ?? true),
            'tools' => collect((array) ($payload['tools'] ?? []))
                ->filter(fn (mixed $tool): bool => is_array($tool))
                ->map(fn (array $tool): array => [
                    'slug' => $tool['slug'] ?? null,
                    'category' => $tool['category'] ?? null,
                    'status' => $tool['status'] ?? null,
                    'exit_code' => $tool['exit_code'] ?? null,
                    'reason' => $tool['reason'] ?? null,
                ])
                ->take(30)
                ->values()
                ->all(),
            'findings' => collect((array) ($payload['findings'] ?? []))
                ->filter(fn (mixed $finding): bool => is_array($finding))
                ->map(fn (array $finding): array => [
                    'tool' => $finding['tool'] ?? null,
                    'category' => $finding['category'] ?? null,
                    'severity' => $finding['severity'] ?? null,
                    'rule_id' => $finding['rule_id'] ?? null,
                    'title' => $finding['title'] ?? null,
                    'file' => $finding['file'] ?? null,
                    'line' => $finding['line'] ?? null,
                    'blocks_resolved' => (bool) ($finding['blocks_resolved'] ?? false),
                ])
                ->take(30)
                ->values()
                ->all(),
            'recommendations' => collect((array) ($payload['recommendations'] ?? []))
                ->filter(fn (mixed $recommendation): bool => is_array($recommendation))
                ->map(fn (array $recommendation): array => [
                    'tool' => $recommendation['tool'] ?? null,
                    'category' => $recommendation['category'] ?? null,
                    'priority' => $recommendation['priority'] ?? null,
                    'reason' => $recommendation['reason'] ?? null,
                    'title' => $recommendation['title'] ?? null,
                    'install_hint' => $recommendation['install_hint'] ?? null,
                    'paid_tool_required' => (bool) ($recommendation['paid_tool_required'] ?? true),
                ])
                ->take(30)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function compactQualityArtifactResult(array $result): array
    {
        return [
            'status' => $result['status'] ?? null,
            'artifact_path_hash' => isset($result['artifact_path']) ? hash('sha256', (string) $result['artifact_path']) : null,
            'source_artifact_root_hash' => $result['source_artifact_root_hash'] ?? null,
            'file_count' => $result['file_count'] ?? 0,
            'total_bytes' => $result['total_bytes'] ?? 0,
            'truncated' => (bool) ($result['truncated'] ?? false),
            'reason' => $result['reason'] ?? null,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function visualArtifactPaths(): array
    {
        return collect((array) config('atlas.engineering.visual_e2e.artifact_paths', [
            'playwright-report',
            'test-results',
            'cypress/screenshots',
            'cypress/videos',
            'atlas-visual-report',
        ]))
            ->filter(fn (mixed $path): bool => is_scalar($path) && trim((string) $path) !== '')
            ->map(fn (mixed $path): string => trim((string) $path))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function compactVisualArtifactResult(array $result): array
    {
        return [
            'status' => $result['status'] ?? null,
            'artifact_path_hash' => isset($result['artifact_path']) ? hash('sha256', (string) $result['artifact_path']) : null,
            'file_count' => $result['file_count'] ?? 0,
            'total_bytes' => $result['total_bytes'] ?? 0,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    private function controlForSlug(mixed $slug): ?AtlasEngineeringControl
    {
        if (! is_string($slug) || $slug === '' || ! Schema::hasTable('atlas_engineering_controls')) {
            return null;
        }

        return AtlasEngineeringControl::query()->where('slug', $slug)->first();
    }

    private function typeForCommand(string $command): string
    {
        $lower = strtolower($command);

        return match (true) {
            str_contains($lower, 'quality-scan') => 'quality_scan',
            str_contains($lower, 'typecheck') => 'typecheck',
            str_contains($lower, 'lint'), str_contains($lower, 'pint') => 'lint',
            str_contains($lower, 'migrate') => 'migration',
            str_contains($lower, 'visual-smoke'), str_contains($lower, 'playwright'), str_contains($lower, 'e2e') => 'e2e',
            str_contains($lower, 'test:front') => 'feature',
            default => 'unit',
        };
    }

    private function caseCode(string $type, string $command): string
    {
        return $type.'_'.substr(hash('sha256', $command), 0, 12);
    }
}
