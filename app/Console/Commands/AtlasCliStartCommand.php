<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGitProjectRoot;
use App\Services\Ai\Cli\AtlasCliStartService;
use App\Services\Ai\Cli\DevProgressReporter;
use App\Support\AtlasSecurity;
use App\Support\TerminalMarkdownRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasCliStartCommand extends Command
{
    use ResolvesGitProjectRoot;

    protected $signature = 'atlas:cli:start
        {--workspace= : Workspace path. Defaults to current directory}
        {--full : Show extended context (decisions, open loops, all next steps)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Briefing focado para comecar o dia: contexto + UMA proxima acao concreta.';

    public function handle(AtlasCliStartService $start): int
    {
        $workspace = $this->workspace();
        $briefing = $start->briefing($workspace);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($briefing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderBriefing($briefing);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $briefing
     */
    private function renderBriefing(array $briefing): void
    {
        $thread = is_array($briefing['thread'] ?? null) ? $briefing['thread'] : null;
        $state = is_array($briefing['state'] ?? null) ? $briefing['state'] : null;
        $action = is_array($briefing['next_action'] ?? null) ? $briefing['next_action'] : null;

        $this->newLine();
        $this->line($this->dimItalic('  · '.$this->greeting()));
        $this->newLine();

        $this->writeSection('contexto');
        $this->line('  '.$this->dim('workspace · ').(string) $briefing['workspace']);

        if ($thread === null) {
            $this->line('  '.$this->dim('sem sessao recente neste workspace'));
        } else {
            $threadSummary = $this->shortId((string) ($thread['id'] ?? ''))
                .' · '.($thread['last_message_human'] ?? '?')
                .$this->phaseSuffix($state);
            $this->line('  '.$this->dim('thread ·    ').$threadSummary);

            if ($state && is_string($state['objective'] ?? null) && trim((string) $state['objective']) !== '') {
                $this->line('  '.$this->dim('objetivo ·  ').Str::limit((string) $state['objective'], 80));
            }

            $loopCount = is_array($state['open_loops'] ?? null) ? count((array) $state['open_loops']) : 0;
            $stepCount = is_array($state['next_steps'] ?? null) ? count((array) $state['next_steps']) : 0;
            if ($loopCount > 0 || $stepCount > 0) {
                $parts = [];
                if ($loopCount > 0) {
                    $parts[] = $loopCount.' open '.($loopCount === 1 ? 'loop' : 'loops');
                }
                if ($stepCount > 0) {
                    $parts[] = $stepCount.' '.($stepCount === 1 ? 'next step' : 'next steps');
                }
                $this->line('  '.$this->dim('estado ·    ').implode(' · ', $parts));
            }
        }

        if ((bool) $this->option('full') && $state) {
            $this->renderFullState($state);
        }

        $this->newLine();
        $this->writeSection('proxima acao');

        if (! is_array($action)) {
            $this->line('  '.$this->dim('sem acao definida'));
            $this->newLine();

            return;
        }

        $this->line('  '.$this->inline((string) $action['title']));
        if (is_string($action['reason'] ?? null) && (string) $action['reason'] !== '') {
            $this->line('  '.$this->dim('motivo · '.(string) $action['reason']));
        }

        $micro = (string) ($action['microaction'] ?? '');
        if ($micro !== '') {
            $this->newLine();
            $this->line('  '.$this->dimItalic('microacao'));
            $this->line('    '.$this->inline($micro));
        }

        $command = (string) ($action['command'] ?? '');
        if ($command !== '') {
            $this->newLine();
            $this->line('  '.$this->dimItalic('rode'));
            $this->line('    '.$this->bold($command));
        }
        $this->newLine();
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function renderFullState(array $state): void
    {
        $loops = collect((array) ($state['open_loops'] ?? []))
            ->map(fn (mixed $item): string => is_array($item)
                ? trim((string) ($item['text'] ?? $item['value'] ?? ''))
                : (is_string($item) ? trim($item) : ''))
            ->filter()
            ->take(5);

        $steps = collect((array) ($state['next_steps'] ?? []))
            ->map(fn (mixed $item): string => is_array($item)
                ? trim((string) ($item['text'] ?? $item['value'] ?? ''))
                : (is_string($item) ? trim($item) : ''))
            ->filter()
            ->take(5);

        if ($loops->isNotEmpty()) {
            $this->newLine();
            $this->writeSection('open loops');
            foreach ($loops as $loop) {
                $this->line('  - '.Str::limit($loop, 100));
            }
        }

        if ($steps->isNotEmpty()) {
            $this->newLine();
            $this->writeSection('next steps');
            foreach ($steps as $step) {
                $this->line('  - '.Str::limit($step, 100));
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $state
     */
    private function phaseSuffix(?array $state): string
    {
        $phase = is_array($state) && is_string($state['current_phase'] ?? null)
            ? trim((string) $state['current_phase'])
            : '';
        if ($phase === '') {
            return '';
        }
        $label = DevProgressReporter::labelFor($phase);
        if ($label === $phase) {
            return '';
        }

        return ' · fase '.$label;
    }

    private function writeSection(string $label): void
    {
        $this->line($this->ansi('1;36', $label));
        $this->line($this->ansi('90', str_repeat('-', max(2, strlen($label) + 4))));
    }

    private function inline(string $text): string
    {
        return app(TerminalMarkdownRenderer::class)->render($text, $this->output->isDecorated());
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('H');
        if ($hour < 6) {
            return 'madrugada';
        }
        if ($hour < 12) {
            return 'bom dia';
        }
        if ($hour < 18) {
            return 'boa tarde';
        }

        return 'boa noite';
    }

    private function shortId(string $id): string
    {
        return substr($id, 0, 8) ?: '-';
    }

    private function dim(string $text): string
    {
        return $this->ansi('2', $text);
    }

    private function dimItalic(string $text): string
    {
        return $this->ansi('2;3', $text);
    }

    private function bold(string $text): string
    {
        return $this->ansi('1', $text);
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
