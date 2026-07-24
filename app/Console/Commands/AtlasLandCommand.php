<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Brain\AtlasEvolutionDiary;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L1 (Obra #19, Frente L) — `atlas:land`, the ONE door a model session uses to
 * commit. It stops the session committing raw git: this thin command exposes the
 * proven {@see AtlasTaskScopedCommitter} (fail-closed under `.git/atlas-task-commit.lock`,
 * commit-by-pathspec — `git add -A` is impossible by construction) and, in the
 * SAME act, writes the labelled Diary entry the Carta requires (Regra 3).
 *
 *   atlas:land app/Foo.php tests/FooTest.php -m "F1: do the thing" \
 *              --why "because the spec asked" --evidence "phpunit 3/3"
 *
 * Pétreas (Obra #19): the underlying committer holds the lock ONLY for the commit
 * (never during tests — this command runs no tests); acquisition order is
 * task-commit → main-merge, never inverse (this command takes task-commit only).
 * Fail-closed: a non-commit returns a NON-ZERO exit so `atlas:land` can gate a
 * slice's landing.
 */
class AtlasLandCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:land
        {paths* : scoped file paths to stage + commit (ONLY these — git add -A is impossible)}
        {--m= : short objective / commit summary (required)}
        {--why= : por_que for the Diary entry}
        {--evidence= : evidencia (test / receipt / commit ref) for the Diary}
        {--tipo=merge : Diary tipo (merge|orgao-novo|refatoracao|graduacao|...)}
        {--client=atlas:land : committer client id}
        {--task= : task packet id (default land-<ulid>)}
        {--no-diario : commit without writing a Diary entry}
        {--repo= : repo root override (testing)}
        {--diary= : Diary path override (testing)}
        {--json : machine-readable output}';

    protected $description = 'Land a scoped slice on local main (fail-closed committer) + label it in the Evolution Diary (L1 · Carta Regra 3).';

    public function handle(): int
    {
        $paths = array_values(array_filter(array_map('trim', (array) $this->argument('paths')), static fn ($p) => $p !== ''));
        $message = trim((string) $this->option('m'));
        $tipo = (string) $this->option('tipo') ?: 'merge';

        if ($message === '') {
            return $this->bail('atlas:land requires -m "<summary>" (the commit + Diary o_que).');
        }
        if (! $this->option('no-diario') && ! in_array($tipo, AtlasEvolutionDiary::TIPOS, true)) {
            return $this->bail("Unknown --tipo '{$tipo}'. One of: ".implode(', ', AtlasEvolutionDiary::TIPOS));
        }

        $task = (string) ($this->option('task') ?: 'land-'.strtolower((string) Str::ulid()));
        $committer = new AtlasTaskScopedCommitter(null, $this->option('repo') ?: null);
        // atlas:land is the OPERATOR port: the constitution gate governs AUTONOMOUS self-edit only.
        $res = $committer->commitScope($paths, $task, (string) $this->option('client'), $message, commitAuthority: 'operator');

        $diaryId = null;
        if (($res['committed'] ?? false) === true && ! $this->option('no-diario')) {
            $entry = (new AtlasEvolutionDiary($this->option('diary') ?: null))->record(
                $tipo,
                $message,
                (string) ($this->option('why') ?: 'landed via atlas:land'),
                $this->option('evidence') ?: null,
                (string) ($res['commit_sha'] ?? '') ?: null,
            );
            $diaryId = $entry['id'] ?? null;
            $res['diario_id'] = $diaryId;
        }

        // Cognitive Pressure Layer PRODUCER (Goal 3.5): on every real land, run the 3 advisory
        // guards over the committed slice and record their verdicts into the outcome ledger so the
        // guards accumulate CADENCE (the Goal 4 gate reads this). ADVISORY + fail-open: the commit
        // already succeeded, so this never changes the land's exit code. Skipped under tests (would
        // pollute the live ledger); kill-switch via config('atlas.pressure.observe_on_land').
        if (($res['committed'] ?? false) === true
            && config('atlas.pressure.observe_on_land', true)
            && ! app()->runningUnitTests()) {
            try {
                $res['pressure_layer'] = app(PressureLayerGuards::class)->observeLandedSlice(
                    $paths,
                    (array) ($res['files_committed'] ?? $paths),
                    $message,
                    (string) $this->option('client'),
                );
            } catch (\Throwable) {
                // advisory-first: a guard failure NEVER fails a landed slice.
            }
        }

        if ($this->option('json')) {
            $this->line($this->encode($res));
        } elseif (($res['committed'] ?? false) === true) {
            $this->info(sprintf('landed %s → %s', implode(', ', $paths), substr((string) ($res['commit_sha'] ?? ''), 0, 10)));
            if ($diaryId !== null) {
                $this->line("  diário: {$tipo} · {$diaryId}");
            }
        } else {
            $this->error('não landou: '.(string) ($res['reason'] ?? 'unknown'));
        }

        // Fail-closed: a non-commit gates the slice (non-zero exit).
        return ($res['committed'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function bail(string $msg): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['committed' => false, 'reason' => 'invalid_invocation', 'message' => $msg], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($msg);
        }

        return self::FAILURE;
    }
}
