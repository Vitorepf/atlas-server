<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\SelfConstruction\AtlasArtisanBootSmokeGate;
use App\Services\Engineering\EngineeringQualityScanService;
use App\Support\AtlasPhpBinary;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * P1 (Obra #19, Frente P) — `atlas:pregate <paths...>`, the ≤3s pre-gate a model
 * runs BEFORE spending a test round: `php -l` (syntax) + `pint --test` (style) +
 * `phpstan --memory-limit=3G` (types) over ONLY the given paths. A type/syntax
 * error caught here saves a full test run.
 *
 * The phpstan default-crash fix lives in {@see EngineeringQualityScanService};
 * this command pins the same --memory-limit so the standalone path never OOMs either.
 *
 * ponytail: standalone 3-check wrapper, NOT the full EngineeringQualityScanService
 * (which builds ~15 tool plans + git status — far over the 3s budget). The ≤3s
 * target assumes a warm phpstan resultCache; a cold cache is slower — we report
 * elapsed and warn, but pass/fail is on the checks, never on the clock (no flaky gate).
 */
class AtlasPregateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:pregate
        {paths* : php files to gate}
        {--skip-phpstan : run only php -l + pint (fastest)}
        {--json : machine-readable output}';

    protected $description = 'P1 · ≤3s pre-gate over the given paths: php -l + pint --test + phpstan (Obra #19).';

    public function handle(): int
    {
        $requested = array_values(array_filter(array_map('trim', (array) $this->argument('paths')), static fn ($p) => $p !== ''));
        $php = array_values(array_filter(
            $requested,
            static fn ($p) => str_ends_with(strtolower($p), '.php') && is_file($p),
        ));
        // A requested .php path that is not on disk is a typo, a stale path, or a
        // wrong working directory — and it used to be dropped in silence, so the
        // caller got "pregate OK" over a file that was never checked. Say so
        // instead of passing.
        //
        // Non-.php paths stay a legitimate skip: callers routinely hand this command
        // a whole changed-files list, and a commit touching only .md must not fail.
        $missingPhp = array_values(array_filter(
            $requested,
            static fn ($p) => str_ends_with(strtolower($p), '.php') && ! is_file($p),
        ));
        if ($missingPhp !== []) {
            return $this->render(false, [[
                'tool' => 'targets',
                'ok' => false,
                'output' => 'requested .php path(s) do not exist: '.implode(', ', $missingPhp),
            ]], 0.0, count($missingPhp).' requested .php path(s) missing — nothing was gated for them');
        }

        if ($php === []) {
            return $this->render(true, [], 0.0, 'no php targets (nothing to gate)');
        }

        $started = microtime(true);
        $checks = [];

        // 1) php -l — syntax, per file (cheapest, fail-fast signal).
        $lintFails = [];
        foreach ($php as $file) {
            $r = $this->proc([AtlasPhpBinary::path(), '-l', $file], 20);
            if ($r['code'] !== 0) {
                $lintFails[] = $file.': '.trim($r['err'] ?: $r['out']);
            }
        }
        $checks[] = ['tool' => 'php -l', 'ok' => $lintFails === [], 'output' => $lintFails === [] ? null : implode("\n", $lintFails)];

        // 2) pint --test — style.
        $pint = $this->proc([AtlasPhpBinary::path(), '-d', 'memory_limit=1024M', 'vendor/bin/pint', '--test', ...$php], 60);
        $checks[] = ['tool' => 'pint', 'ok' => $pint['code'] === 0, 'output' => $pint['code'] === 0 ? null : trim($pint['out'] ?: $pint['err'])];

        // 3) phpstan --memory-limit=3G — types (the default-crash fix).
        if (! $this->option('skip-phpstan')) {
            $stan = $this->proc(['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=3G', ...$php], 120);
            $checks[] = ['tool' => 'phpstan', 'ok' => $stan['code'] === 0, 'output' => $stan['code'] === 0 ? null : trim($stan['out'] ?: $stan['err'])];
        }

        // 4) EVI-03 — artisan boot smoke in an isolated subprocess (live tree).
        $boot = app(AtlasArtisanBootSmokeGate::class)->smokeLiveTree(base_path());
        $checks[] = [
            'tool' => 'artisan boot smoke',
            'ok' => ($boot['ok'] ?? false) === true,
            'output' => ($boot['ok'] ?? false) === true ? null : (string) ($boot['stderr_tail'] ?? 'boot_smoke_failed'),
        ];

        $elapsed = round(microtime(true) - $started, 2);
        $ok = ! collect($checks)->contains(fn ($c) => ($c['ok'] ?? true) === false);

        return $this->render($ok, $checks, $elapsed);
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function render(bool $ok, array $checks, float $elapsed, string $note = ''): int
    {
        $payload = ['ok' => $ok, 'elapsed_s' => $elapsed, 'within_budget' => $elapsed <= 3.0, 'checks' => array_values($checks), 'note' => $note ?: null];

        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $c) {
            $mark = ($c['ok'] ?? true) ? '<info>✓</info>' : '<error>✗</error>';
            $this->line(sprintf('  %s %s%s', $mark, $c['tool'] ?? '?', ($c['ok'] ?? true) ? '' : "\n".($c['output'] ?? '')));
        }
        $this->line(sprintf('%s (%.2fs%s)', $ok ? '<info>pregate OK</info>' : '<error>pregate FAIL</error>', $elapsed, $elapsed <= 3.0 ? '' : ' — over 3s budget'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $cmd @return array{code:int,out:string,err:string} */
    private function proc(array $cmd, int $timeout): array
    {
        $p = new Process($cmd, base_path(), ['CI' => '1']);
        $p->setTimeout($timeout);
        try {
            $p->run();
        } catch (\Throwable $e) {
            return ['code' => 1, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }
}
