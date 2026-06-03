<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Atlas Evolution Loop — runtime entry.
 *
 * Drives {@see AtlasEvolutionLoopRunner} over a queue of metric-shaped tasks: for
 * each task it explores N candidate scenarios through the provider-agnostic
 * execution abstraction, the FROZEN JUDGE picks the best, and the loop accumulates
 * PROPOSE-ONLY certified-for-review proposals (it never merges to main).
 *
 * `--fixture` runs the smoke task end-to-end through the REAL driver (proof of the
 * whole chain). `--task-file` feeds a real queue (JSON list of metric-shaped tasks).
 */
final class AtlasEvolutionLoopCommand extends Command
{
    protected $signature = 'atlas:loop:evolve
        {--fixture : Run the built-in smoke task end-to-end through the real driver (proof of the chain)}
        {--task-file= : Path to a JSON file holding a queue of metric-shaped tasks}
        {--generate-from= : Path to a self-contained PHP file — the loop GENERATES its own metric-shaped task for it (Sources→Hypotheses), then grinds it. The full autonomous cycle.}
        {--scenarios= : Candidate scenarios explored per task (default: config atlas.loop.scenarios_per_task)}
        {--max-tasks= : Max tasks to process this run}
        {--max-seconds= : Wall-clock budget for the whole run (0 = no cap)}
        {--keep : Keep scenario workspaces for inspection}
        {--json : Print the canonical JSON result}';

    protected $description = 'Run the Atlas Evolution Loop: explore N scenarios per task, frozen judge picks the best, propose-only (never merges).';

    public function handle(AtlasEvolutionLoopRunner $runner): int
    {
        [$tasks, $cleanup] = $this->resolveTasks();
        if ($tasks === []) {
            $this->error('No tasks. Pass --fixture or --task-file=<path>.');

            return self::FAILURE;
        }

        $options = array_filter([
            'scenarios_per_task' => $this->intOption('scenarios'),
            'max_tasks' => $this->intOption('max-tasks'),
            'max_seconds' => $this->intOption('max-seconds'),
        ], static fn (mixed $v): bool => $v !== null);
        if ((bool) $this->option('keep')) {
            foreach ($tasks as &$t) {
                $t['keep_workspaces'] = true;
            }
            unset($t);
        }

        try {
            $result = $runner->run($tasks, $options);
        } finally {
            $cleanup();
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Evolution Loop</>', (string) $result['schema_version']);
            $this->components->twoColumnDetail('Merged to main (must be no)', $result['merged_to_main'] ? 'YES — INVARIANT BROKEN' : 'no');
            $this->components->twoColumnDetail('Tasks processed', (string) $result['tasks_processed']);
            $this->components->twoColumnDetail('Proposals (certified-for-review)', (string) $result['proposals_certified_for_review']);
            $this->components->twoColumnDetail('Stop reason', (string) $result['stop_reason']);
            foreach ($result['explorations'] as $e) {
                $this->components->twoColumnDetail(
                    '  • '.mb_substr((string) $e['objective'], 0, 48),
                    sprintf('%d explored / %d accepted%s', $e['scenarios_explored'], $e['scenarios_accepted'], $e['has_winner'] ? ' ✓' : ' (no winner)'),
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: callable}
     */
    private function resolveTasks(): array
    {
        if ((bool) $this->option('fixture')) {
            $base = $this->buildSmokeFixture();

            return [[$this->smokeTask($base)], function () use ($base): void {
                (new Process(['rm', '-rf', $base]))->run();
            }];
        }

        $genFrom = trim((string) ($this->option('generate-from') ?: ''));
        if ($genFrom !== '' && is_file($genFrom)) {
            return $this->resolveGeneratedTask($genFrom);
        }

        $path = trim((string) ($this->option('task-file') ?: ''));
        if ($path !== '' && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $tasks = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];

            return [$tasks, static function (): void {}];
        }

        return [[], static function (): void {}];
    }

    /**
     * The full autonomous cycle: the loop GENERATES its own metric-shaped task for
     * a real self-contained file (Sources→Hypotheses), then hands it to the runner
     * to grind (Experiments→Results). Only a genuinely RED generated test becomes a task.
     *
     * @return array{0: list<array<string,mixed>>, 1: callable}
     */
    private function resolveGeneratedTask(string $file): array
    {
        $base = sys_get_temp_dir().'/atlas-loop-gen-'.bin2hex(random_bytes(4));
        mkdir($base.'/src', 0o755, true);
        mkdir($base.'/tests', 0o755, true);
        file_put_contents($base.'/composer.json', '{}'.PHP_EOL);
        $targetRel = 'src/'.basename($file);
        copy($file, $base.'/'.$targetRel);
        $cleanup = function () use ($base): void {
            (new Process(['rm', '-rf', $base]))->run();
        };

        $gen = app(AtlasEvolutionTaskGenerator::class)->generateForTarget($base, $targetRel, ['index' => 0]);
        if (! (bool) ($gen['generated'] ?? false)) {
            $this->warn('Task generation produced no real task: '.(string) ($gen['reason'] ?? 'unknown'));

            return [[], $cleanup];
        }

        $this->info('Generated metric-shaped task → '.(string) ($gen['task']['objective'] ?? ''));

        return [[$gen['task']], $cleanup];
    }

    private function buildSmokeFixture(): string
    {
        $base = sys_get_temp_dir().'/atlas-loop-fixture-'.bin2hex(random_bytes(4));
        mkdir($base.'/src', 0o755, true);
        mkdir($base.'/tests', 0o755, true);
        file_put_contents($base.'/composer.json', '{"scripts":{"test":"php tests/SmokeSubjectTest.php"}}'.PHP_EOL);
        file_put_contents($base.'/src/SmokeSubject.php', <<<'PHP'
<?php
namespace Smoke;
final class SmokeSubject
{
    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP);
        file_put_contents($base.'/tests/SmokeSubjectTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/SmokeSubject.php';
$subject = new \Smoke\SmokeSubject();
if ($subject->greeting() !== 'hello atlas') {
    fwrite(STDERR, 'Expected hello atlas, got '.$subject->greeting().PHP_EOL);
    exit(1);
}
echo "ok\n";
PHP);

        return $base;
    }

    /**
     * @return array<string,mixed>
     */
    private function smokeTask(string $base): array
    {
        return [
            'objective' => 'The greeting in src/SmokeSubject.php has a typo that makes the test fail. Fix the typo so the test passes. Edit src/SmokeSubject.php directly.',
            'base_workspace' => $base,
            // provider omitted on purpose -> the loop resolves it provider-agnostically
            // from config (and the smoke fixture is fixed by the deterministic fast-path).
            'allowed_files' => ['src/SmokeSubject.php'],
            'validation_commands' => ['php tests/SmokeSubjectTest.php'],
            'acceptance' => [
                'commands' => ['php tests/SmokeSubjectTest.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**', 'composer.json'],
                'metric_kind' => 'gate',
            ],
        ];
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }
}
