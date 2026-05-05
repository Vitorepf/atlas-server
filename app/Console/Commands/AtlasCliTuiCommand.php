<?php

namespace App\Console\Commands;

use App\Models\AiMemoryDelta;
use App\Models\AiPermissionSession;
use App\Models\AiTrace;
use App\Services\Ai\Cli\AtlasCliDashboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasCliTuiCommand extends Command
{
    protected $signature = 'atlas:cli:tui
        {--workspace= : Workspace path. Defaults to the current directory}
        {--refresh-index : Refresh workspace profile cache}
        {--limit=8 : Maximum rows per panel}
        {--interval=1 : Refresh interval in seconds}
        {--classic : Use the classic non-interactive dashboard}
        {--once : Render one TUI frame and exit}';

    protected $description = 'Open the interactive Atlas CLI terminal cockpit.';

    /** @var array<string,array{number:int,key:string,title:string}> */
    private array $panels = [
        'conversation' => ['number' => 1, 'key' => 's', 'title' => 'Conversa'],
        'plan' => ['number' => 2, 'key' => 'p', 'title' => 'Plano'],
        'diff' => ['number' => 3, 'key' => 'd', 'title' => 'Diff'],
        'tests' => ['number' => 4, 'key' => 't', 'title' => 'Testes'],
        'permissions' => ['number' => 5, 'key' => '', 'title' => 'Permissoes'],
        'memory' => ['number' => 6, 'key' => '', 'title' => 'Memoria'],
        'traces' => ['number' => 7, 'key' => '', 'title' => 'Traces'],
    ];

    public function handle(AtlasCliDashboardService $dashboard): int
    {
        if ((bool) $this->option('classic')) {
            return $this->call('atlas:cli:dashboard', [
                '--workspace' => $this->workspace(),
                '--refresh-index' => (bool) $this->option('refresh-index'),
                '--limit' => (int) $this->option('limit'),
            ]);
        }

        $workspace = $this->workspace();
        $limit = max(1, min(25, (int) $this->option('limit')));
        $selected = 'conversation';
        $status = 'r refresh | 1-7/s/p/d/t troca painel | a aprova write 10m | x revoga | c compacta | m handoff | q sair';
        $data = $dashboard->build($workspace, (bool) $this->option('refresh-index'), $limit);

        if ((bool) $this->option('once') || ! $this->isInteractiveTty()) {
            $this->output->write($this->renderFrame($data, $selected, $status, clear: false));

            return self::SUCCESS;
        }

        $restore = $this->enableRawMode();

        try {
            while (true) {
                $data = $dashboard->build($workspace, false, $limit);
                $this->output->write($this->renderFrame($data, $selected, $status, clear: true));

                $key = $this->readKey((float) $this->option('interval'));
                if ($key === null) {
                    continue;
                }

                if ($key === 'q' || $key === "\003") {
                    break;
                }

                [$selected, $status] = $this->handleKey($key, $selected, $workspace, $data);
            }
        } finally {
            $restore();
            $this->output->write("\033[?25h\033[0m\n");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function handleKey(string $key, string $selected, string $workspace, array $data): array
    {
        $panel = $this->panelForKey($key);
        if ($panel) {
            return [$panel, 'Painel: '.$this->panels[$panel]['title']];
        }

        return match ($key) {
            'r' => [$selected, 'Atualizado em '.now()->format('H:i:s')],
            'a' => ['permissions', $this->approveWriteSession($workspace)],
            'x' => ['permissions', $this->revokePermissionSessions($workspace)],
            'c' => ['memory', $this->compactActiveThread($workspace)],
            'm' => ['traces', $this->handoffProvider($workspace, $data)],
            default => [$selected, 'Atalho sem acao: '.$this->printableKey($key)],
        };
    }

    private function panelForKey(string $key): ?string
    {
        foreach ($this->panels as $slug => $panel) {
            if ($key === (string) $panel['number'] || ($panel['key'] !== '' && $key === $panel['key'])) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderFrame(array $data, string $selected, string $status, bool $clear): string
    {
        $workspace = (array) $data['workspace'];
        $atlasAi = (array) $data['atlas_ai'];
        $thread = is_array($atlasAi['active_thread'] ?? null) ? (array) $atlasAi['active_thread'] : null;
        $providers = (array) ($data['providers'] ?? []);

        $lines = [];
        if ($clear) {
            $lines[] = "\033[2J\033[H\033[?25l";
        }

        $lines[] = $this->accent('Atlas CLI TUI').'  '.$this->muted((string) $data['generated_at']);
        $lines[] = 'Workspace  '.$this->clip((string) ($workspace['path'] ?? '-'), 90);
        $lines[] = 'Repo       '.$this->clip((string) ($workspace['branch'] ?? '-').' @ '.(string) ($workspace['head'] ?? '-').' | dirty '.(string) ($workspace['dirty_count'] ?? 0), 90);
        $lines[] = 'Thread     '.$this->clip($thread ? ((string) $thread['title']).' | '.((string) ($thread['provider'] ?? '-')) : 'nenhuma thread ativa', 90);
        $lines[] = str_repeat('-', 96);
        $lines[] = $this->tabs($selected);
        $lines[] = str_repeat('-', 96);

        foreach ($this->panelLines($selected, $data, $workspace, $thread, $providers) as $line) {
            $lines[] = $line;
        }

        $lines[] = str_repeat('-', 96);
        $lines[] = $this->muted($status);
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $workspace
     * @param  array<string,mixed>|null  $thread
     * @param  array<int,array<string,mixed>>  $providers
     * @return array<int,string>
     */
    private function panelLines(string $selected, array $data, array $workspace, ?array $thread, array $providers): array
    {
        return match ($selected) {
            'plan' => $this->planPanel($thread),
            'diff' => $this->diffPanel((string) ($workspace['repo_root'] ?: $workspace['path'])),
            'tests' => $this->testsPanel($data),
            'permissions' => $this->permissionsPanel((string) ($workspace['path'] ?? $this->workspace())),
            'memory' => $this->memoryPanel(),
            'traces' => $this->tracesPanel(),
            default => $this->conversationPanel($thread, $providers, $data),
        };
    }

    /**
     * @param  array<string,mixed>|null  $thread
     * @param  array<int,array<string,mixed>>  $providers
     * @param  array<string,mixed>  $data
     * @return array<int,string>
     */
    private function conversationPanel(?array $thread, array $providers, array $data): array
    {
        $atlasAi = (array) $data['atlas_ai'];
        $traces = (array) ($atlasAi['traces'] ?? []);
        $jobs = (array) ($atlasAi['jobs'] ?? []);
        $lines = [$this->title('1 Conversa')];

        if (! $thread) {
            $lines[] = 'Nenhuma conversa ativa neste workspace.';
        } else {
            $state = is_array($thread['state'] ?? null) ? (array) $thread['state'] : [];
            $session = is_array($thread['session'] ?? null) ? (array) $thread['session'] : [];
            $lines[] = 'Objetivo   '.$this->clip((string) ($state['objective'] ?? '-'), 86);
            $lines[] = 'Fase       '.$this->clip((string) ($state['current_phase'] ?? '-'), 86);
            $lines[] = 'Sessao     '.$this->clip((string) ($session['status'] ?? '-').' | messages '.(string) ($session['message_count'] ?? 0), 86);
            $lines[] = 'Resumo     '.$this->clip((string) ($thread['summary'] ?? '-'), 86);
        }

        $lines[] = '';
        $lines[] = 'Operacao   traces queued '.(string) ($traces['queued'] ?? 0).' | processing '.(string) ($traces['processing'] ?? 0).' | jobs queued '.(string) ($jobs['queued'] ?? 0);
        foreach ($providers as $provider) {
            $lines[] = 'Provider   '.(string) ($provider['provider'] ?? '-').' | '.(string) ($provider['status'] ?? '-').' | pain '.(string) ($provider['pain'] ?? '-');
        }

        return $lines;
    }

    /**
     * @param  array<string,mixed>|null  $thread
     * @return array<int,string>
     */
    private function planPanel(?array $thread): array
    {
        $lines = [$this->title('2 Plano')];
        if (! $thread || ! is_array($thread['state'] ?? null)) {
            return [...$lines, 'Sem estado ativo. Use atlas state para iniciar ou atlas ask/dev para criar contexto.'];
        }

        $state = (array) $thread['state'];
        $lines[] = 'Topico     '.$this->clip((string) ($state['current_topic'] ?? '-'), 86);
        $lines[] = 'Objetivo   '.$this->clip((string) ($state['objective'] ?? '-'), 86);
        $lines[] = 'Fase       '.$this->clip((string) ($state['current_phase'] ?? '-'), 86);
        $lines[] = 'Decisoes   '.(string) ($state['decisions_count'] ?? 0);
        $lines[] = 'Open loops '.(string) ($state['open_loops_count'] ?? 0);
        $lines[] = 'Next steps '.(string) ($state['next_steps_count'] ?? 0);
        $lines[] = '';
        $lines[] = 'Comandos   atlas state | atlas plan | atlas dev';

        return $lines;
    }

    /**
     * @return array<int,string>
     */
    private function diffPanel(string $repoRoot): array
    {
        $lines = [$this->title('3 Diff')];
        $stat = trim((string) @shell_exec('git -C '.escapeshellarg($repoRoot).' diff --stat -- . 2>/dev/null'));
        $status = trim((string) @shell_exec('git -C '.escapeshellarg($repoRoot).' status --short 2>/dev/null'));

        $lines[] = 'Repo       '.$repoRoot;
        $lines[] = '';
        $lines[] = $stat !== '' ? $stat : 'Sem diff rastreado em arquivos modificados.';
        $lines[] = '';
        $lines[] = 'Status';
        foreach (array_slice(preg_split('/\R/', $status) ?: [], 0, 8) as $row) {
            if ($row !== '') {
                $lines[] = '  '.$this->clip($row, 92);
            }
        }

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<int,string>
     */
    private function testsPanel(array $data): array
    {
        $workspace = (array) $data['workspace'];
        $atlasAi = (array) $data['atlas_ai'];
        $quality = (array) ($atlasAi['quality'] ?? []);
        $lines = [$this->title('4 Testes')];
        $lines[] = 'Quality    avg24h '.(string) ($quality['average_score_24h'] ?? '-').' | failed '.(string) ($quality['failed'] ?? 0).' | review '.(string) ($quality['needs_review'] ?? 0);
        $lines[] = '';
        $lines[] = 'Comandos detectados';
        foreach ((array) ($workspace['test_commands'] ?? []) as $command) {
            $lines[] = '  '.$command;
        }
        $lines[] = '';
        $lines[] = 'Atalho     atlas test';

        return $lines;
    }

    /**
     * @return array<int,string>
     */
    private function permissionsPanel(string $workspace): array
    {
        $lines = [$this->title('5 Permissoes')];
        if (! Schema::hasTable('ai_permission_sessions')) {
            return [...$lines, 'Tabela ai_permission_sessions indisponivel. Rode migrations.'];
        }

        $sessions = AiPermissionSession::query()
            ->where('workspace', $workspace)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('created_at')
            ->limit(8)
            ->get();

        if ($sessions->isEmpty()) {
            $lines[] = 'Nenhuma permission session ativa.';
        }

        foreach ($sessions as $session) {
            $lines[] = $this->clip($session->id.' | '.$session->mode.' | paths '.implode(',', $session->allowed_paths ?? ['*']).' | expira '.$session->expires_at?->format('H:i:s'), 94);
        }

        $lines[] = '';
        $lines[] = 'a aprova write por 10m neste workspace | x revoga permissoes ativas';

        return $lines;
    }

    /**
     * @return array<int,string>
     */
    private function memoryPanel(): array
    {
        $lines = [$this->title('6 Memoria')];
        if (! Schema::hasTable('ai_memory_deltas')) {
            return [...$lines, 'Tabela ai_memory_deltas indisponivel. Rode migrations.'];
        }

        $counts = AiMemoryDelta::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $lines[] = 'Pending    '.(string) ($counts['pending'] ?? 0).' | accepted '.(string) ($counts['accepted'] ?? 0).' | rejected '.(string) ($counts['rejected'] ?? 0);
        $lines[] = '';

        $latest = AiMemoryDelta::query()->latest('created_at')->limit(8)->get();
        foreach ($latest as $delta) {
            $lines[] = $this->clip($delta->status.' | '.$delta->type.' | '.$delta->claim, 94);
        }

        $lines[] = '';
        $lines[] = 'Comandos   atlas memory propose | atlas memory review | atlas memory accept <id>';

        return $lines;
    }

    /**
     * @return array<int,string>
     */
    private function tracesPanel(): array
    {
        $lines = [$this->title('7 Traces')];
        if (! Schema::hasTable('ai_traces')) {
            return [...$lines, 'Tabela ai_traces indisponivel.'];
        }

        $traces = AiTrace::query()
            ->withCount(['toolEvents'])
            ->latest('created_at')
            ->limit(8)
            ->get();

        foreach ($traces as $trace) {
            $lines[] = $this->clip($trace->id.' | '.$trace->status.' | '.$trace->provider.' | '.$trace->agent_slug.' | tools '.$trace->tool_events_count, 94);
        }

        $lines[] = '';
        $lines[] = 'Comandos   atlas trace last | atlas trace show <id> | atlas compare "..."';

        return $lines;
    }

    private function approveWriteSession(string $workspace): string
    {
        if (! Schema::hasTable('ai_permission_sessions')) {
            return 'Nao foi possivel aprovar: tabela ai_permission_sessions indisponivel.';
        }

        $session = AiPermissionSession::query()->create([
            'workspace' => $workspace,
            'mode' => 'write',
            'allowed_tools' => ['file.write', 'file.patch', 'git.apply_patch', 'shell.run', 'test.run'],
            'allowed_paths' => [$workspace],
            'denied_patterns' => ['**/.env', '**/secrets/*', '**/*token*'],
            'expires_at' => now()->addMinutes(10),
            'granted_by' => 'operator_tui',
            'reason' => 'atlas_tui_hotkey_a',
        ]);

        return 'Write aprovado por 10m: '.substr($session->id, 0, 8);
    }

    private function revokePermissionSessions(string $workspace): string
    {
        if (! Schema::hasTable('ai_permission_sessions')) {
            return 'Nada revogado: tabela ai_permission_sessions indisponivel.';
        }

        $count = AiPermissionSession::query()
            ->where('workspace', $workspace)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->update(['expires_at' => now()]);

        return 'Permission sessions revogadas: '.$count;
    }

    private function compactActiveThread(string $workspace): string
    {
        $code = Artisan::call('atlas:cli:state', [
            'action' => 'compact',
            '--workspace' => $workspace,
            '--json' => true,
        ]);

        return $code === 0 ? 'Sessao compactada.' : 'Compact falhou: '.Str::limit(trim(Artisan::output()), 90);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function handoffProvider(string $workspace, array $data): string
    {
        $thread = data_get($data, 'atlas_ai.active_thread');
        if (! is_array($thread)) {
            return 'Sem thread ativa para handoff.';
        }

        $current = (string) ($thread['provider'] ?? 'claude_cli');
        $target = $current === 'codex_cli' ? 'claude_cli' : 'codex_cli';
        $code = Artisan::call('atlas:cli:state', [
            'action' => 'handoff',
            '--workspace' => $workspace,
            '--provider' => $target,
            '--json' => true,
        ]);

        return $code === 0 ? 'Handoff preparado para '.$target.'.' : 'Handoff falhou: '.Str::limit(trim(Artisan::output()), 90);
    }

    private function readKey(float $timeoutSeconds): ?string
    {
        $read = [STDIN];
        $write = null;
        $except = null;
        $seconds = max(0, (int) floor($timeoutSeconds));
        $microseconds = max(0, (int) (($timeoutSeconds - $seconds) * 1_000_000));

        if (@stream_select($read, $write, $except, $seconds, $microseconds) !== 1) {
            return null;
        }

        $key = fread(STDIN, 1);

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function enableRawMode(): callable
    {
        $state = trim((string) @shell_exec('stty -g 2>/dev/null'));
        @shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');

        return static function () use ($state): void {
            if ($state !== '') {
                @shell_exec('stty '.escapeshellarg($state).' 2>/dev/null');
            }
        };
    }

    private function isInteractiveTty(): bool
    {
        return function_exists('stream_isatty') && stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function tabs(string $selected): string
    {
        $tabs = [];
        foreach ($this->panels as $slug => $panel) {
            $label = $panel['number'].'.'.$panel['title'];
            $tabs[] = $slug === $selected ? $this->accent('['.$label.']') : $label;
        }

        return implode('  ', $tabs);
    }

    private function title(string $value): string
    {
        return $this->accent($value);
    }

    private function accent(string $value): string
    {
        return "\033[1;36m{$value}\033[0m";
    }

    private function muted(string $value): string
    {
        return "\033[2m{$value}\033[0m";
    }

    private function clip(string $value, int $length): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?: '-';

        return Str::limit($value, $length, '...');
    }

    private function printableKey(string $key): string
    {
        $code = ord($key);

        return $code < 32 ? 'ctrl-'.$code : $key;
    }
}
