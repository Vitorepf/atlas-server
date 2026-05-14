<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AtlasEngineeringBenchmarkFairCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:claude-fair
        {action=run : prepare, run, run-atlas, run-claude-code, report, readiness, runbook, replay, verify or triage-invalid-battery}
        {run? : Benchmark run id for replay}
        {--suite=atlas-core-smoke : Suite slug or id}
        {--workspace= : Workspace path for benchmark execution}
        {--case=* : Restrict execution to one or more case codes}
        {--tag=* : Restrict execution to cases carrying these tags}
        {--limit= : Maximum number of cases or report runs}
        {--tier= : Restrict execution to a corpus tier}
        {--domain= : Restrict execution to a corpus domain}
        {--risk= : Restrict execution to a risk profile}
        {--curation-status= : Restrict execution to a curation status}
        {--model=opus : Fair Claude model lock. Only opus is accepted.}
        {--model-policy=fixed : Fair Claude model policy. Only fixed is accepted.}
        {--test-command= : Explicit deterministic validation command}
        {--quality-changed-only : Force fair quality scan to changed files for patch-scoped A/B validity}
        {--claude-code-baseline-workspace= : Separate workspace for claude-code baseline run}
        {--claude-code-baseline-binary= : Claude Code CLI binary override}
        {--claude-code-baseline-timeout=900 : Seconds to wait for Claude Code baseline run}
        {--claude-code-baseline-validation-timeout=300 : Seconds to wait for baseline deterministic validation}
        {--case-timeout=1800 : Maximum wall-clock seconds budgeted per paired Fair Claude case}
        {--max-attempts=3 : Maximum Atlas repair attempts}
        {--permission=auto : auto, read, write or danger}
        {--sandbox=workspace : workspace, worktree or docker}
        {--provider-runtime=host : host, docker or auto for atlas:cli:dev execution}
        {--provider-timeout=600 : Seconds to wait for Atlas provider execution before aborting}
        {--test-timeout=300 : Seconds to wait for each deterministic test command before aborting}
        {--gate-profile=strict : Release gate profile: release, smoke, strict, advisory or off}
        {--keep-workspace : Keep isolated execution workspace after the run for debugging}
        {--no-auto-test : Disable auto-test for run and run-atlas}
        {--confirm-runbook-reviewed : Confirm the Fair Claude runbook/preflight was reviewed before provider execution}
        {--confirm-provider-cost : Confirm external provider cost/token usage before provider execution}
        {--confirm-invalid-battery-quarantine : Confirm invalid historical battery should be quarantined without admitting score}
        {--reason= : Human triage reason for triage-invalid-battery}
        {--run-id= : Benchmark run id for replay; alias for the positional run argument}
        {--output-dir= : Write or verify report.json, evidence.json, claim.md and manifest.json for report/readiness/verify}
        {--markdown : Print audit-ready Markdown for report/readiness}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the official opt-in Fair Claude benchmark workflow against Claude Code CLI.';

    public function handle(EngineeringBenchmarkService $benchmarks, FairClaudePolicy $fairClaude): int
    {
        $action = $this->normalizeAction((string) $this->argument('action'));
        $modelViolation = $this->validateFairModelLock($fairClaude);
        if ($modelViolation !== null) {
            return $this->fairModeViolation($modelViolation);
        }
        if (in_array($action, ['run', 'run-atlas', 'run-claude-code'], true)) {
            $confirmationGuard = $this->guardProviderExecutionConfirmation($action);
            if ($confirmationGuard !== null) {
                return $confirmationGuard;
            }
            $preflightGuard = $this->guardProviderExecutionPreflight($benchmarks, $action);
            if ($preflightGuard !== null) {
                return $preflightGuard;
            }
        }

        return match ($action) {
            'prepare' => $this->prepare($benchmarks),
            'run' => $this->callForwarded('atlas:engineering:benchmark', $this->runArgs('run')),
            'run-atlas' => $this->callForwarded('atlas:engineering:benchmark', $this->runArgs('off')),
            'run-claude-code' => $this->callForwarded('atlas:engineering:benchmark', $this->runArgs('run', noProvider: true)),
            'report', 'readiness' => $this->report($benchmarks),
            'verify', 'verify-export' => $this->verifyExport($benchmarks),
            'triage-invalid-battery', 'quarantine-invalid-battery' => $this->triageInvalidBattery($benchmarks),
            'runbook', 'doctor' => $this->runbook($benchmarks),
            'replay' => $this->replay($benchmarks),
            default => $this->unknownAction($action),
        };
    }

    private function prepare(EngineeringBenchmarkService $benchmarks): int
    {
        $args = $this->prepareArgs();
        $json = (bool) ($args['--json'] ?? false);
        $args['--json'] = false;

        $exitCode = Artisan::call('atlas:engineering:benchmark:seed', $args);
        if ($exitCode !== self::SUCCESS) {
            $output = Artisan::output();
            if ($output !== '') {
                $this->output->write($output);
            }

            return $exitCode;
        }

        $suiteQuery = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', $this->suite());
        if (Str::isUuid($this->suite())) {
            $suiteQuery->orWhere('id', $this->suite());
        }
        $suite = $suiteQuery
            ->with('cases')
            ->first();
        if (! $suite) {
            $this->error("Benchmark suite nao encontrada: {$this->suite()}");

            return self::FAILURE;
        }

        $manifest = $benchmarks->refreshCorpusManifest($suite->refresh());
        $cases = $suite->refresh()
            ->cases()
            ->where('metadata->source', 'fair_claude_seed_v1')
            ->orderBy('case_code')
            ->get();
        $payload = [
            'suite' => $benchmarks->suitePayload($suite->refresh())['suite'] ?? null,
            'corpus_manifest' => $manifest,
            'promoted_count' => $cases->count(),
            'promoted_cases' => $cases
                ->map(fn ($case): array => $benchmarks->casePayload($case))
                ->values()
                ->all(),
        ];

        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Fair Claude corpus</>', (string) data_get($payload, 'suite.slug', '-'));
        $this->components->twoColumnDetail('Seeded cases', (string) $cases->count());
        $this->components->twoColumnDetail('Active cases', (string) data_get($manifest, 'active_cases', 0));

        return self::SUCCESS;
    }

    private function report(EngineeringBenchmarkService $benchmarks): int
    {
        $suiteRef = $this->suite();
        $suiteQuery = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', $suiteRef);
        if (Str::isUuid($suiteRef)) {
            $suiteQuery->orWhere('id', $suiteRef);
        }
        $suite = $suiteQuery->first();
        if (! $suite) {
            if ((bool) $this->option('json')) {
                return $this->jsonError('benchmark_suite_not_found', "Benchmark suite nao encontrada: {$suiteRef}", [
                    'suite' => $suiteRef,
                ]);
            }

            $this->error("Benchmark suite nao encontrada: {$suiteRef}");

            return self::FAILURE;
        }

        $payload = $benchmarks->fairClaudeReportPayload($suite, [
            'limit' => $this->intOption('limit') ?: 20,
        ]);
        $outputDir = $this->stringOption('output-dir');
        if ($outputDir !== null) {
            $payload['written_export_bundle'] = $benchmarks->writeFairClaudeExportBundle($payload, $outputDir);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ((bool) $this->option('markdown')) {
            $this->line((string) ($payload['claim_markdown'] ?? ''));

            return self::SUCCESS;
        }

        return $this->callForwarded('atlas:engineering:benchmark:report', $this->reportArgs());
    }

    private function verifyExport(EngineeringBenchmarkService $benchmarks): int
    {
        $outputDir = $this->stringOption('output-dir');
        if ($outputDir === null) {
            $payload = [
                'schema_version' => 1,
                'kind' => 'fair_claude_export_bundle_verification',
                'status' => 'failed',
                'verified' => false,
                'blocking_reasons' => ['output_dir_required'],
                'message' => '--output-dir e obrigatorio para verify.',
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($payload['message']);
            }

            return self::FAILURE;
        }

        $payload = $benchmarks->verifyFairClaudeExportBundle($outputDir);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['verified'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Fair Claude Export Verification</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Directory', (string) ($payload['directory'] ?? $outputDir));
        $this->components->twoColumnDetail('Files checked', (string) ($payload['file_count'] ?? 0));
        $this->components->twoColumnDetail('Passed', (string) ($payload['passed_count'] ?? 0));
        $this->components->twoColumnDetail('Failed', (string) ($payload['failed_count'] ?? 0));
        $this->components->twoColumnDetail('Missing', (string) ($payload['missing_count'] ?? 0));

        foreach ((array) ($payload['blocking_reasons'] ?? []) as $reason) {
            $this->warn('Blocking: '.(string) $reason);
        }
        foreach ((array) ($payload['files'] ?? []) as $filename => $file) {
            if (($file['status'] ?? null) !== 'passed') {
                $this->warn((string) $filename.': '.(string) ($file['status'] ?? 'failed'));
            }
        }

        return ($payload['verified'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function triageInvalidBattery(EngineeringBenchmarkService $benchmarks): int
    {
        $suite = $this->findSuite();
        if (! $suite) {
            return $this->jsonError('benchmark_suite_not_found', "Benchmark suite nao encontrada: {$this->suite()}", [
                'suite' => $this->suite(),
                'safety' => [
                    'no_provider_call' => true,
                    'no_score_admitted' => true,
                    'no_history_deleted' => true,
                ],
            ]);
        }

        $report = $benchmarks->fairClaudeReportPayload($suite, [
            'limit' => $this->intOption('limit') ?: 20,
        ]);
        $fingerprint = (string) data_get($report, 'scope.invalid_battery_fingerprint', '');
        $status = (string) data_get($report, 'result_integrity.status', 'unknown');
        $reason = trim((string) ($this->option('reason') ?? ''));
        $confirmed = (bool) $this->option('confirm-invalid-battery-quarantine');

        if ($status !== 'invalid_battery_no_comparable_score') {
            return $this->jsonError('no_invalid_battery_to_quarantine', 'Nao existe bateria invalida ativa para quarentena.', [
                'suite' => data_get($report, 'suite'),
                'result_integrity' => data_get($report, 'result_integrity'),
                'safety' => [
                    'no_provider_call' => true,
                    'no_score_admitted' => true,
                    'no_history_deleted' => true,
                ],
            ]);
        }

        if (! $confirmed || $reason === '') {
            return $this->jsonError('invalid_battery_quarantine_confirmation_required', 'Confirme a quarentena e informe --reason.', [
                'suite' => data_get($report, 'suite'),
                'required_flags' => [
                    '--confirm-invalid-battery-quarantine',
                    '--reason=<human-triage-reason>',
                ],
                'invalid_battery_fingerprint' => $fingerprint,
                'result_integrity' => data_get($report, 'result_integrity'),
                'safety' => [
                    'no_provider_call' => true,
                    'no_score_admitted' => true,
                    'no_history_deleted' => true,
                ],
            ]);
        }

        $metadata = is_array($suite->metadata) ? $suite->metadata : [];
        $previousTriage = is_array($metadata['rivals_invalid_battery_triage'] ?? null)
            ? $metadata['rivals_invalid_battery_triage']
            : [];
        $records = collect((array) ($previousTriage['records'] ?? []))
            ->filter(fn (mixed $record): bool => is_array($record))
            ->reject(fn (array $record): bool => (string) ($record['invalid_battery_fingerprint'] ?? '') === $fingerprint)
            ->values()
            ->all();
        $record = [
            'schema_version' => 'atlas.fair_claude.invalid_battery_triage.v1',
            'status' => 'triaged_quarantined',
            'invalid_battery_fingerprint' => $fingerprint,
            'reason' => $reason,
            'triaged_at' => now()->toJSON(),
            'triaged_by' => 'engineering_operator',
            'policy' => [
                'history_deleted' => false,
                'score_admitted' => false,
                'claim_winner_admitted' => false,
                'provider_dispatch_performed' => false,
                'invalid_cases_count_as_losses' => false,
                'next_run_still_requires_clean_workspaces_and_cost_confirmation' => true,
            ],
            'counts' => data_get($report, 'result_integrity.counts', []),
            'blocking_reason_counts' => data_get($report, 'result_integrity.blocking_reason_counts', []),
            'run_ids' => collect((array) data_get($report, 'runs', []))
                ->pluck('id')
                ->filter()
                ->values()
                ->all(),
        ];
        $records[] = $record;
        $metadata['rivals_invalid_battery_triage'] = array_merge($record, [
            'records' => $records,
            'record_count' => count($records),
            'accepted_fingerprints' => collect($records)
                ->pluck('invalid_battery_fingerprint')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
        $suite->forceFill(['metadata' => $metadata])->save();

        $payload = [
            'schema_version' => 'atlas.fair_claude.invalid_battery_triage_result.v1',
            'status' => 'triaged_quarantined',
            'suite' => data_get($report, 'suite'),
            'invalid_battery_fingerprint' => $fingerprint,
            'accepted_fingerprints' => $metadata['rivals_invalid_battery_triage']['accepted_fingerprints'],
            'record' => $metadata['rivals_invalid_battery_triage'],
            'safety' => [
                'no_provider_call' => true,
                'no_score_admitted' => true,
                'no_history_deleted' => true,
                'invalid_cases_stay_out_of_win_loss_math' => true,
            ],
            'next_action' => 'Create clean Atlas and baseline worktrees, review runbook, then request an explicit provider run.',
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function runbook(EngineeringBenchmarkService $benchmarks): int
    {
        $suite = $this->findSuite();
        $report = $suite
            ? $benchmarks->fairClaudeReportPayload($suite, [
                'limit' => $this->intOption('limit') ?: 20,
            ])
            : null;
        $payload = $this->runbookPayload($report);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return empty($payload['start_blocking_reasons']) ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Fair Claude Battery Runbook</>', (string) ($payload['start_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Suite', (string) data_get($payload, 'suite.slug', $this->suite()));
        $this->components->twoColumnDetail('Workspace', (string) ($payload['workspace'] ?? '-'));
        $this->components->twoColumnDetail('Baseline workspace', (string) ($payload['claude_code_baseline_workspace'] ?? '-'));
        foreach ((array) ($payload['start_blocking_reasons'] ?? []) as $reason) {
            $this->warn('Blocking: '.(string) $reason);
        }
        foreach ((array) ($payload['commands'] ?? []) as $step => $command) {
            $this->line($step.': '.$command);
        }

        return empty($payload['start_blocking_reasons']) ? self::SUCCESS : self::FAILURE;
    }

    private function callForwarded(string $command, array $args): int
    {
        $exitCode = Artisan::call($command, $args);
        $output = Artisan::output();
        if ($output !== '') {
            $this->output->write($output);
        }

        return $exitCode;
    }

    /**
     * @return array<string,mixed>
     */
    private function prepareArgs(): array
    {
        return array_filter([
            '--suite' => $this->suite(),
            '--workspace' => $this->stringOption('workspace'),
            '--tier' => $this->stringOption('tier') ?: 'release',
            '--domain' => $this->stringOption('domain'),
            '--risk' => $this->stringOption('risk'),
            '--curation-status' => $this->stringOption('curation-status') ?: 'curated',
            '--tag' => (array) $this->option('tag'),
            '--fair-claude-corpus' => true,
            '--refresh-manifest' => true,
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    private function findSuite(): ?AtlasEngineeringBenchmarkSuite
    {
        $suiteRef = $this->suite();
        $suiteQuery = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', $suiteRef);
        if (Str::isUuid($suiteRef)) {
            $suiteQuery->orWhere('id', $suiteRef);
        }

        return $suiteQuery->first();
    }

    /**
     * @param  array<string,mixed>|null  $report
     * @return array<string,mixed>
     */
    private function runbookPayload(?array $report): array
    {
        $suite = (array) data_get($report, 'suite', []);
        $workspace = $this->stringOption('workspace');
        $baselineWorkspace = $this->stringOption('claude-code-baseline-workspace');
        $baselineBinary = $this->stringOption('claude-code-baseline-binary') ?: (string) config('atlas.ai.providers.claude_cli.binary', 'claude');
        $finder = new ExecutableFinder;
        $baselineBinaryFound = $this->binaryExists($baselineBinary, $finder);
        $workspaceOk = $workspace !== null && is_dir($workspace);
        $baselineWorkspaceOk = $baselineWorkspace !== null && is_dir($baselineWorkspace);
        $baselineSeparate = $workspace !== null
            && $baselineWorkspace !== null
            && realpath($workspace) !== realpath($baselineWorkspace);
        $workspaceGit = $workspaceOk ? $this->gitWorkspaceState((string) $workspace) : ['is_git' => false, 'clean' => null, 'dirty_files' => []];
        $baselineGit = $baselineWorkspaceOk ? $this->gitWorkspaceState((string) $baselineWorkspace) : ['is_git' => false, 'clean' => null, 'dirty_files' => []];
        $workspaceRuntime = $workspaceOk ? $this->workspaceRuntimeState((string) $workspace) : $this->emptyWorkspaceRuntimeState();
        $baselineRuntime = $baselineWorkspaceOk ? $this->workspaceRuntimeState((string) $baselineWorkspace) : $this->emptyWorkspaceRuntimeState();
        $releaseCorpusCount = (int) data_get($report, 'readiness.release_corpus_case_count', 0);
        $resultIntegrityStatus = (string) data_get($report, 'result_integrity.status', 'unknown');
        $historicalInvalidBatteryRequiresTriage = $this->historicalInvalidBatteryRequiresTriage($report);

        $blocking = [];
        if ($report === null) {
            $blocking[] = 'suite_not_prepared';
        }
        if ($historicalInvalidBatteryRequiresTriage) {
            $blocking[] = 'historical_invalid_battery_requires_triage';
        }
        if ($releaseCorpusCount < 6) {
            $blocking[] = 'fair_release_corpus_below_minimum';
        }
        if (! $workspaceOk) {
            $blocking[] = 'workspace_missing_or_unreadable';
        }
        if (! $baselineWorkspaceOk) {
            $blocking[] = 'claude_code_baseline_workspace_missing_or_unreadable';
        }
        if ($workspaceOk && $baselineWorkspaceOk && ! $baselineSeparate) {
            $blocking[] = 'baseline_workspace_must_be_separate';
        }
        if ($workspaceOk && ($workspaceGit['is_git'] ?? false) !== true) {
            $blocking[] = 'atlas_workspace_not_git_worktree';
        }
        if ($baselineWorkspaceOk && ($baselineGit['is_git'] ?? false) !== true) {
            $blocking[] = 'claude_code_baseline_workspace_not_git_worktree';
        }
        if (($workspaceGit['is_git'] ?? false) && ($workspaceGit['clean'] ?? null) !== true) {
            $blocking[] = 'atlas_workspace_dirty';
        }
        if (($baselineGit['is_git'] ?? false) && ($baselineGit['clean'] ?? null) !== true) {
            $blocking[] = 'claude_code_baseline_workspace_dirty';
        }
        if (($workspaceRuntime['laravel_runtime_detected'] ?? false) && ($workspaceRuntime['vendor_autoload_exists'] ?? false) !== true) {
            $blocking[] = 'atlas_workspace_vendor_autoload_missing';
        }
        if (($baselineRuntime['laravel_runtime_detected'] ?? false) && ($baselineRuntime['vendor_autoload_exists'] ?? false) !== true) {
            $blocking[] = 'claude_code_baseline_vendor_autoload_missing';
        }
        if (($workspaceRuntime['laravel_runtime_detected'] ?? false) && ($workspaceRuntime['env_file_exists'] ?? false) !== true) {
            $blocking[] = 'atlas_workspace_env_missing';
        }
        if (($baselineRuntime['laravel_runtime_detected'] ?? false) && ($baselineRuntime['env_file_exists'] ?? false) !== true) {
            $blocking[] = 'claude_code_baseline_env_missing';
        }
        if (! $baselineBinaryFound) {
            $blocking[] = 'claude_code_binary_not_found';
        }

        $base = 'atlas benchmark claude-fair';
        $suiteArg = '--suite='.$this->shellArg($this->suite());
        $workspaceArg = $workspace !== null ? ' --workspace='.$this->shellArg($workspace) : ' --workspace=<atlas-arm-workspace>';
        $baselineWorkspaceArg = $baselineWorkspace !== null
            ? ' --claude-code-baseline-workspace='.$this->shellArg($baselineWorkspace)
            : ' --claude-code-baseline-workspace=<separate-claude-code-workspace>';
        $binaryArg = $baselineBinary !== '' ? ' --claude-code-baseline-binary='.$this->shellArg($baselineBinary) : '';
        $caseArgs = collect((array) $this->option('case'))
            ->map(fn (mixed $case): string => ' --case='.$this->shellArg((string) $case))
            ->implode('');
        $tagArgs = collect((array) $this->option('tag'))
            ->map(fn (mixed $tag): string => ' --tag='.$this->shellArg((string) $tag))
            ->implode('');
        $filterArgs = $caseArgs.$tagArgs
            .($this->stringOption('tier') ? ' --tier='.$this->shellArg((string) $this->stringOption('tier')) : '')
            .($this->stringOption('domain') ? ' --domain='.$this->shellArg((string) $this->stringOption('domain')) : '')
            .($this->stringOption('risk') ? ' --risk='.$this->shellArg((string) $this->stringOption('risk')) : '');
        $commonRunArgs = "{$suiteArg}{$workspaceArg}{$baselineWorkspaceArg}{$binaryArg}{$filterArgs}"
            .' --model='.$this->fairModelOption()
            .' --model-policy='.($this->stringOption('model-policy') ?: 'fixed')
            .' --provider-timeout='.($this->intOption('provider-timeout') ?: 600)
            .' --case-timeout='.($this->intOption('case-timeout') ?: 1800)
            .' --test-timeout='.($this->intOption('test-timeout') ?: 300)
            .' --gate-profile='.$this->shellArg($this->stringOption('gate-profile') ?: 'strict')
            .' --quality-changed-only'
            .' --confirm-runbook-reviewed --confirm-provider-cost';
        $doctorArgs = "{$suiteArg}{$workspaceArg}{$baselineWorkspaceArg}{$binaryArg}"
            .' --model='.$this->fairModelOption()
            .' --model-policy='.($this->stringOption('model-policy') ?: 'fixed');

        return [
            'schema_version' => 1,
            'kind' => 'fair_claude_battery_runbook',
            'generated_at' => now()->toJSON(),
            'start_status' => $blocking === [] ? 'ready_to_start' : 'blocked',
            'ready_to_start_battery' => $blocking === [],
            'start_blocking_reasons' => $blocking,
            'suite' => $suite !== [] ? $suite : ['slug' => $this->suite(), 'exists' => false],
            'workspace' => $workspace,
            'claude_code_baseline_workspace' => $baselineWorkspace,
            'preflight' => [
                'suite_prepared' => $report !== null,
                'release_corpus_case_count' => $releaseCorpusCount,
                'minimum_release_corpus_case_count' => 6,
                'workspace_exists' => $workspaceOk,
                'workspace_git' => $workspaceGit,
                'workspace_runtime' => $workspaceRuntime,
                'baseline_workspace_exists' => $baselineWorkspaceOk,
                'baseline_workspace_separate' => $baselineSeparate,
                'baseline_workspace_git' => $baselineGit,
                'baseline_workspace_runtime' => $baselineRuntime,
                'claude_code_binary' => $baselineBinary,
                'claude_code_binary_found' => $baselineBinaryFound,
                'report_readiness_status' => data_get($report, 'readiness.status'),
                'report_result_integrity_status' => $resultIntegrityStatus,
                'historical_invalid_battery_requires_triage' => $historicalInvalidBatteryRequiresTriage,
                'report_blocking_reasons' => data_get($report, 'readiness.blocking_reasons', []),
            ],
            'commands' => [
                'prepare_clean_atlas_worktree' => 'git worktree add <clean-atlas-workspace> HEAD',
                'prepare_clean_baseline_worktree' => 'git worktree add <separate-clean-baseline-workspace> HEAD',
                'verify_atlas_worktree_clean' => 'git -C <clean-atlas-workspace> status --short',
                'verify_baseline_worktree_clean' => 'git -C <separate-clean-baseline-workspace> status --short',
                'prepare_corpus' => "{$base} prepare {$suiteArg} --json",
                'doctor' => "{$base} runbook {$doctorArgs} --json",
                'run_full_paired_battery' => "{$base} run {$commonRunArgs} --json",
                'run_atlas_arm_only' => "{$base} run-atlas {$commonRunArgs} --json",
                'run_claude_code_baseline_only' => "{$base} run-claude-code {$commonRunArgs} --json",
                'report' => "{$base} report {$suiteArg} --json",
                'readiness' => "{$base} readiness {$suiteArg} --json",
                'replay' => "{$base} replay --run-id=<benchmark-run-id> --json",
            ],
            'protocol' => [
                'atlas_provider_lock' => 'claude_cli',
                'atlas_model_lock' => 'opus',
                'baseline_provider_lock' => 'claude_code_cli',
                'baseline_model_lock' => 'opus',
                'forbidden_in_fair_mode' => ['codex_cli', 'gemini_cli', 'claude_codex', 'atlas_decide', 'fallback', 'council'],
                'pass_without_human_requires' => ['provider_lock', 'model_lock', 'deterministic_gates_passed', 'human_intervention_count_zero'],
            ],
            'provider_execution_guard' => [
                'schema_version' => 'atlas.fair_claude.provider_execution_guard.v1',
                'confirm_runbook_reviewed_required' => true,
                'confirm_provider_cost_required' => true,
                'ready_runbook_required' => true,
                'git_worktree_required' => true,
                'invalid_battery_triage_required_before_rerun' => true,
                'laravel_runtime_preflight_required' => true,
                'clean_atlas_workspace_required' => true,
                'clean_baseline_workspace_required' => true,
                'blocking_error' => 'fair_claude_provider_execution_confirmation_required',
            ],
        ];
    }

    private function binaryExists(string $binary, ExecutableFinder $finder): bool
    {
        $binary = trim($binary);
        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            $resolved = realpath($binary) ?: $binary;

            return is_file($resolved) && is_executable($resolved);
        }

        return $finder->find($binary) !== null;
    }

    /**
     * @param  array<string,mixed>|null  $report
     */
    private function historicalInvalidBatteryRequiresTriage(?array $report): bool
    {
        if ($report === null) {
            return false;
        }

        if ((bool) data_get($report, 'result_integrity.triage_required_before_rerun', false) === false
            && (string) data_get($report, 'result_integrity.invalid_battery_triage.status') === 'triaged_quarantined') {
            return false;
        }

        if ((string) data_get($report, 'result_integrity.status') === 'invalid_battery_no_comparable_score') {
            return true;
        }

        $nextActionIds = collect((array) data_get($report, 'next_actions', []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        if (in_array('triage_invalid_battery_before_provider_rerun', $nextActionIds, true)) {
            return true;
        }

        $pairedRunCount = (int) data_get($report, 'scope.paired_run_count', 0);
        $caseComparisonCount = (int) data_get($report, 'scope.case_comparison_count', 0);
        $comparableCount = (int) data_get($report, 'readiness.comparable_count', 0);
        $invalidCaseCount = (int) data_get($report, 'paired_scorecard.invalid_case_count', 0);

        return ($pairedRunCount > 0
            && $caseComparisonCount > 0
            && $comparableCount === 0
            && $invalidCaseCount > 0)
            || $this->suiteHasHistoricalInvalidFairBattery($report);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function suiteHasHistoricalInvalidFairBattery(array $report): bool
    {
        $suiteId = data_get($report, 'suite.id');
        if (! is_string($suiteId) || $suiteId === '') {
            return false;
        }

        $results = AtlasEngineeringBenchmarkResult::query()
            ->where('suite_id', $suiteId)
            ->latest()
            ->limit(50)
            ->get();
        $hasInvalidFairCase = false;
        $hasComparableFairCase = false;

        foreach ($results as $result) {
            $scorecard = data_get($result->observed_json ?? [], 'paired_scorecard');
            if (! is_array($scorecard) || ! (bool) ($scorecard['fair_mode'] ?? false)) {
                continue;
            }

            if (
                (string) ($scorecard['comparison_status'] ?? '') === 'atlas_protocol_invalid'
                || (bool) data_get($scorecard, 'atlas.protocol_valid', true) === false
            ) {
                $hasInvalidFairCase = true;

                continue;
            }

            if ((bool) ($scorecard['comparable'] ?? false) && (string) ($scorecard['comparison_status'] ?? '') === 'comparable') {
                $hasComparableFairCase = true;
            }
        }

        return $hasInvalidFairCase && ! $hasComparableFairCase
            && (string) data_get($report, 'result_integrity.invalid_battery_triage.status') !== 'triaged_quarantined';
    }

    private function shellArg(string $value): string
    {
        return escapeshellarg($value);
    }

    /**
     * @return array<string,mixed>
     */
    private function runArgs(string $baselineMode, bool $noProvider = false): array
    {
        return array_filter([
            '--suite' => $this->suite(),
            '--workspace' => $this->stringOption('workspace'),
            '--case' => (array) $this->option('case'),
            '--tag' => (array) $this->option('tag'),
            '--limit' => $this->intOption('limit'),
            '--tier' => $this->stringOption('tier'),
            '--domain' => $this->stringOption('domain'),
            '--risk' => $this->stringOption('risk'),
            '--curation-status' => $this->stringOption('curation-status'),
            '--model' => $this->fairModelOption(),
            '--model-policy' => $this->stringOption('model-policy') ?: 'fixed',
            '--claude-only' => ! $noProvider,
            '--single-provider' => ! $noProvider,
            '--no-decide' => ! $noProvider,
            '--fallback-disabled' => ! $noProvider,
            '--claude-code-baseline' => $baselineMode,
            '--claude-code-baseline-model' => 'opus',
            '--claude-code-baseline-binary' => $this->stringOption('claude-code-baseline-binary'),
            '--claude-code-baseline-workspace' => $this->stringOption('claude-code-baseline-workspace'),
            '--claude-code-baseline-timeout' => $this->intOption('claude-code-baseline-timeout') ?: 900,
            '--claude-code-baseline-validation-timeout' => $this->intOption('claude-code-baseline-validation-timeout') ?: 300,
            '--case-timeout' => $this->intOption('case-timeout') ?: 1800,
            '--permission' => $this->stringOption('permission') ?: 'auto',
            '--sandbox' => $this->stringOption('sandbox') ?: 'workspace',
            '--provider-runtime' => $this->stringOption('provider-runtime') ?: 'host',
            '--provider-timeout' => $this->intOption('provider-timeout') ?: 600,
            '--test-timeout' => $this->intOption('test-timeout') ?: 300,
            '--max-attempts' => $this->intOption('max-attempts') ?: 3,
            '--test-command' => $this->stringOption('test-command'),
            '--complete' => ! $noProvider,
            '--auto-test' => ! (bool) $this->option('no-auto-test'),
            '--quality-changed-only' => true,
            '--no-provider' => $noProvider,
            '--keep-workspace' => (bool) $this->option('keep-workspace'),
            '--gate-profile' => $this->stringOption('gate-profile') ?: 'strict',
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '' && $value !== false);
    }

    /**
     * @return array<string,mixed>
     */
    private function reportArgs(): array
    {
        return array_filter([
            '--suite' => $this->suite(),
            '--limit' => $this->intOption('limit') ?: 20,
            '--output-dir' => $this->stringOption('output-dir'),
            '--markdown' => (bool) $this->option('markdown'),
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false);
    }

    private function guardProviderExecutionConfirmation(string $action): ?int
    {
        $missing = [];
        if (! (bool) $this->option('confirm-runbook-reviewed')) {
            $missing[] = 'confirm_runbook_reviewed';
        }
        if (! (bool) $this->option('confirm-provider-cost')) {
            $missing[] = 'confirm_provider_cost';
        }

        if ($missing === []) {
            return null;
        }

        $payload = [
            'schema_version' => 1,
            'error' => 'fair_claude_provider_execution_confirmation_required',
            'message' => 'Fair Claude provider execution requires explicit runbook review and provider cost acknowledgement.',
            'action' => $action,
            'missing_confirmations' => $missing,
            'required_flags' => [
                '--confirm-runbook-reviewed',
                '--confirm-provider-cost',
            ],
            'safety' => [
                'no_provider_call' => true,
                'no_benchmark_run_created' => true,
                'external_cost_possible' => true,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($payload['message']);
            $this->line('Required flags: '.implode(' ', $payload['required_flags']));
        }

        return self::FAILURE;
    }

    private function guardProviderExecutionPreflight(EngineeringBenchmarkService $benchmarks, string $action): ?int
    {
        $suite = $this->findSuite();
        $report = $suite
            ? $benchmarks->fairClaudeReportPayload($suite, [
                'limit' => $this->intOption('limit') ?: 20,
            ])
            : null;
        $runbook = $this->runbookPayload($report);
        $blocking = (array) ($runbook['start_blocking_reasons'] ?? []);

        if ($blocking === []) {
            return null;
        }

        $payload = [
            'schema_version' => 1,
            'error' => 'fair_claude_provider_execution_preflight_blocked',
            'message' => 'Fair Claude provider execution requires a ready runbook, clean isolated workspaces and valid preflight before spending provider tokens.',
            'action' => $action,
            'blocking_reasons' => $blocking,
            'runbook' => $runbook,
            'safety' => [
                'no_provider_call' => true,
                'no_benchmark_run_created' => true,
                'external_cost_possible' => true,
                'operator_confirmations_present_but_insufficient' => true,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($payload['message']);
            foreach ($blocking as $reason) {
                $this->warn('Blocking: '.(string) $reason);
            }
        }

        return self::FAILURE;
    }

    /**
     * @return array{laravel_runtime_detected:bool,artisan_exists:bool,composer_json_exists:bool,vendor_autoload_exists:bool,env_file_exists:bool,pint_exists:bool,pint_command:string}
     */
    private function emptyWorkspaceRuntimeState(): array
    {
        return [
            'laravel_runtime_detected' => false,
            'artisan_exists' => false,
            'composer_json_exists' => false,
            'vendor_autoload_exists' => false,
            'env_file_exists' => false,
            'pint_exists' => false,
            'pint_command' => 'php -d memory_limit=1024M vendor/bin/pint --test',
        ];
    }

    /**
     * @return array{laravel_runtime_detected:bool,artisan_exists:bool,composer_json_exists:bool,vendor_autoload_exists:bool,env_file_exists:bool,pint_exists:bool,pint_command:string}
     */
    private function workspaceRuntimeState(string $workspace): array
    {
        $artisanExists = is_file($workspace.DIRECTORY_SEPARATOR.'artisan');
        $composerJsonExists = is_file($workspace.DIRECTORY_SEPARATOR.'composer.json');

        return [
            'laravel_runtime_detected' => $artisanExists && $composerJsonExists,
            'artisan_exists' => $artisanExists,
            'composer_json_exists' => $composerJsonExists,
            'vendor_autoload_exists' => is_file($workspace.DIRECTORY_SEPARATOR.'vendor/autoload.php'),
            'env_file_exists' => is_file($workspace.DIRECTORY_SEPARATOR.'.env') || is_file($workspace.DIRECTORY_SEPARATOR.'.env.testing'),
            'pint_exists' => is_file($workspace.DIRECTORY_SEPARATOR.'vendor/bin/pint'),
            'pint_command' => 'php -d memory_limit=1024M vendor/bin/pint --test',
        ];
    }

    /**
     * @return array{is_git:bool,clean:?bool,dirty_files:array<int,string>,status:string}
     */
    private function gitWorkspaceState(string $workspace): array
    {
        $inside = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $workspace);
        $inside->setTimeout(5);
        $inside->run();

        if (! $inside->isSuccessful() || trim($inside->getOutput()) !== 'true') {
            return [
                'is_git' => false,
                'clean' => null,
                'dirty_files' => [],
                'status' => 'not_git_workspace',
            ];
        }

        $status = new Process(['git', 'status', '--porcelain'], $workspace);
        $status->setTimeout(10);
        $status->run();

        if (! $status->isSuccessful()) {
            return [
                'is_git' => true,
                'clean' => null,
                'dirty_files' => [],
                'status' => 'git_status_unavailable',
            ];
        }

        $dirtyFiles = collect(explode("\n", trim($status->getOutput())))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(function (string $line): string {
                $path = preg_replace('/^..\s*/', '', $line);

                return trim(is_string($path) && $path !== '' ? $path : $line);
            })
            ->values()
            ->all();

        return [
            'is_git' => true,
            'clean' => $dirtyFiles === [],
            'dirty_files' => $dirtyFiles,
            'status' => $dirtyFiles === [] ? 'clean' : 'dirty',
        ];
    }

    private function replay(EngineeringBenchmarkService $benchmarks): int
    {
        $run = $this->argument('run');
        if (! is_string($run) || trim($run) === '') {
            $run = $this->stringOption('run-id');
        }
        if (! is_string($run) || trim($run) === '') {
            if ((bool) $this->option('json')) {
                return $this->jsonError('run_id_required', 'replay exige o id do benchmark run.');
            }

            $this->error('replay exige o id do benchmark run.');

            return self::FAILURE;
        }

        $benchmarkRun = AtlasEngineeringBenchmarkRun::query()->find(trim($run));
        if (! $benchmarkRun) {
            if ((bool) $this->option('json')) {
                return $this->jsonError('benchmark_run_not_found', 'Benchmark run nao encontrado: '.trim($run), [
                    'run_id' => trim($run),
                ]);
            }

            $this->error('Benchmark run nao encontrado: '.trim($run));

            return self::FAILURE;
        }

        $payload = $benchmarks->replayManifestPayload($benchmarkRun);
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'available' ? self::SUCCESS : self::FAILURE;
        }

        return $this->callForwarded('atlas:engineering:benchmark:replay-manifest', [
            'run' => trim($run),
        ]);
    }

    private function unknownAction(string $action): int
    {
        if ((bool) $this->option('json')) {
            return $this->jsonError('unknown_action', "Acao Fair Claude desconhecida: {$action}", [
                'action' => $action,
                'supported_actions' => ['prepare', 'run', 'run-atlas', 'run-claude-code', 'report', 'readiness', 'runbook', 'doctor', 'replay', 'verify'],
            ]);
        }

        $this->error("Acao Fair Claude desconhecida: {$action}");
        $this->line('Use: prepare, run, run-atlas, run-claude-code, report, readiness, runbook, doctor, replay, verify ou triage-invalid-battery.');

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function jsonError(string $error, string $message, array $extra = []): int
    {
        $payload = [
            'schema_version' => 1,
            'error' => $error,
            'message' => $message,
            ...$extra,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }

    private function suite(): string
    {
        return $this->stringOption('suite') ?: 'atlas-core-smoke';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeAction(string $action): string
    {
        return match (str_replace('_', '-', strtolower(trim($action)))) {
            'prep', 'prepare-suite' => 'prepare',
            'atlas', 'run-atlas-arm' => 'run-atlas',
            'claude-code', 'baseline', 'run-baseline', 'run-claude-code-baseline' => 'run-claude-code',
            'scorecard', 'summary' => 'report',
            'ready', 'readiness-check' => 'readiness',
            'battery', 'battery-runbook', 'preflight', 'doctor' => 'runbook',
            'manifest', 'replay-manifest' => 'replay',
            default => str_replace('_', '-', strtolower(trim($action))),
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function validateFairModelLock(FairClaudePolicy $fairClaude): ?array
    {
        if ($this->fairModelOption() !== FairClaudePolicy::MODEL_LOCK) {
            return $fairClaude->violation(
                message: 'Fair Claude benchmark mode only accepts --model=opus.',
                details: ['model' => $this->stringOption('model')],
            );
        }

        $modelPolicy = $this->stringOption('model-policy') ?: 'fixed';
        if ($this->normalizeModelLock($modelPolicy) !== 'fixed') {
            return $fairClaude->violation(
                message: 'Fair Claude benchmark mode requires --model-policy=fixed.',
                details: ['model_policy' => $this->stringOption('model-policy')],
            );
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $violation
     */
    private function fairModeViolation(array $violation): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($violation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error((string) ($violation['message'] ?? 'Fair Claude benchmark mode violation.'));

        return self::FAILURE;
    }

    private function fairModelOption(): string
    {
        return $this->normalizeModelLock($this->stringOption('model') ?: FairClaudePolicy::MODEL_LOCK);
    }

    private function normalizeModelLock(string $value): string
    {
        return str_replace(['_', '.', ' '], '-', strtolower(trim($value)));
    }
}
