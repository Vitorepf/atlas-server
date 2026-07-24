<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Console\Command;

class AtlasMemoryQualityCommand extends Command
{
    protected $signature = 'atlas:memory:quality
        {action=scorecard : scorecard, history or snapshot}
        {--workspace= : Workspace path}
        {--type=* : Filter by memory type}
        {--scope-type= : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--source-type= : Source type}
        {--status= : active, inactive or archived}
        {--days=30 : Days for history}
        {--limit=50 : Maximum history rows}
        {--record : Persist the current scorecard as a quality snapshot}
        {--check : Run MEM-09 pinned watchdog checks and fail on alert}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas memory quality scorecard and operational recommendations.';

    public function handle(AtlasMemoryQualityService $quality, AtlasAcosWatchdogHealthService $health): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        if ((bool) $this->option('check')) {
            return $this->check($health);
        }
        if ($action === 'history') {
            return $this->history($quality);
        }
        if (in_array($action, ['snapshot', 'record'], true)) {
            return $this->scorecard($quality, record: true);
        }
        if (! in_array($action, ['scorecard', 'show', 'status'], true)) {
            $this->error("Acao invalida para atlas:memory:quality: {$action}");

            return self::FAILURE;
        }

        return $this->scorecard($quality, record: (bool) $this->option('record'));
    }

    private function check(AtlasAcosWatchdogHealthService $health): int
    {
        $report = $health->memoryQualityCheck($this->filters());
        $payload = ['memory_quality_check' => $report];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Quality Check</>', (string) ($report['status'] ?? 'unknown'));
            foreach ((array) ($report['checks'] ?? []) as $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->components->twoColumnDetail((string) ($check['id'] ?? 'check'), ((bool) ($check['pass'] ?? false)) ? 'pass' : 'fail');
            }
        }

        return ($report['alert'] ?? false) === true ? self::FAILURE : self::SUCCESS;
    }

    private function scorecard(AtlasMemoryQualityService $quality, bool $record = false): int
    {
        $scorecard = $quality->scorecard($this->filters());
        $snapshot = $record
            ? $quality->recordSnapshot($scorecard, [
                'workspace' => $this->stringOption('workspace'),
                'source_type' => 'cli',
                'metadata' => ['command' => 'atlas:memory:quality'],
            ])
            : null;
        $payload = [
            'memory_quality' => $scorecard,
            'memory_quality_snapshot' => $snapshot ? $quality->snapshotPayload($snapshot) : null,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $scorecard = $payload['memory_quality'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Quality</>', (string) ($scorecard['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Score', (string) ($scorecard['score'] ?? 0));
        $this->components->twoColumnDetail('Active', (string) data_get($scorecard, 'counts.active', 0));
        $this->components->twoColumnDetail('Provider-safe', (string) data_get($scorecard, 'counts.provider_safe_active', 0));
        $this->components->twoColumnDetail('Open conflicts', (string) data_get($scorecard, 'counts.relations.open_conflicts', 0));
        $this->components->twoColumnDetail('Accepted learnings', (string) data_get($scorecard, 'counts.deltas.accepted', 0));
        $this->components->twoColumnDetail('Trend', (string) data_get($scorecard, 'trend.status', '-'));

        $drivers = array_values((array) data_get($scorecard, 'trend.drivers', []));
        if ($drivers !== []) {
            $this->newLine();
            $this->line('Trend drivers:');
            foreach (array_slice($drivers, 0, 5) as $driver) {
                if (! is_array($driver)) {
                    continue;
                }

                $this->line('  - '.(string) ($driver['severity'] ?? 'info').': '
                    .(string) ($driver['key'] ?? $driver['kind'] ?? 'driver')
                    .' delta '.(string) ($driver['delta'] ?? 'n/a'));
            }
        }

        $issues = (array) ($scorecard['issues'] ?? []);
        if ($issues !== []) {
            $this->newLine();
            $this->line('Issues:');
            foreach ($issues as $issue) {
                $this->line('  - '.(string) ($issue['severity'] ?? 'info').': '.(string) ($issue['code'] ?? 'unknown'));
            }
        }

        $recommendations = (array) ($scorecard['recommendations'] ?? []);
        if ($recommendations !== []) {
            $this->newLine();
            $this->line('Recomendacoes:');
            foreach ($recommendations as $recommendation) {
                $this->line('  - '.(string) $recommendation);
            }
        }

        return self::SUCCESS;
    }

    private function history(AtlasMemoryQualityService $quality): int
    {
        $payload = [
            'memory_quality_history' => $quality->history(
                $this->filters(),
                (int) $this->option('days'),
                (int) $this->option('limit'),
            ),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $history = $payload['memory_quality_history'];
        $summary = (array) ($history['summary'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Quality History</>', (string) ($history['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Period', (string) ($history['period_days'] ?? '-').' days');
        $this->components->twoColumnDetail('Snapshots', (string) ($summary['total'] ?? 0));
        $this->components->twoColumnDetail('Latest score', (string) ($summary['latest_score'] ?? '-'));
        $this->components->twoColumnDetail('Score delta', (string) ($summary['score_delta'] ?? '-'));
        $this->components->twoColumnDetail('Trend', (string) ($summary['trend_status'] ?? '-'));

        $this->table(
            ['snapshot_at', 'status', 'score', 'source'],
            collect((array) ($history['snapshots'] ?? []))
                ->map(fn (array $snapshot): array => [
                    $snapshot['snapshot_at'] ?? '-',
                    $snapshot['status'] ?? '-',
                    $snapshot['score'] ?? '-',
                    $snapshot['source_type'] ?? '-',
                ])
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'workspace' => $this->stringOption('workspace'),
            'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'source_type' => $this->stringOption('source-type'),
            'status' => $this->stringOption('status'),
        ];
    }

}
