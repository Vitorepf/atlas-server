<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasUnifiedLoopSupervisorService;
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

    public function handle(AtlasUnifiedLoopSupervisorService $supervisor): int
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
        $report['independent_verification'] = $this->independentVerificationSummary($runDir);
        $report['supervisor'] = $supervisor->assess($runDir, ['run_id' => basename($runDir)]);

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
        $iv = $report['independent_verification'];
        $this->components->twoColumnDetail('Independently verified', '<fg=green>'.($iv['independently_verified'] ?? 0).'</>');
        $this->components->twoColumnDetail('Refuted by independent verifier', '<fg=yellow>'.($iv['refuted'] ?? 0).'</>');
        $sv = $report['supervisor'];
        $this->components->twoColumnDetail('Supervisor status', (string) ($sv['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('PHP worker alive', ($sv['php_worker_alive'] ?? false) ? 'yes' : 'no');

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
        $this->line('  <options=bold>Flagged backlog (human/Forge decides)</>');
        $this->components->twoColumnDetail('  items flagged', (string) ($backlog['flagged_items'] ?? $backlog['flagged_docs'] ?? 0));
        $this->components->twoColumnDetail('  phantom claims', (string) ($backlog['flagged_phantoms'] ?? 0));
        foreach ((array) ($backlog['by_mode'] ?? []) as $mode => $count) {
            $this->components->twoColumnDetail('  '.$mode, (string) $count);
        }
        foreach (array_slice($backlog['top'] ?? [], 0, 8) as $t) {
            $this->components->twoColumnDetail('    '.basename((string) ($t['path'] ?? '')), (string) ($t['count'] ?? 0).' · '.($t['mode'] ?? '').' · '.($t['route'] ?? ''));
        }

        if (is_array($report['intelligence'] ?? null)) {
            $intel = $report['intelligence'];
            $summary = (array) ($intel['summary'] ?? []);
            $provider = (array) ($intel['provider_matrix'] ?? []);
            $learning = (array) ($intel['learning'] ?? []);
            $this->newLine();
            $this->line('  <options=bold>Priority intelligence</>');
            $this->components->twoColumnDetail('  top impact score', (string) ($summary['top_impact_score'] ?? 0));
            $this->components->twoColumnDetail('  feedback signals', (string) ($learning['feedback_count'] ?? 0));
            $this->components->twoColumnDetail('  provider candidates', (string) ($provider['known_count'] ?? 0));
            $this->components->twoColumnDetail('  cross-domain slots', (string) ($summary['cross_domain_slots'] ?? 0));
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

    /**
     * @return array<string,mixed>
     */
    private function independentVerificationSummary(string $runDir): array
    {
        $summary = [];
        $summaryPath = $runDir.'/independent_verification_summary.json';
        if (is_file($summaryPath)) {
            $decoded = json_decode((string) file_get_contents($summaryPath), true);
            $summary = is_array($decoded) ? $decoded : [];
        }

        return [
            'independently_verified' => $this->jsonlCount($runDir.'/independently_verified.jsonl'),
            'refuted' => $this->jsonlCount($runDir.'/refuted.jsonl'),
            'last_summary' => $summary === [] ? null : $summary,
        ];
    }

    private function jsonlCount(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
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
