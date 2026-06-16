<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryQualityScore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ACDE Bloco C — extract the REAL ACDE arm panel from the loop's own merged-to-main outcomes.
 *
 * The honest ACDE arm for a head-to-head is the loop's ACTUAL autonomous output, not a synthetic stand-in.
 * This reads every TERMINAL task (done/failed) the loop attempted and, per task, derives the machine-resolved
 * outcome the {@see AtlasLoopDeliveryQualityScore} consumes:
 *   - attempted = the task reached a terminal state;
 *   - committed = a proposal for that task was merged_to_main;
 *   - canary    = the merged proposal's _canary (green iff ran && passed) — a RED merge is an escaped defect;
 *   - mutation_kill_ratio / completeness / cyclomatic_drop = read from the merged proposal's quality json
 *     (delivery_confidence contributions + quality_grade dimensions) — never model self-report.
 * A task that did NOT merge is a REFUSAL = a delivery defect on that task (the anti-selection-bias rule).
 *
 * REPORT-ONLY: reads the loop tables, writes a panel JSON, never mutates anything. The matching Opus arm
 * (one-shot patches on the SAME tasks) is captured separately; then atlas:loop:dqs-head-to-head scores both.
 */
class AtlasLoopDqsExtractAceCommand extends Command
{
    protected $signature = 'atlas:loop:dqs-extract-ace
        {--campaign= : restrict to one campaign id (default: all)}
        {--multi-file-only : keep only multi-file (Path B large-work) tasks}
        {--out= : write the ACDE panel JSON to this path}
        {--limit=0 : cap the number of tasks (0 = all)}
        {--json : print the panel JSON to stdout}';

    protected $description = 'Report-only: extract the REAL ACDE-arm delivery-quality panel from the loop\'s merged-to-main outcomes.';

    public function handle(): int
    {
        $q = DB::table('atlas_loop_tasks')->whereIn('status', ['done', 'failed']);
        if (($c = trim((string) $this->option('campaign'))) !== '') {
            $q->where('campaign_id', $c);
        }
        if (($lim = (int) $this->option('limit')) > 0) {
            $q->limit($lim);
        }
        $tasks = $q->get(['id', 'status', 'target_path', 'payload']);

        // Merged proposals by task_id (the committed set + its machine quality signals).
        $merged = [];
        foreach (DB::table('atlas_loop_proposals')->where('merged_to_main', true)->get(['task_id', 'quality', 'target_path']) as $p) {
            $merged[(string) $p->task_id] = json_decode((string) $p->quality, true) ?: [];
        }

        $panel = [];
        $multiOnly = (bool) $this->option('multi-file-only');
        foreach ($tasks as $t) {
            if ($multiOnly && ! $this->isMultiFile($t)) {
                continue;
            }
            $committed = isset($merged[(string) $t->id]);
            $quality = $committed ? $merged[(string) $t->id] : [];
            $panel[] = [
                'task_id' => (string) $t->id,
                'attempted' => true,
                'committed' => $committed,
                'canary' => $committed ? $this->canary($quality) : 'not_run',
                'mutation_kill_ratio' => $this->mutation($quality),
                'completeness' => $this->completeness($quality),
                'cyclomatic_drop' => $this->cyclomaticDrop($quality),
            ];
        }

        $attempted = count($panel);
        $committed = count(array_filter($panel, static fn ($o): bool => $o['committed'] === true));
        $green = count(array_filter($panel, static fn ($o): bool => $o['committed'] === true && $o['canary'] === 'green'));
        $defects = $attempted - $green;

        if (($out = trim((string) $this->option('out'))) !== '') {
            file_put_contents($out, (string) json_encode($panel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("wrote ACDE panel ({$attempted} tasks) -> {$out}");
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($panel, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('REAL ACDE arm (loop merged-to-main outcomes)');
        $this->line('attempted ........ '.$attempted);
        $this->line('committed ........ '.$committed.' ('.($attempted > 0 ? round(100 * $committed / $attempted, 1) : 0).'%)');
        $this->line('committed-green .. '.$green);
        $this->line('defects .......... '.$defects.' (refusals + canary-red), defect_rate = '.($attempted > 0 ? round($defects / $attempted, 4) : 0));
        $this->line('');
        $this->line('Score it:  php artisan atlas:loop:dqs-extract-ace --out=ace.json  &&  (capture opus.json on the same tasks)  &&  php artisan atlas:loop:dqs-head-to-head --ace=ace.json --opus=opus.json');

        return self::SUCCESS;
    }

    private function isMultiFile(object $t): bool
    {
        $payload = json_decode((string) ($t->payload ?? ''), true);
        $allowed = is_array($payload['allowed_files'] ?? null) ? $payload['allowed_files'] : [];
        $kind = (string) ($payload['objective_kind'] ?? '');

        return count($allowed) >= 2 || str_contains($kind, 'multi') || str_contains($kind, 'cluster');
    }

    /** @param array<string,mixed> $q */
    private function canary(array $q): string
    {
        $c = $q['_canary'] ?? null;
        if (! is_array($c)) {
            return 'not_run';
        }
        if (($c['ran'] ?? false) !== true) {
            return 'not_run';
        }

        return ($c['passed'] ?? false) === true ? 'green' : 'red';
    }

    /** @param array<string,mixed> $q */
    private function mutation(array $q): float
    {
        // delivery_confidence.contributions.mutation_kill_ratio is the weighted contribution; the grade
        // dimensions are the cleaner [0..1] signal when present. Fall back to 0 (fail-open low).
        $dc = is_array($q['delivery_confidence'] ?? null) ? $q['delivery_confidence'] : [];
        $contrib = is_array($dc['contributions'] ?? null) ? $dc['contributions'] : [];
        if (isset($contrib['mutation_kill_ratio'])) {
            // the contribution is a weight*ratio; clamp into [0..1] as a coarse proxy.
            return max(0.0, min(1.0, (float) $contrib['mutation_kill_ratio'] / 3.0));
        }

        return 0.0;
    }

    /** @param array<string,mixed> $q */
    private function completeness(array $q): float
    {
        $g = is_array($q['quality_grade'] ?? null) ? $q['quality_grade'] : [];
        $dims = is_array($g['dimensions'] ?? null) ? $g['dimensions'] : [];

        return ($dims['behavior_preserved'] ?? false) === true ? 1.0 : 0.0;
    }

    /** @param array<string,mixed> $q */
    private function cyclomaticDrop(array $q): float
    {
        $g = is_array($q['quality_grade'] ?? null) ? $q['quality_grade'] : [];
        $dims = is_array($g['dimensions'] ?? null) ? $g['dimensions'] : [];
        $before = (float) ($dims['cx_before'] ?? 0);
        $after = (float) ($dims['cx_after'] ?? 0);

        return max(0.0, $before - $after);
    }
}
