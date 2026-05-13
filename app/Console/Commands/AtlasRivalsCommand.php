<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

class AtlasRivalsCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:rivals
        {action=readiness : readiness, run, quick, medium, full, run-atlas, run-claude-code, prepare, report, runbook, replay, verify or triage-invalid-battery}
        {run? : Benchmark run id for replay}
        {--profile=fair-claude : Rivals benchmark profile. Only fair-claude is supported.}
        {--preset= : Battery size: quick, medium or full}
        {--quick : Run a quick battery preset}
        {--medium : Run a medium battery preset}
        {--full : Run the full release battery preset}
        {--no-dashboard : Disable human dashboard before and after run}
        {--suite=atlas-fair-claude-v1 : Suite slug or id}
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
        {--claude-code-baseline-workspace= : Separate workspace for claude-code baseline run}
        {--claude-code-baseline-binary= : Claude Code CLI binary override}
        {--claude-code-baseline-timeout=900 : Seconds to wait for Claude Code baseline run}
        {--claude-code-baseline-validation-timeout=300 : Seconds to wait for baseline deterministic validation}
        {--max-attempts=3 : Maximum Atlas repair attempts}
        {--permission=auto : auto, read, write or danger}
        {--sandbox=workspace : workspace, worktree or docker}
        {--provider-runtime=host : host, docker or auto for atlas:cli:dev execution}
        {--gate-profile=strict : Release gate profile: release, smoke, strict, advisory or off}
        {--keep-workspace : Keep isolated execution workspace after the run for debugging}
        {--no-auto-test : Disable auto-test for run and run-atlas}
        {--confirm-runbook-reviewed : Confirm the Rivals runbook/preflight was reviewed before provider execution}
        {--confirm-provider-cost : Confirm external provider cost/token usage before provider execution}
        {--confirm-invalid-battery-quarantine : Confirm invalid historical battery should be quarantined without admitting score}
        {--reason= : Human triage reason for triage-invalid-battery}
        {--run-id= : Benchmark run id for replay; alias for the positional run argument}
        {--output-dir= : Write or verify report.json, evidence.json, claim.md and manifest.json for report/readiness/verify}
        {--markdown : Print audit-ready Markdown for report/readiness}
        {--json : Print machine-readable JSON}';

    protected $description = 'Atlas Rivals: prove Atlas outperforms Claude Code CLI on your codebase.';

    private const SUPPORTED_PROFILES = ['fair-claude'];

    private const RUN_ACTIONS = ['run', 'run-atlas', 'run-claude-code'];

    /**
     * @var array<string,array<string,mixed>>
     */
    private const PRESETS = [
        'quick' => [
            'label' => 'quick',
            'description' => '1 curated release case, fast feedback, lowest cost.',
            'limit' => 1,
            'tier' => 'release',
            'curation_status' => 'curated',
            'max_attempts' => 1,
            'baseline_timeout' => 1200,
            'baseline_validation_timeout' => 300,
            'gate_profile' => 'strict',
            'estimated_time' => '10-25 min',
        ],
        'medium' => [
            'label' => 'medium',
            'description' => '3 curated release cases, useful signal without full battery cost.',
            'limit' => 3,
            'tier' => 'release',
            'curation_status' => 'curated',
            'max_attempts' => 2,
            'baseline_timeout' => 1800,
            'baseline_validation_timeout' => 600,
            'gate_profile' => 'strict',
            'estimated_time' => '30-75 min',
        ],
        'full' => [
            'label' => 'full',
            'description' => 'Full curated release corpus, strongest evidence for claims.',
            'limit' => null,
            'tier' => 'release',
            'curation_status' => 'curated',
            'max_attempts' => 3,
            'baseline_timeout' => 3600,
            'baseline_validation_timeout' => 900,
            'gate_profile' => 'strict',
            'estimated_time' => '90-180+ min',
        ],
    ];

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $profile = is_string($this->option('profile')) ? trim((string) $this->option('profile')) : 'fair-claude';

        if (! in_array($profile, self::SUPPORTED_PROFILES, true)) {
            $violation = [
                'error' => 'unsupported_profile',
                'message' => "Unsupported rivals profile: {$profile}. Supported: ".implode(', ', self::SUPPORTED_PROFILES).'.',
                'supported_profiles' => self::SUPPORTED_PROFILES,
                'requested_profile' => $profile,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($violation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->error($violation['message']);

            return self::FAILURE;
        }

        $action = $this->normalizedAction();
        $preset = $this->selectedPreset($action);
        if ($preset === false) {
            return self::FAILURE;
        }

        if ($action === 'runbook') {
            return $this->runbook($preset);
        }

        if ($this->shouldRenderDashboard($action)) {
            $this->renderStartDashboard($benchmarks, $action, $preset);
        }

        $exitCode = $this->call('atlas:engineering:benchmark:claude-fair', $this->forwardArgs($action, $preset));

        if ($this->shouldRenderDashboard($action)) {
            $this->renderFinishDashboard($benchmarks, $exitCode);
        }

        return $exitCode;
    }

    /**
     * @return array<string,mixed>
     */
    private function forwardArgs(string $action, ?string $preset): array
    {
        return array_filter([
            'action' => $action,
            'run' => $this->argument('run'),
            '--suite' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
            '--workspace' => $this->stringOption('workspace'),
            '--case' => (array) $this->option('case'),
            '--tag' => (array) $this->option('tag'),
            '--limit' => $this->presetIntOption('limit', $preset),
            '--tier' => $this->presetStringOption('tier', $preset),
            '--domain' => $this->stringOption('domain'),
            '--risk' => $this->stringOption('risk'),
            '--curation-status' => $this->presetStringOption('curation-status', $preset, 'curation_status'),
            '--model' => $this->stringOption('model') ?: 'opus',
            '--model-policy' => $this->stringOption('model-policy') ?: 'fixed',
            '--test-command' => $this->stringOption('test-command'),
            '--claude-code-baseline-workspace' => $this->stringOption('claude-code-baseline-workspace'),
            '--claude-code-baseline-binary' => $this->stringOption('claude-code-baseline-binary'),
            '--claude-code-baseline-timeout' => $this->presetIntOption('claude-code-baseline-timeout', $preset, 'baseline_timeout') ?: 900,
            '--claude-code-baseline-validation-timeout' => $this->presetIntOption('claude-code-baseline-validation-timeout', $preset, 'baseline_validation_timeout') ?: 300,
            '--max-attempts' => $this->presetIntOption('max-attempts', $preset, 'max_attempts') ?: 3,
            '--permission' => $this->stringOption('permission') ?: 'auto',
            '--sandbox' => $this->stringOption('sandbox') ?: 'workspace',
            '--provider-runtime' => $this->stringOption('provider-runtime') ?: 'host',
            '--gate-profile' => $this->presetStringOption('gate-profile', $preset, 'gate_profile') ?: 'strict',
            '--keep-workspace' => (bool) $this->option('keep-workspace') ?: null,
            '--no-auto-test' => (bool) $this->option('no-auto-test') ?: null,
            '--confirm-runbook-reviewed' => (bool) $this->option('confirm-runbook-reviewed') ?: null,
            '--confirm-provider-cost' => (bool) $this->option('confirm-provider-cost') ?: null,
            '--confirm-invalid-battery-quarantine' => (bool) $this->option('confirm-invalid-battery-quarantine') ?: null,
            '--reason' => $this->stringOption('reason'),
            '--run-id' => $this->stringOption('run-id'),
            '--output-dir' => $this->stringOption('output-dir'),
            '--markdown' => (bool) $this->option('markdown') ?: null,
            '--json' => (bool) $this->option('json') ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
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

    private function normalizedAction(): string
    {
        $action = str_replace('_', '-', strtolower(trim((string) $this->argument('action'))));

        return match ($action) {
            'q', 'quick', 'smoke' => 'run',
            'm', 'medium' => 'run',
            'f', 'full', 'complete', 'release' => 'run',
            default => $action,
        };
    }

    private function selectedPreset(string $action): string|false|null
    {
        $rawAction = str_replace('_', '-', strtolower(trim((string) $this->argument('action'))));
        $preset = $this->stringOption('preset');
        if ((bool) $this->option('quick') || in_array($rawAction, ['q', 'quick', 'smoke'], true)) {
            $preset = 'quick';
        } elseif ((bool) $this->option('medium') || in_array($rawAction, ['m', 'medium'], true)) {
            $preset = 'medium';
        } elseif ((bool) $this->option('full') || in_array($rawAction, ['f', 'full', 'complete', 'release'], true)) {
            $preset = 'full';
        }

        if ($preset === null && in_array($action, self::RUN_ACTIONS, true)) {
            $preset = 'quick';
        }
        if ($preset === null) {
            return null;
        }

        $preset = str_replace('_', '-', strtolower(trim($preset)));
        if (! array_key_exists($preset, self::PRESETS)) {
            $message = "Unsupported rivals preset: {$preset}. Supported: quick, medium, full.";
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'error' => 'unsupported_preset',
                    'message' => $message,
                    'supported_presets' => array_keys(self::PRESETS),
                    'requested_preset' => $preset,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($message);
            }

            return false;
        }

        return $preset;
    }

    private function presetStringOption(string $option, ?string $preset, ?string $presetKey = null): ?string
    {
        $value = $this->stringOption($option);
        if ($value !== null || $preset === null || $this->optionWasProvided($option)) {
            return $value;
        }

        $presetValue = self::PRESETS[$preset][$presetKey ?: str_replace('-', '_', $option)] ?? null;

        return is_string($presetValue) && $presetValue !== '' ? $presetValue : null;
    }

    private function presetIntOption(string $option, ?string $preset, ?string $presetKey = null): ?int
    {
        $value = $this->intOption($option);
        if ($this->optionWasProvided($option) || $preset === null) {
            return $value;
        }

        $presetValue = self::PRESETS[$preset][$presetKey ?: str_replace('-', '_', $option)] ?? null;

        return is_numeric($presetValue) ? (int) $presetValue : $value;
    }

    private function optionWasProvided(string $name): bool
    {
        $needle = '--'.$name;
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === $needle || str_starts_with((string) $arg, $needle.'=')) {
                return true;
            }
        }

        return false;
    }

    private function shouldRenderDashboard(string $action): bool
    {
        return in_array($action, self::RUN_ACTIONS, true)
            && ! (bool) $this->option('json')
            && ! (bool) $this->option('no-dashboard');
    }

    private function runbook(?string $preset): int
    {
        $args = $this->forwardArgs('runbook', $preset);
        $args['--json'] = true;
        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', $args, $buffer);
        $payload = json_decode($buffer->fetch(), true);
        if (! is_array($payload)) {
            $payload = [
                'schema_version' => 1,
                'kind' => 'fair_claude_battery_runbook',
                'generated_at' => now()->toJSON(),
                'start_status' => 'blocked',
                'ready_to_start_battery' => false,
                'start_blocking_reasons' => ['fair_claude_runbook_unavailable'],
                'suite' => ['slug' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1'],
                'preflight' => [],
                'protocol' => [
                    'atlas_provider_lock' => 'claude_cli',
                    'atlas_model_lock' => 'opus',
                    'baseline_provider_lock' => 'claude_code_cli',
                    'baseline_model_lock' => 'opus',
                ],
            ];
        }

        $payload['kind'] = 'atlas_rivals_runbook';
        $payload['recommended_command'] = 'atlas rivals run';
        $payload['start_blocking_reasons'] = collect((array) ($payload['start_blocking_reasons'] ?? []))
            ->reject(fn (mixed $reason): bool => $reason === 'claude_code_baseline_workspace_missing_or_unreadable')
            ->values()
            ->all();
        $payload['start_status'] = $payload['start_blocking_reasons'] === [] ? 'ready_to_start' : 'blocked';
        $payload['ready_to_start_battery'] = $payload['start_blocking_reasons'] === [];
        $payload['preflight']['baseline_workspace_auto_prepared_when_missing'] = true;
        $payload['commands'] = [
            'quick' => 'atlas rivals run --confirm-runbook-reviewed --confirm-provider-cost',
            'medium' => 'atlas rivals run --medium --confirm-runbook-reviewed --confirm-provider-cost',
            'full' => 'atlas rivals run --full --confirm-runbook-reviewed --confirm-provider-cost',
            'status' => 'atlas rivals readiness',
            'report' => 'atlas rivals report',
            'export' => 'atlas rivals report --output-dir=atlas-rivals-report',
            'verify_export' => 'atlas rivals verify --output-dir=atlas-rivals-report',
            'advanced_protocol_runbook' => 'atlas benchmark claude-fair runbook --json',
        ];
        $payload['presets'] = self::PRESETS;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return empty($payload['start_blocking_reasons'] ?? []) ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Atlas Rivals Runbook</>');
        $this->components->twoColumnDetail('Status', (string) ($payload['start_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Recommended', (string) $payload['recommended_command']);
        $this->components->twoColumnDetail('Quick', (string) data_get($payload, 'commands.quick'));
        $this->components->twoColumnDetail('Medium', (string) data_get($payload, 'commands.medium'));
        $this->components->twoColumnDetail('Full', (string) data_get($payload, 'commands.full'));
        $this->components->twoColumnDetail('Report', (string) data_get($payload, 'commands.report'));
        foreach ((array) ($payload['start_blocking_reasons'] ?? []) as $reason) {
            $this->warn('Blocking: '.(string) $reason);
        }

        return empty($payload['start_blocking_reasons'] ?? []) ? self::SUCCESS : self::FAILURE;
    }

    private function renderStartDashboard(EngineeringBenchmarkService $benchmarks, string $action, ?string $preset): void
    {
        $presetConfig = $preset !== null ? self::PRESETS[$preset] : null;
        $report = $this->currentReport($benchmarks);

        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Atlas Rivals</> Fair Claude battery');
        $this->components->twoColumnDetail('Command', 'atlas rivals '.$action);
        $this->components->twoColumnDetail('Preset', $presetConfig ? (string) $presetConfig['label'] : 'custom');
        $this->components->twoColumnDetail('Estimated time', $presetConfig ? (string) $presetConfig['estimated_time'] : 'depends on filters');
        $this->components->twoColumnDetail('Atlas arm', 'claude_cli / opus');
        $this->components->twoColumnDetail('Baseline arm', 'Claude Code CLI / opus');
        $this->components->twoColumnDetail('Workspace', $this->stringOption('workspace') ?: getcwd() ?: '-');
        if ($presetConfig) {
            $this->components->twoColumnDetail('Scope', $this->presetScopeLabel($preset));
        }
        $this->renderScoreboard($report, 'Current scoreboard');
        $this->line('<fg=yellow>Running paired benchmark now. Long Claude calls may be quiet; final scorecard prints when it finishes.</>');
    }

    private function renderFinishDashboard(EngineeringBenchmarkService $benchmarks, int $exitCode): void
    {
        $report = $this->currentReport($benchmarks);
        $this->newLine();
        $this->line($exitCode === self::SUCCESS
            ? '<fg=green;options=bold>Atlas Rivals finished</>'
            : '<fg=red;options=bold>Atlas Rivals finished with failures</>');
        $this->renderScoreboard($report, 'Final scoreboard');
        $this->renderCaseDiagnostics($report);
        $this->line('Next: atlas rivals report');
        $this->line('Export: atlas rivals report --output-dir=atlas-rivals-report');
        $this->line('Verify: atlas rivals verify --output-dir=atlas-rivals-report');
    }

    private function renderCaseDiagnostics(?array $report): void
    {
        $cases = (array) ($report['case_comparisons'] ?? []);
        if ($cases === []) {
            return;
        }

        $failing = collect($cases)
            ->filter(function (mixed $case): bool {
                if (! is_array($case)) {
                    return false;
                }
                $atlasOk = (bool) data_get($case, 'atlas.passed', false) && (bool) data_get($case, 'atlas.verified', false);
                $baselineOk = (bool) data_get($case, 'claude_code_baseline.passed', false) && (bool) data_get($case, 'claude_code_baseline.verified', false);

                return ! $atlasOk || ! $baselineOk;
            })
            ->take(5);

        if ($failing->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<fg=yellow;options=bold>Per-case diagnostics</>');
        foreach ($failing as $case) {
            $code = (string) (data_get($case, 'case_code') ?: data_get($case, 'case_id') ?: 'unknown');
            $this->line('  <fg=cyan>'.$code.'</>');

            $atlasScore = data_get($case, 'atlas.score');
            $atlasDur = $this->formatDuration(data_get($case, 'atlas.duration_ms'));
            $atlasState = (bool) data_get($case, 'atlas.passed', false) ? 'passed' : 'failed';
            $this->line(sprintf('    Atlas: %s · score=%s · duration=%s', $atlasState, $atlasScore ?? '-', $atlasDur));

            $baseExecuted = (bool) data_get($case, 'claude_code_baseline.executed', false);
            if (! $baseExecuted) {
                $this->line('    Claude Code: not executed');
            } else {
                $baseState = (bool) data_get($case, 'claude_code_baseline.passed', false) ? 'passed' : 'failed';
                $baseScore = data_get($case, 'claude_code_baseline.score');
                $baseDur = $this->formatDuration(data_get($case, 'claude_code_baseline.duration_ms'));
                $errCode = (string) (data_get($case, 'claude_code_baseline.error_code') ?: '');
                $errSuffix = $errCode !== '' ? ' · '.$errCode : '';
                $this->line(sprintf('    Claude Code: %s · score=%s · duration=%s%s', $baseState, $baseScore ?? '-', $baseDur, $errSuffix));

                $gateStatus = (string) (data_get($case, 'claude_code_baseline.gate_status') ?: '');
                $gateReason = (string) (data_get($case, 'claude_code_baseline.gate_reason') ?: '');
                $gateStderr = trim((string) (data_get($case, 'claude_code_baseline.gate_stderr_excerpt') ?: ''));
                if ($gateStatus !== '' && $gateStatus !== 'passed') {
                    $detail = $gateReason !== '' ? ' ('.$gateReason.')' : '';
                    $this->line('    Gate: '.$gateStatus.$detail);
                    if ($gateStderr !== '') {
                        $firstLine = trim((string) (explode("\n", $gateStderr)[0] ?? ''));
                        if ($firstLine !== '') {
                            $this->line('    Gate stderr: '.$this->truncate($firstLine, 200));
                        }
                    }
                }
            }

            $blockers = collect((array) data_get($case, 'blocking_reasons', []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                ->take(4)
                ->values()
                ->all();
            if ($blockers !== []) {
                $this->line('    Blockers: '.implode(', ', $blockers));
            }
        }
    }

    private function formatDuration(mixed $ms): string
    {
        if (! is_numeric($ms)) {
            return '-';
        }
        $ms = (int) $ms;
        if ($ms >= 60_000) {
            return round($ms / 60_000, 1).'m';
        }
        if ($ms >= 1000) {
            return round($ms / 1000, 1).'s';
        }

        return $ms.'ms';
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function currentReport(EngineeringBenchmarkService $benchmarks): ?array
    {
        $suite = $this->findSuite();
        if (! $suite) {
            return null;
        }

        return $benchmarks->fairClaudeReportPayload($suite, [
            'limit' => $this->intOption('limit') ?: 20,
        ]);
    }

    private function findSuite(): ?AtlasEngineeringBenchmarkSuite
    {
        $suiteRef = $this->stringOption('suite') ?: 'atlas-fair-claude-v1';
        $query = AtlasEngineeringBenchmarkSuite::query()->where('slug', $suiteRef);
        if (Str::isUuid($suiteRef)) {
            $query->orWhere('id', $suiteRef);
        }

        return $query->first();
    }

    private function renderScoreboard(?array $report, string $title): void
    {
        if ($report === null) {
            $this->components->twoColumnDetail($title, 'suite not prepared');

            return;
        }

        $readiness = (array) ($report['readiness'] ?? []);
        $paired = (array) ($report['paired_scorecard'] ?? []);
        $atlasWins = (int) ($readiness['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($readiness['claude_code_baseline_win_count'] ?? 0);
        $ties = (int) ($readiness['tie_count'] ?? 0);
        $comparable = (int) ($readiness['comparable_count'] ?? 0);

        $this->components->twoColumnDetail($title, "Atlas {$atlasWins} x {$baselineWins} Claude Code · ties {$ties}");
        $this->components->twoColumnDetail('Comparable cases', (string) $comparable);
        $this->components->twoColumnDetail('Protocol validity', $this->percentLabel($paired['protocol_validity_rate'] ?? null));
        $this->components->twoColumnDetail('Pass without human', $this->percentLabel($paired['pass_without_human_rate_medium_hard'] ?? $paired['pass_without_human_rate'] ?? null));
        $this->components->twoColumnDetail('Readiness', (string) ($readiness['status'] ?? 'unknown'));
        foreach (array_slice((array) ($readiness['blocking_reasons'] ?? []), 0, 3) as $reason) {
            $this->warn('Blocking: '.(string) $reason);
        }
    }

    private function presetScopeLabel(string $preset): string
    {
        $config = self::PRESETS[$preset];
        $limit = $config['limit'] ?? null;

        return ($limit === null ? 'all' : (string) $limit)
            .' release curated case(s), max attempts '.(string) $config['max_attempts'];
    }

    private function percentLabel(mixed $value): string
    {
        return is_numeric($value) ? ((string) round((float) $value, 2)).'%' : '-';
    }
}
