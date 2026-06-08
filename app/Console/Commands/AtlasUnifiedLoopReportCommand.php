<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * atlas:loop:unified:report — the visible dashboard for the unified loop: utilization
 * (aproveitamento), per-mode yield, scenarios, the flagged fake-implemented backlog, and
 * the sibling code-campaign status. Reads the run's persisted report.json (read-only).
 */
final class AtlasUnifiedLoopReportCommand extends Command
{
    protected $signature = 'atlas:loop:unified:report
        {--run= : Run id (default: most recent)}
        {--json : Print the raw report JSON}';

    protected $description = 'Show the unified loop dashboard: utilization, per-mode yield, backlog, code-campaign status.';

    public function handle(): int
    {
        $root = storage_path('atlas/loop/unified');
        $runId = trim((string) $this->option('run'));
        $runDir = $runId !== '' ? $root.'/'.$runId : $this->latestRunDir($root);

        if ($runDir === null || ! is_file($runDir.'/report.json')) {
            $this->error('No unified loop report found'.($runId !== '' ? ' for run '.$runId : '').'.');

            return self::FAILURE;
        }

        $report = json_decode((string) file_get_contents($runDir.'/report.json'), true);
        if (! is_array($report)) {
            $this->error('Unreadable report.json');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $u = $report['utilization'] ?? [];
        $this->newLine();
        $this->line('  <fg=bright-blue;options=bold>ATLAS UNIFIED LOOP — '.($report['run_id'] ?? '').'</>');
        $this->line('  <fg=gray>'.($report['status'] ?? '').' · provider '.($report['provider'] ?? '').' · '.($report['elapsed_seconds'] ?? 0).'s · '.($report['cycles'] ?? 0).' cycles · merged_to_main='.var_export($report['merged_to_main'] ?? false, true).'</>');
        $this->newLine();

        $this->components->twoColumnDetail('<options=bold>Tasks attempted</>', (string) ($u['tasks_attempted'] ?? 0));
        $this->components->twoColumnDetail('Certified-for-review', '<fg=green>'.($u['certified_for_review'] ?? 0).'</>');
        $this->components->twoColumnDetail('Gate-rejected (held honest)', '<fg=yellow>'.($u['gate_rejected'] ?? 0).'</>');
        $this->components->twoColumnDetail('Reconstruction failed (stale/oversized diff)', '<fg=gray>'.($u['reconstruction_failed'] ?? 0).'</>');
        $this->components->twoColumnDetail('No winner (provider miss)', (string) ($u['no_winner'] ?? 0));
        $this->components->twoColumnDetail('<options=bold>Yield (aproveitamento)</>', (string) ($u['yield'] ?? 0));
        $this->components->twoColumnDetail('Scenarios explored', (string) ($u['scenarios_explored'] ?? 0));

        $this->newLine();
        $this->line('  <options=bold>Per-mode</>');
        foreach (($u['by_mode'] ?? []) as $mode => $m) {
            $this->components->twoColumnDetail(
                '  '.$mode,
                sprintf('%d attempted · %d certified · %d held · %d miss', $m['attempted'] ?? 0, $m['certified'] ?? 0, $m['gate_rejected'] ?? 0, $m['no_winner'] ?? 0),
            );
        }

        $backlog = $report['backlog'] ?? [];
        $this->newLine();
        $this->line('  <options=bold>Flagged backlog (fake-implemented — human/P4 decides)</>');
        $this->components->twoColumnDetail('  docs flagged', (string) ($backlog['flagged_docs'] ?? 0));
        $this->components->twoColumnDetail('  phantom claims', (string) ($backlog['flagged_phantoms'] ?? 0));
        foreach (array_slice($backlog['top'] ?? [], 0, 8) as $t) {
            $this->components->twoColumnDetail('    '.basename((string) ($t['path'] ?? '')), (string) ($t['count'] ?? 0).' · '.($t['route'] ?? ''));
        }

        if (is_array($report['code_campaign'] ?? null)) {
            $cc = $report['code_campaign'];
            $this->newLine();
            $this->line('  <options=bold>Sibling code campaign (P1/P4)</>');
            $this->components->twoColumnDetail('  status', (string) ($cc['status'] ?? 'unknown'));
        }

        $this->newLine();
        $this->line('  <fg=gray>files: '.$runDir.'/{proposals.jsonl, rejected.jsonl, backlog.json, report.json}</>');

        return self::SUCCESS;
    }

    private function latestRunDir(string $root): ?string
    {
        if (! is_dir($root)) {
            return null;
        }
        $dirs = glob($root.'/run-*', GLOB_ONLYDIR) ?: [];
        if ($dirs === []) {
            return null;
        }
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $dirs[0];
    }
}
