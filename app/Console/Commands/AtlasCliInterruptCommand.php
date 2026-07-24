<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGitProjectRoot;
use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Cli\DevProgressReporter;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;

class AtlasCliInterruptCommand extends Command
{
    use ResolvesGitProjectRoot;

    protected $signature = 'atlas:cli:interrupt
        {--workspace= : Workspace path. Defaults to current directory}
        {--thread= : Specific thread id to interrupt}
        {--json : Print machine-readable JSON}';

    protected $description = 'Cancel the active Atlas trace running for this workspace.';

    public function handle(AtlasCliSessionService $sessions): int
    {
        $workspace = $this->workspace();
        $threadId = is_string($this->option('thread')) ? trim((string) $this->option('thread')) : null;

        $result = $sessions->cancelActiveTrace($workspace, $threadId !== '' ? $threadId : null);
        $json = (bool) $this->option('json');

        if (! $result['cancelled']) {
            if ($json) {
                $this->line(json_encode([
                    'ok' => true,
                    'cancelled' => false,
                    'workspace' => $workspace,
                    'message' => 'Nenhuma execucao Atlas ativa neste workspace.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->writeAtlas('  · ', '2;3', 'sem execucao ativa', '2');

            return self::SUCCESS;
        }

        if ($json) {
            $this->line(json_encode([
                'ok' => true,
                'cancelled' => true,
                'workspace' => $workspace,
                'trace_id' => $result['trace_id'],
                'thread_id' => $result['thread_id'],
                'provider' => $result['provider'],
                'phase' => $result['phase'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $traceShort = substr((string) $result['trace_id'], 0, 8);
        $phaseLabel = $result['phase'] ? DevProgressReporter::labelFor((string) $result['phase']) : null;

        $this->writeAtlas('  · ', '2;3', 'interrompido', '2', 'trace '.$traceShort.($phaseLabel ? ' · '.$phaseLabel : '').($result['provider'] ? ' · '.$result['provider'] : ''));
        $this->newLine();
        $this->line('  para retomar: '.$this->ansi('1', 'atlas continue'));
        $this->line('  para abrir a thread: '.$this->ansi('1', 'atlas chat'));

        return self::SUCCESS;
    }

    private function writeAtlas(string $prefix, string $labelStyle, string $label, string $tailStyle = '2', string $tail = ''): void
    {
        $line = $this->ansi($labelStyle, $prefix.$label);
        if ($tail !== '') {
            $line .= ' '.$this->ansi($tailStyle, $tail);
        }
        $this->line($line);
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

}
