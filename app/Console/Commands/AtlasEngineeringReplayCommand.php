<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use Illuminate\Console\Command;
use RuntimeException;

class AtlasEngineeringReplayCommand extends Command
{
    protected $signature = 'atlas:engineering:replay
        {run : Source engineering run id to replay}
        {--workspace= : Workspace path. Defaults to current directory}
        {--attempt= : Replay a single source attempt id or attempt number}
        {--provider= : Override provider when --provider-replay is enabled}
        {--model= : Override model alias/id when --provider-replay is enabled}
        {--model-policy=fixed : fixed, auto, balanced, best-quality, fastest or cheapest model selection for provider replay}
        {--provider-replay : Re-run the provider instead of sensor-only replay}
        {--same-sandbox : Reuse the source run sandbox instead of defaulting to worktree}
        {--permission=auto : auto, read, write or danger}
        {--sandbox= : Override replay sandbox: workspace, worktree or docker}
        {--provider-runtime=host : host, docker or auto for atlas:cli:dev execution}
        {--max-attempts= : Override provider max attempts}
        {--test-command= : Explicit validation command}
        {--visual-e2e=auto : auto, off or required visual/E2E test discovery}
        {--quality-scan=off : off, auto or required Atlas quality/security scan sensor}
        {--quality-profile=auto : auto, fast, standard, release or deep profile for quality scan}
        {--quality-changed-only : Prefer changed files for quality scan tools that support explicit targets}
        {--harness-policy=auto : auto, off or strict harnessability autonomy policy}
        {--auto-test : Force validation sensors on}
        {--no-auto-test : Force validation sensors off}
        {--keep-workspace : Keep isolated execution workspace after the replay}
        {--apply-isolated-patch : Allow a resolved replay patch to apply back to the original workspace}
        {--json : Print machine-readable JSON}';

    protected $description = 'Replay an existing Atlas Engineering Harness run with a controlled, auditable strategy.';

    public function handle(EngineeringHarnessRunnerService $runner): int
    {
        $runId = trim((string) $this->argument('run'));
        $sourceRun = AtlasEngineeringRun::query()->with(['task', 'testRuns'])->find($runId);
        if (! $sourceRun) {
            $this->error("Engineering run nao encontrado: {$runId}");

            return self::FAILURE;
        }

        $options = array_filter([
            'workspace' => $this->workspace(),
            'provider' => is_string($this->option('provider')) ? $this->option('provider') : null,
            'model' => is_string($this->option('model')) ? $this->option('model') : null,
            'model_policy' => is_string($this->option('model-policy')) ? $this->option('model-policy') : 'fixed',
            'provider_replay' => (bool) $this->option('provider-replay'),
            'same_sandbox' => (bool) $this->option('same-sandbox'),
            'permission' => (string) $this->option('permission'),
            'sandbox' => is_string($this->option('sandbox')) && $this->option('sandbox') !== '' ? $this->option('sandbox') : null,
            'provider_runtime' => is_string($this->option('provider-runtime')) ? $this->option('provider-runtime') : 'host',
            'max_attempts' => is_numeric($this->option('max-attempts')) ? (int) $this->option('max-attempts') : null,
            'test_command' => is_string($this->option('test-command')) ? $this->option('test-command') : null,
            'visual_e2e' => is_string($this->option('visual-e2e')) ? $this->option('visual-e2e') : 'auto',
            'quality_scan' => is_string($this->option('quality-scan')) ? $this->option('quality-scan') : 'off',
            'quality_profile' => is_string($this->option('quality-profile')) ? $this->option('quality-profile') : 'auto',
            'quality_changed_only' => (bool) $this->option('quality-changed-only'),
            'harness_policy' => is_string($this->option('harness-policy')) ? $this->option('harness-policy') : 'auto',
            'auto_test' => $this->autoTestOption(),
            'keep_workspace' => (bool) $this->option('keep-workspace'),
            'apply_isolated_patch' => (bool) $this->option('apply-isolated-patch'),
        ], fn (mixed $value): bool => $value !== null);

        try {
            $attempt = $this->sourceAttempt($sourceRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = $attempt instanceof AtlasEngineeringRunAttempt
            ? $runner->replayAttempt($attempt, $options)
            : $runner->replay($sourceRun, $options);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->successExit($payload);
        }

        $this->render($payload);

        return $this->successExit($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $run = (array) ($payload['run'] ?? []);
        $replay = (array) ($run['replay'] ?? []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering Replay</>', (string) ($run['decision'] ?? 'unknown'));
        $this->components->twoColumnDetail('Source run', (string) ($replay['source_run_id'] ?? '-'));
        if (($replay['scope'] ?? null) === 'attempt') {
            $this->components->twoColumnDetail('Source attempt', (string) ($replay['source_attempt_number'] ?? $replay['source_attempt_id'] ?? '-'));
        }
        $this->components->twoColumnDetail('Replay run', (string) ($run['id'] ?? '-'));
        $this->components->twoColumnDetail('Mode', (string) ($replay['mode'] ?? '-'));
        $this->components->twoColumnDetail('Score', (string) ($run['score'] ?? '-'));
        $this->components->twoColumnDetail('Workspace', (string) data_get($run, 'workspace.mode', '-'));
    }

    private function autoTestOption(): ?bool
    {
        if ((bool) $this->option('auto-test')) {
            return true;
        }

        if ((bool) $this->option('no-auto-test')) {
            return false;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function successExit(array $payload): int
    {
        if (data_get($payload, 'run.replay') && data_get($payload, 'run.id')) {
            return self::SUCCESS;
        }

        $decision = (string) data_get($payload, 'run.decision', 'unresolved');

        return in_array($decision, ['resolved', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function sourceAttempt(AtlasEngineeringRun $sourceRun): ?AtlasEngineeringRunAttempt
    {
        $value = trim((string) ($this->option('attempt') ?? ''));
        if ($value === '') {
            return null;
        }

        $query = AtlasEngineeringRunAttempt::query()
            ->where('engineering_run_id', $sourceRun->id);

        if (is_numeric($value)) {
            $attempt = (clone $query)->where('attempt_number', (int) $value)->first();
            if ($attempt) {
                return $attempt;
            }
        }

        $attempt = $query->where('id', $value)->first();
        if (! $attempt) {
            throw new RuntimeException("Attempt nao encontrado para o run {$sourceRun->id}: {$value}");
        }

        return $attempt;
    }
}
