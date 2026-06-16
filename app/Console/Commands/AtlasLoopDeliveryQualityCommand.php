<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryQualityScore;
use Illuminate\Console\Command;

/**
 * ACDE Bloco C — the head-to-head Delivery-Quality runner (REPORT-ONLY).
 *
 * Feeds two panels of attempted-frozen-obra outcomes (one per engine, the SAME task set) through
 * {@see AtlasLoopDeliveryQualityScore} and reports whether engine A (e.g. ACDE/MiniMax) is >= factor better
 * than engine B (e.g. Opus ultracode) on machine-resolved delivery quality — defect-rate primary (refusals
 * count as defects), relative-risk lower bound for the ">=2x" claim, machine tie-breaks near parity.
 *
 * Each panel file is a JSON array of outcomes:
 *   [{"attempted":true,"committed":true,"canary":"green","mutation_kill_ratio":0.8,"completeness":1.0,"cyclomatic_drop":3}, ...]
 * where defect = (attempted AND (not committed OR canary=="red")). The two panels MUST be the same task set
 * (same attempted count) or the run refuses to compare (panel_mismatch).
 *
 * NEVER mutates config/env and NEVER arms anything — it only turns a frozen head-to-head into a number.
 */
class AtlasLoopDeliveryQualityCommand extends Command
{
    protected $signature = 'atlas:loop:dqs-head-to-head
        {--ace= : path to engine-A (ACDE) panel JSON}
        {--opus= : path to engine-B (Opus ultracode) panel JSON}
        {--factor=2.0 : the ">=Nx better" target (default 2.0)}
        {--json : canonical JSON output}';

    protected $description = 'Report-only: machine-resolved head-to-head delivery-quality score (ACDE vs ultracode) on a frozen panel.';

    public function handle(AtlasLoopDeliveryQualityScore $dqs): int
    {
        // ACDE D3 — DEPRECATED. Rivals / the repeated head-to-head is a failed, disabled approach: quality is
        // proven PER DELIVERY now (a human-frozen bar + a machine-resolved dossier), and the AGGREGATE view is
        // the SELF-trend `atlas:loop:capability-trend` (D1) — never an engine-vs-engine comparison. This command
        // survives only because its score() math is reused by the per-delivery dossier; do not re-arm the
        // head-to-head. (Warning suppressed under --json so machine consumers stay clean.)
        if (! $this->option('json')) {
            $this->warn('DEPRECATED: head-to-head is disabled (Rivals failed). Evaluate quality PER DELIVERY; for the aggregate trend use `php artisan atlas:loop:capability-trend`. See docs/acde-teto-closure.md.');
        }

        $ace = $this->loadPanel((string) $this->option('ace'), 'ace');
        $opus = $this->loadPanel((string) $this->option('opus'), 'opus');
        if ($ace === null || $opus === null) {
            return self::FAILURE;
        }

        $factor = max(1.0, (float) $this->option('factor'));
        $result = $dqs->headToHead($ace, $opus, $factor);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $a = $result['a'];
        $b = $result['b'];
        $this->info('ACDE  (A): attempted='.$a['attempted'].' committed='.$a['committed'].' refused='.$a['refused'].' escaped='.$a['escaped_defects'].' defect_rate='.$a['defect_rate']);
        $this->info('Opus  (B): attempted='.$b['attempted'].' committed='.$b['committed'].' refused='.$b['refused'].' escaped='.$b['escaped_defects'].' defect_rate='.$b['defect_rate']);
        $this->line('');
        $this->line('factor target ......... '.$result['factor'].'x');
        $this->line('defect ratio (point) .. '.($result['defect_ratio_point'] ?? 'n/a'));
        $this->line('confident A >= factor . '.($result['confident_a_better'] ? 'YES' : 'no'));
        $this->line('tie-break winner ...... '.($result['tie_break_winner'] ?? 'n/a'));
        $this->{$result['confident_a_better'] ? 'info' : 'warn'}('VERDICT: '.$result['verdict']);
        $this->line($result['reason']);

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>>|null */
    private function loadPanel(string $path, string $label): ?array
    {
        if (trim($path) === '') {
            $this->error("--{$label} <path> is required (JSON array of obra outcomes)");

            return null;
        }
        if (! is_file($path)) {
            $this->error("--{$label}: file not found: {$path}");

            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->error("--{$label}: not a JSON array of outcomes: {$path}");

            return null;
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
