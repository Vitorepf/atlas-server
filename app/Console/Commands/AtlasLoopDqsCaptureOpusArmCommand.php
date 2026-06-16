<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * ACDE Bloco C — capture the OPUS-ULTRACODE arm panel, the symmetric counterpart to
 * {@see AtlasLoopDqsExtractAceCommand}. The head-to-head ({@see AtlasLoopDeliveryQualityCommand}) is only
 * honest if BOTH arms are machine-resolved over the SAME task set — otherwise comparing ACDE's frozen-gated
 * output to a self-graded Opus arm reintroduces exactly the Goodhart this system forbids.
 *
 * The Opus arm is one-shot refactors on the SAME tasks the loop attempted. This command turns those real
 * refactor artifacts into a delivery-quality panel WITHOUT trusting any self-report:
 *   - attempted = true (the task was put to the engine);
 *   - committed = the engine actually produced a refactor for the task (refused/empty => committed:false,
 *     which the scorer counts as a DEFECT — the same anti-selection-bias rule the ACE arm obeys);
 *   - canary    = the VERDICT-DETERMINING axis: when a workspace + test command are given the command RUNS
 *     the test and records green iff it exits 0 — a measured escaped-defect signal, never a claim. A
 *     committed refactor whose test is RED is an escaped defect, full stop.
 *   - mutation_kill_ratio / completeness / cyclomatic_drop = secondary tie-break axes; passed through from
 *     the manifest as recorded measurements (clamped, default 0 = fail-open low) since they only matter near
 *     defect-rate parity. They are NEVER the engine's own grade — they are measurements the producing harness
 *     recorded, exactly like the ACE arm reads them from the certify() envelope.
 *
 * The manifest is a JSON array, one entry per task in the frozen panel:
 *   [{"task_id":"t1","committed":true,"workspace":"/abs/worktree","test_command":"php tests/x_test.php",
 *     "mutation_kill_ratio":0.8,"completeness":1.0,"cyclomatic_drop":10}, {"task_id":"t2","committed":false}, ...]
 * `committed:false` (Opus refused/failed the task) needs no workspace — it scores as a defect.
 *
 * REPORT-ONLY: reads the manifest, runs the named tests read-only in their given workspaces, writes the
 * panel JSON. It NEVER edits the repo, mutates config/env, or arms anything. It does NOT fabricate the arm —
 * it requires real refactor artifacts (a real ultracode batch, or an operator-produced manifest) as input.
 */
class AtlasLoopDqsCaptureOpusArmCommand extends Command
{
    protected $signature = 'atlas:loop:dqs-capture-opus-arm
        {--manifest= : path to the Opus-arm manifest JSON (array of per-task refactor artifacts)}
        {--out= : write the captured Opus panel JSON to this path}
        {--timeout=120 : per-task test command timeout (seconds)}
        {--json : print the panel JSON to stdout}';

    protected $description = 'Report-only: capture the machine-resolved Opus-ultracode-arm delivery-quality panel from real refactor artifacts (runs each task test to resolve canary).';

    public function handle(): int
    {
        $manifestPath = trim((string) $this->option('manifest'));
        if ($manifestPath === '' || ! is_file($manifestPath)) {
            $this->error('--manifest <path> is required and must exist (JSON array of per-task refactor artifacts)');

            return self::FAILURE;
        }
        $decoded = json_decode((string) @file_get_contents($manifestPath), true);
        if (! is_array($decoded)) {
            $this->error("--manifest: not a JSON array: {$manifestPath}");

            return self::FAILURE;
        }
        $timeout = max(1, (int) $this->option('timeout'));

        $panel = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $committed = ($entry['committed'] ?? false) === true;
            $panel[] = [
                'task_id' => (string) ($entry['task_id'] ?? ''),
                'attempted' => true,
                'committed' => $committed,
                'canary' => $committed ? $this->measureCanary($entry, $timeout) : 'not_run',
                'mutation_kill_ratio' => $this->clamp01($entry['mutation_kill_ratio'] ?? 0),
                'completeness' => $this->clamp01($entry['completeness'] ?? 0),
                'cyclomatic_drop' => max(0.0, (float) ($entry['cyclomatic_drop'] ?? 0)),
            ];
        }

        $attempted = count($panel);
        $committed = count(array_filter($panel, static fn ($o): bool => $o['committed'] === true));
        $green = count(array_filter($panel, static fn ($o): bool => $o['committed'] === true && $o['canary'] === 'green'));
        $red = count(array_filter($panel, static fn ($o): bool => $o['canary'] === 'red'));
        $defects = $attempted - $green;

        if (($out = trim((string) $this->option('out'))) !== '') {
            file_put_contents($out, (string) json_encode($panel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("wrote Opus-arm panel ({$attempted} tasks) -> {$out}");
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($panel, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('OPUS-ultracode arm (machine-resolved from real refactor artifacts)');
        $this->line('attempted ........ '.$attempted);
        $this->line('committed ........ '.$committed.' ('.($attempted > 0 ? round(100 * $committed / $attempted, 1) : 0).'%)');
        $this->line('committed-green .. '.$green);
        $this->line('canary-red ....... '.$red.' (escaped defects)');
        $this->line('defects .......... '.$defects.' (refusals + canary-red), defect_rate = '.($attempted > 0 ? round($defects / $attempted, 4) : 0));
        $this->line('');
        $this->line('Score it:  php artisan atlas:loop:dqs-head-to-head --ace=ace.json --opus='.($out !== '' ? $out : 'opus.json'));
        $this->warn('NOTE: a CONFIDENT >=2x verdict needs the SAME task set on both arms at N>=~30 — a real runtime batch, not a small sample.');

        return self::SUCCESS;
    }

    /**
     * Resolve canary by RUNNING the task's acceptance — the verdict-determining axis is measured, never
     * claimed. No workspace/command => not_run (the panel records the honest "unmeasured", not a false green).
     *
     * @param  array<string,mixed>  $entry
     */
    private function measureCanary(array $entry, int $timeout): string
    {
        $workspace = trim((string) ($entry['workspace'] ?? ''));
        $command = trim((string) ($entry['test_command'] ?? ''));
        if ($workspace === '' || ! is_dir($workspace) || $command === '') {
            return 'not_run';
        }
        $p = new Process(['bash', '-lc', $command], $workspace, null, null, (float) $timeout);
        $p->run();

        return $p->isSuccessful() ? 'green' : 'red';
    }

    private function clamp01(mixed $v): float
    {
        return max(0.0, min(1.0, (float) $v));
    }
}
