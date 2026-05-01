<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Cli\DevProgressReporter;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AtlasCliContinueCommand extends Command
{
    protected $signature = 'atlas:cli:continue
        {--workspace= : Workspace path. Defaults to current directory}
        {--thread= : Specific thread id to resume}
        {--complete : Force --complete on the resumed dev run}
        {--dry-run : Show what would be resumed without re-executing}
        {--json : Print machine-readable JSON}';

    protected $description = 'Resume the most recent unfinished Atlas dev workflow in this workspace.';

    public function handle(AtlasCliSessionService $sessions): int
    {
        $workspace = $this->workspace();
        $threadId = is_string($this->option('thread')) ? trim((string) $this->option('thread')) : null;
        $resume = $sessions->findResumablePlan($workspace, $threadId !== '' ? $threadId : null);
        $json = (bool) $this->option('json');

        if (! $resume) {
            if ($json) {
                $this->line(json_encode([
                    'ok' => true,
                    'resumed' => false,
                    'workspace' => $workspace,
                    'message' => 'Nada a retomar neste workspace.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->line($this->ansi('2;3', '  · nada a retomar'));
            $this->newLine();
            $this->line('  inicie com: '.$this->ansi('1', 'atlas dev "<descreva a tarefa>"'));

            return self::SUCCESS;
        }

        $operatorOptions = (array) $resume['operator_options'];
        $forceComplete = (bool) $this->option('complete');
        $complete = $forceComplete || (bool) ($operatorOptions['complete'] ?? false);

        $command = $this->buildResumeCommand($resume, $operatorOptions, $complete);

        if ($json) {
            $this->line(json_encode([
                'ok' => true,
                'resumed' => true,
                'workspace' => $workspace,
                'plan_id' => $resume['plan_id'],
                'task' => $resume['task'],
                'thread_id' => $resume['thread_id'],
                'reason' => $resume['reason'],
                'command' => AtlasSecurity::commandLineForDisplay($command),
                'dry_run' => (bool) $this->option('dry-run'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $phase = (string) data_get($resume, 'reason', '');
        $phaseLabel = $phase !== '' && DevProgressReporter::labelFor($phase) !== $phase
            ? DevProgressReporter::labelFor($phase)
            : $phase;

        $this->newLine();
        $this->line($this->ansi('2;3', '  · retomando').' '.$this->ansi('2', $phaseLabel ?: 'plano anterior'));
        $this->line($this->ansi('2', '  · plano '.substr($resume['plan_id'], 0, 8).' · '.\Illuminate\Support\Str::limit($resume['task'], 80)));
        $this->newLine();

        if ((bool) $this->option('dry-run')) {
            $this->line('  '.AtlasSecurity::commandLineForDisplay($command));

            return self::SUCCESS;
        }

        $process = new Process(
            $command,
            $resume['workspace'],
            AtlasSecurity::processEnv(profile: 'internal'),
        );
        $process->setTimeout(0);
        $process->setIdleTimeout(null);
        $process->setTty(Process::isTtySupported() && $this->output->isDecorated());

        $exit = $process->run(function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                fwrite(STDERR, $buffer);

                return;
            }
            $this->output->write($buffer);
        });

        return is_int($exit) ? $exit : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $resume
     * @param  array<string,mixed>  $operatorOptions
     * @return array<int,string>
     */
    private function buildResumeCommand(array $resume, array $operatorOptions, bool $complete): array
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'atlas:cli:dev',
            (string) $resume['task'],
            '--workspace='.$resume['workspace'],
            '--resume='.$resume['plan_id'],
        ];

        if ($complete) {
            $command[] = '--complete';
        }
        $maxIterations = (int) ($operatorOptions['max_iterations'] ?? 0);
        if ($maxIterations > 0) {
            $command[] = '--max-iterations='.$maxIterations;
        }
        if (! empty($operatorOptions['provider'])) {
            $command[] = '--provider='.(string) $operatorOptions['provider'];
        }
        if (! empty($operatorOptions['critical'])) {
            $command[] = '--critical';
        }
        if (! empty($operatorOptions['allow_write'])) {
            $command[] = '--allow-write';
        }
        if (! empty($operatorOptions['allow_danger'])) {
            $command[] = '--dangerously-allow-all';
        }
        if (! empty($operatorOptions['allow_unsandboxed'])) {
            $command[] = '--allow-unsandboxed';
        }
        if (! empty($operatorOptions['auto_test'])) {
            $command[] = '--auto-test';
        }
        if (! empty($operatorOptions['no_stream'])) {
            $command[] = '--no-stream';
        }
        $permission = (string) ($operatorOptions['permission'] ?? '');
        if ($permission !== '' && in_array($permission, ['read', 'write', 'danger'], true)) {
            $command[] = '--permission='.$permission;
        }
        foreach ((array) ($operatorOptions['skills'] ?? []) as $skill) {
            if (is_scalar($skill) && trim((string) $skill) !== '') {
                $command[] = '--skill='.trim((string) $skill);
            }
        }

        return $command;
    }

    private function ansi(string $code, string $text): string
    {
        if (! $this->output->isDecorated()) {
            return $text;
        }

        return "\033[".$code.'m'.$text."\033[0m";
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        if (! $resolved || ! is_dir($resolved)) {
            return $workspace;
        }

        return $this->projectRootFor($resolved) ?: $resolved;
    }

    private function projectRootFor(string $workspace): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--show-toplevel'], $workspace, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(3);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $root = trim(AtlasSecurity::redactString($process->getOutput()));

        return $root !== '' && is_dir($root) ? $root : null;
    }
}
