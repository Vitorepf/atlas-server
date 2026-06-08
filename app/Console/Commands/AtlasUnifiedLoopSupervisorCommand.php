<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasUnifiedLoopSupervisorService;
use Illuminate\Console\Command;

final class AtlasUnifiedLoopSupervisorCommand extends Command
{
    protected $signature = 'atlas:loop:unified:supervisor
        {--run= : Unified loop run id (default: latest)}
        {--max-heartbeat-age=900 : Stale heartbeat threshold in seconds}
        {--max-report-age=900 : Stale report.json threshold in seconds}
        {--strict : Exit non-zero when restart is recommended or blockers exist}
        {--json : Print machine-readable report}';

    protected $description = 'Assess unified-loop liveness using heartbeat/report age plus a real PHP worker scan. Read-only restart recommendation.';

    public function handle(AtlasUnifiedLoopSupervisorService $supervisor): int
    {
        $runId = trim((string) $this->option('run'));
        $runDir = $runId !== '' ? storage_path('atlas/loop/unified/'.$runId) : null;
        $report = $supervisor->assess($runDir, [
            'run_id' => $runId !== '' ? $runId : null,
            'max_heartbeat_age_seconds' => (int) $this->option('max-heartbeat-age'),
            'max_report_age_seconds' => (int) $this->option('max-report-age'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($report);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Unified Loop Supervisor</>', (string) ($report['run_id'] ?? ''));
        $this->components->twoColumnDetail('Status', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('PHP worker alive', ($report['php_worker_alive'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Heartbeat age', ($report['heartbeat_age_seconds'] ?? null) === null ? 'missing' : ($report['heartbeat_age_seconds'].'s'));
        $this->components->twoColumnDetail('Report age', ($report['report_age_seconds'] ?? null) === null ? 'missing' : ($report['report_age_seconds'].'s'));
        $this->components->twoColumnDetail('Restart recommended', ($report['restart_recommended'] ?? false) ? 'yes' : 'no');
        if (is_string($report['restart_command'] ?? null) && $report['restart_command'] !== '') {
            $this->line('  <fg=yellow>'.$report['restart_command'].'</>');
        }
        foreach ((array) ($report['blockers'] ?? []) as $blocker) {
            $this->line('  <fg=yellow>blocker: '.$blocker.'</>');
        }

        return $this->exitCode($report);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function exitCode(array $report): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return ((bool) ($report['restart_recommended'] ?? false) || (array) ($report['blockers'] ?? []) !== [])
            ? self::FAILURE
            : self::SUCCESS;
    }
}
