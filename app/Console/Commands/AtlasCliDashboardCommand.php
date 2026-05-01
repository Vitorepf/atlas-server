<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliDashboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasCliDashboardCommand extends Command
{
    protected $signature = 'atlas:cli:dashboard
        {--workspace= : Workspace path. Defaults to the current directory}
        {--refresh-index : Refresh workspace profile cache}
        {--limit=8 : Maximum rows per dashboard section}
        {--watch=0 : Refresh every N seconds until interrupted}
        {--classic : Force classic PHP dashboard fallback}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show the Atlas CLI/TUI operational dashboard for the current Mac workspace.';

    public function handle(AtlasCliDashboardService $dashboard): int
    {
        $workspace = $this->workspace();
        $watch = max(0, (int) $this->option('watch'));

        if ($watch > 0 && ! (bool) $this->option('json')) {
            while (true) {
                $this->clearScreen();
                $data = $dashboard->build($workspace, (bool) $this->option('refresh-index'), (int) $this->option('limit'));
                $this->render($data);
                $this->line('<fg=gray>Atualizando a cada '.$watch.'s. Ctrl+C para sair.</>');
                sleep($watch);
            }
        }

        $data = $dashboard->build($workspace, (bool) $this->option('refresh-index'), (int) $this->option('limit'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($data);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function render(array $data): void
    {
        $workspace = (array) $data['workspace'];
        $atlasAi = (array) $data['atlas_ai'];
        $runtime = (array) $data['runtime'];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI/TUI</>', (string) $data['generated_at']);
        $this->components->twoColumnDetail('Workspace', (string) $workspace['path']);
        $this->components->twoColumnDetail('Repo', (string) ($workspace['repo_root'] ?: '-'));
        $this->components->twoColumnDetail('Branch', trim(($workspace['branch'] ?: '-').' '.($workspace['head'] ? '@ '.$workspace['head'] : '')));
        $this->components->twoColumnDetail('Stack', $this->join($workspace['stack'] ?? []));
        $this->components->twoColumnDetail('Package', (string) ($workspace['package_manager'] ?: '-'));
        $this->components->twoColumnDetail('Dirty files', (string) $workspace['dirty_count']);

        $this->section('Sessao Atlas');
        $thread = $atlasAi['active_thread'] ?? null;
        if (is_array($thread)) {
            $this->components->twoColumnDetail('Thread', $this->short((string) $thread['id']).' - '.(string) ($thread['title'] ?: 'sem titulo'));
            $this->components->twoColumnDetail('Provider', (string) ($thread['provider'] ?: '-'));
            $this->components->twoColumnDetail('Messages', (string) ($thread['message_count'] ?? 0));
            $session = is_array($thread['session'] ?? null) ? $thread['session'] : null;
            $this->components->twoColumnDetail('Session', $session ? $this->short((string) $session['id']).' '.$session['status'] : '-');
            $state = is_array($thread['state'] ?? null) ? $thread['state'] : null;
            $this->components->twoColumnDetail('Objective', $state ? Str::limit((string) ($state['objective'] ?: '-'), 96) : '-');
            $this->components->twoColumnDetail('Phase', $state ? (string) ($state['current_phase'] ?: '-') : '-');
            $this->components->twoColumnDetail('Open loops', $state ? (string) ($state['open_loops_count'] ?? 0) : '-');
            $trace = is_array($thread['last_trace'] ?? null) ? $thread['last_trace'] : null;
            $this->components->twoColumnDetail('Last trace', $trace ? $this->short((string) $trace['id']).' '.$trace['status'] : '-');
        } else {
            $this->line('<fg=gray>Nenhuma thread Atlas CLI ativa neste workspace.</>');
        }

        $this->section('Operacao');
        $traces = (array) ($atlasAi['traces'] ?? []);
        $jobs = (array) ($atlasAi['jobs'] ?? []);
        $quality = (array) ($atlasAi['quality'] ?? []);
        $actions = (array) ($atlasAi['actions'] ?? []);
        $metrics = (array) data_get($atlasAi, 'metrics.totals', []);
        $this->table(
            ['area', 'queued', 'processing', 'failed/open', 'extra'],
            [
                ['traces', $traces['queued'] ?? '-', $traces['processing'] ?? '-', $traces['failed_24h'] ?? '-', '24h total '.($traces['total_24h'] ?? '-')],
                ['jobs', $jobs['queued'] ?? '-', $jobs['processing'] ?? '-', $jobs['failed_24h'] ?? '-', '-'],
                ['quality', '-', '-', $quality['failed'] ?? '-', 'avg '.($quality['average_score_24h'] ?? '-')],
                ['metrics', '-', '-', $metrics['needed_remediation_rate'] ?? '-', 'q '.($metrics['final_quality_avg'] ?? '-').' / e '.($metrics['final_efficiency_avg'] ?? '-')],
                ['actions', $actions['queued'] ?? '-', '-', $actions['open'] ?? '-', 'blocked '.($actions['blocked'] ?? '-')],
            ],
        );

        $this->section('Providers');
        $providers = (array) ($data['providers'] ?? []);
        if ($providers === []) {
            $this->line('<fg=gray>Nenhum snapshot de provider ainda. Rode atlas bootstrap --refresh-providers para diagnosticar.</>');
        } else {
            $this->table(
                ['provider', 'status', 'pain', 'p50', 'checked'],
                collect($providers)->map(fn (array $provider): array => [
                    $provider['provider'] ?? '-',
                    $provider['status'] ?? '-',
                    $provider['pain'] ?? '-',
                    $provider['p50_latency_ms'] ?? '-',
                    $provider['checked_at'] ?? '-',
                ])->all(),
            );
        }

        $this->section('Runtime');
        $this->components->twoColumnDetail('Permission default', (string) $runtime['default_permission']);
        $this->components->twoColumnDetail('Danger allowed', ((bool) $runtime['danger_allowed']) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Allowed roots', $this->join($runtime['allowed_roots'] ?? []));

        $this->section('Workspace');
        $this->listRows('Dirty files', (array) ($workspace['dirty_files'] ?? []));
        $this->listRows('Tests', (array) ($workspace['test_commands'] ?? []));
        $this->listRows('Important files', (array) ($workspace['important_files'] ?? []));

        $this->section('Comandos Recomendados');
        foreach ((array) ($data['recommended_commands'] ?? []) as $command) {
            $this->line('  '.$command);
        }

        $this->newLine();
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>'.$title.'</>');
    }

    /**
     * @param  array<int|string,mixed>  $values
     */
    private function join(array $values): string
    {
        $items = collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value) && (string) $value !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->values();

        return $items->isEmpty() ? '-' : $items->implode(', ');
    }

    /**
     * @param  array<int|string,mixed>  $rows
     */
    private function listRows(string $label, array $rows): void
    {
        if ($rows === []) {
            $this->line("{$label}: <fg=gray>-</>");

            return;
        }

        $this->line($label.':');
        foreach ($rows as $row) {
            $this->line('  - '.Str::limit((string) $row, 120));
        }
    }

    private function short(string $id): string
    {
        return substr($id, 0, 8);
    }

    private function clearScreen(): void
    {
        $this->output->write("\033[2J\033[H");
    }
}
