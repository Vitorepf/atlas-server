<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Wave pruner for ACDE dead clusters and loop command deprecation stubs.
 */
final class EliteCompactionWavePruner
{
    private const DEPRECATION_STUB = <<<'PHP'
    public function handle(): int
    {
        return $this->emitAcdeLoopDeprecation('{{SIGNATURE}}');
    }
PHP;

    public function stubLoopCommands(bool $dryRun = false): array
    {
        $dir = base_path('app/Console/Commands');
        $files = glob($dir.'/AtlasLoop*.php') ?: [];
        $stubbed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($files as $file) {
            $src = File::get($file);
            if (! str_contains($src, 'atlas:loop')) {
                $skipped++;
                continue;
            }
            if (str_contains($src, 'emitAcdeLoopDeprecation')) {
                $skipped++;
                continue;
            }
            if (! preg_match("/protected \\\$signature\s*=\s*'([^']+)'/", $src, $m)) {
                $errors++;
                continue;
            }
            $signature = trim(explode("\n", $m[1])[0]);
            $updated = $this->injectDeprecationStub($src, $signature);
            if ($updated === null) {
                $errors++;
                continue;
            }
            if (! $dryRun) {
                File::put($file, $updated);
            }
            $stubbed++;
        }

        return [
            'schema' => 'atlas.elite_compaction.loop_deprecation_batch.v1',
            'dry_run' => $dryRun,
            'stubbed' => $stubbed,
            'skipped' => $skipped,
            'errors' => $errors,
            'hard_remove_after' => config('atlas_elite_compaction.loop_commands.hard_remove_after'),
        ];
    }

    public function pruneAcdeDeadCluster(bool $dryRun = false, int $limit = 200): array
    {
        $inventory = app(EliteCompactionInventoryService::class)->inventoryAcde();
        $keepList = array_values(array_map('strval', (array) config('atlas_elite_compaction.acde_keep_list', [])));

        // Obra 3 / AUT-06: fail-closed before any delete when inventory is unsafe.
        $keepInDead = (array) ($inventory['keep_list_in_dead_sample'] ?? []);
        $mismatches = (array) ($inventory['basename_body_mismatches'] ?? []);
        if ($keepInDead !== [] || $mismatches !== [] || ($inventory['fail_closed'] ?? true) !== true) {
            return [
                'schema' => 'atlas.elite_compaction.prune_acde.v1',
                'dry_run' => $dryRun,
                'limit' => $limit,
                'deleted_count' => 0,
                'failed_count' => 0,
                'skipped_keep_list' => 0,
                'deleted' => [],
                'failed' => [],
                'blocked' => true,
                'block_reason' => $keepInDead !== []
                    ? 'keep_list_in_dead_sample'
                    : ($mismatches !== [] ? 'basename_body_mismatch' : 'inventory_fail_closed'),
                'keep_list_in_dead_sample' => $keepInDead,
                'basename_body_mismatches' => $mismatches,
                'remaining_dead_estimate' => (int) ($inventory['dead_candidates_rg0'] ?? 0),
                'note' => 'ELITE-01/AUT-06 fail-closed: refuse prune until inventory is safe.',
            ];
        }

        $candidates = $inventory['dead_sample'] ?? [];
        $candidates = array_slice($candidates, 0, max(1, $limit));
        $deleted = [];
        $failed = [];
        $skipped = [];

        foreach ($candidates as $row) {
            $class = (string) ($row['class'] ?? '');
            $fileRel = (string) ($row['file'] ?? '');
            $basename = $fileRel !== '' ? basename($fileRel, '.php') : $class;
            if ($class !== '' && in_array($class, $keepList, true)) {
                $skipped[] = $fileRel !== '' ? $fileRel : $class;
                continue;
            }
            if ($basename !== '' && in_array($basename, $keepList, true)) {
                $skipped[] = $fileRel !== '' ? $fileRel : $basename;
                continue;
            }
            if ($class !== '' && $basename !== '' && $class !== $basename) {
                $failed[] = ['file' => $fileRel, 'error' => 'basename_class_mismatch'];
                continue;
            }
            $path = base_path($fileRel);
            if (! is_file($path) || str_contains($path, '/Brain/')) {
                continue;
            }
            if ($dryRun) {
                $deleted[] = $fileRel;
                continue;
            }
            try {
                File::delete($path);
                $deleted[] = $fileRel;
            } catch (\Throwable $e) {
                $failed[] = ['file' => $fileRel, 'error' => $e->getMessage()];
            }
        }

        return [
            'schema' => 'atlas.elite_compaction.prune_acde.v1',
            'dry_run' => $dryRun,
            'limit' => $limit,
            'deleted_count' => count($deleted),
            'failed_count' => count($failed),
            'skipped_keep_list' => count($skipped),
            'deleted' => $deleted,
            'failed' => $failed,
            'blocked' => false,
            'remaining_dead_estimate' => max(0, (int) ($inventory['dead_candidates_rg0'] ?? 0) - count($deleted)),
            'note' => 'rg<=1 dead cluster pruned in waves; keep-list + Brain/** intocáveis.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanupLoopWiring(bool $dryRun = false): array
    {
        $checks = [
            'loop_command_files' => count(glob(base_path('app/Console/Commands/AtlasLoop*.php')) ?: []),
            'bootstrap_hits' => $this->countPatternHits('bootstrap/app.php', 'AtlasLoop\\w+Command'),
            'provider_command_regs' => $this->countPatternHits('app/Providers/AppServiceProvider.php', 'AtlasLoop\\w+Command::class'),
            'schedule_hits' => $this->countPatternHits('routes/console.php', "Schedule::command\\('atlas:loop:"),
            'orphan_tests' => $this->listOrphanLoopTests(),
        ];

        return [
            'schema' => 'atlas.elite_compaction.cleanup_loop_wiring.v1',
            'dry_run' => $dryRun,
            'checks' => $checks,
            'ok' => ($checks['loop_command_files'] === 0
                && $checks['bootstrap_hits'] === 0
                && $checks['provider_command_regs'] === 0
                && $checks['schedule_hits'] === 0),
        ];
    }

    private function countPatternHits(string $relPath, string $pattern): int
    {
        $path = base_path($relPath);
        if (! is_file($path)) {
            return 0;
        }
        $src = File::get($path);
        preg_match_all('/'.$pattern.'/', $src, $m);

        return count($m[0] ?? []);
    }

    /**
     * @return list<string>
     */
    private function listOrphanLoopTests(): array
    {
        $process = Process::fromShellCommandline(
            "rg -l \"Artisan::call\\('atlas:loop:\" tests 2>/dev/null || true",
            base_path(),
            null,
            null,
            60
        );
        $process->run();
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return $lines;
    }

    public function stripLoopCommandCorpses(bool $dryRun = false): array
    {
        $dir = base_path('app/Console/Commands');
        $files = glob($dir.'/AtlasLoop*.php') ?: [];
        $stripped = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($files as $file) {
            $src = File::get($file);
            if (! str_contains($src, 'emitAcdeLoopDeprecation')) {
                $skipped++;
                continue;
            }
            if (! preg_match('/namespace\s+([^;]+);/', $src, $ns)
                || ! preg_match("/protected \\\$signature\s*=\s*'([^']+)'/", $src, $sig)) {
                $errors++;
                continue;
            }
            $className = basename($file, '.php');
            $signature = trim(explode("\n", $sig[1])[0]);
            $constants = $this->extractPublicConstants($src);
            $minimal = $this->minimalLoopStub($ns[1], $className, $signature, $constants);
            if ($src === $minimal) {
                $skipped++;
                continue;
            }
            if (! $dryRun) {
                File::put($file, $minimal);
            }
            $stripped++;
        }

        return [
            'schema' => 'atlas.elite_compaction.strip_loop_corpses.v1',
            'dry_run' => $dryRun,
            'stripped' => $stripped,
            'skipped' => $skipped,
            'errors' => $errors,
            'hard_remove_after' => config('atlas_elite_compaction.loop_commands.hard_remove_after'),
        ];
    }

    public function hardDeleteLoopCommands(bool $dryRun = false, bool $forceAfterSunset = false): array
    {
        $sunset = (string) config('atlas_elite_compaction.loop_commands.hard_remove_after', '2026-10-06');
        $allowed = $forceAfterSunset || now()->toDateString() >= $sunset;
        if (! $allowed) {
            return [
                'schema' => 'atlas.elite_compaction.hard_delete_loop.v1',
                'allowed' => false,
                'hard_remove_after' => $sunset,
                'deleted_count' => 0,
                'note' => 'Hard-delete blocked until sunset date. Use --force-after-sunset only for drills.',
            ];
        }

        $deleted = [];
        $failed = [];
        foreach (glob(base_path('app/Console/Commands/AtlasLoop*.php')) ?: [] as $file) {
            $rel = ltrim(str_replace(base_path().'/', '', $file), '/');
            if ($dryRun) {
                $deleted[] = $rel;
                continue;
            }
            try {
                File::delete($file);
                $deleted[] = $rel;
            } catch (\Throwable $e) {
                $failed[] = ['file' => $rel, 'error' => $e->getMessage()];
            }
        }

        return [
            'schema' => 'atlas.elite_compaction.hard_delete_loop.v1',
            'allowed' => true,
            'dry_run' => $dryRun,
            'deleted_count' => count($deleted),
            'failed_count' => count($failed),
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }

    public function pruneGeneratedOrphans(bool $dryRun = false): array
    {
        $inventory = app(EliteCompactionInventoryService::class)->inventoryGenerated();
        $root = base_path('app/Services/Ai/Aaeos/Generated');
        $quarantine = base_path('archive/app/Services/Ai/Aaeos/Quarantine');
        $moved = [];
        $commandsDeleted = [];
        $failed = [];

        $allowlist = array_values(array_map('strval', (array) config('atlas_elite_compaction.generated.allowlist', [])));

        foreach (glob($root.'/*.php') ?: [] as $file) {
            $src = File::get($file);
            // Basename is authoritative — Generated bodies often contain nested
            // `class foo` tokens that would poison a naive first-match regex.
            $class = pathinfo($file, PATHINFO_FILENAME);
            if ($class === '' || ! preg_match('/\bclass\s+'.preg_quote($class, '/').'\b/', $src)) {
                continue;
            }
            // Obra 3 / AAEOS-01: never quarantine allowlisted Generated services.
            if (in_array($class, $allowlist, true)) {
                continue;
            }
            $callers = $this->listClassCallerFiles($class);
            $nonSelf = array_values(array_filter(
                $callers,
                static fn (string $p): bool => ! str_contains($p, 'Aaeos/Generated/'.$class.'.php')
                    && ! str_contains($p, 'Aaeos/Generated/'.$class),
            ));
            // Wrapper-only: Command/tests and/or already-quarantined/Generated peers —
            // no live ACOS/Autonomy/config service caller outside Aaeos.
            $wrapperSurface = static function (string $p): bool {
                return str_starts_with($p, 'app/Console/Commands/')
                    || str_starts_with($p, 'tests/')
                    || str_contains($p, 'Aaeos/Quarantine/')
                    || str_contains($p, 'Aaeos/Generated/');
            };
            $wrapperOnly = $nonSelf !== [] && array_reduce(
                $nonSelf,
                static fn (bool $ok, string $p): bool => $ok && $wrapperSurface($p),
                true,
            );
            $orphan = count($nonSelf) === 0;
            if (! $orphan && ! $wrapperOnly) {
                continue;
            }
            $basename = basename($file);
            if ($dryRun) {
                $moved[] = $basename;
                $commandsDeleted = array_merge(
                    $commandsDeleted,
                    array_values(array_filter(
                        $nonSelf,
                        static fn (string $p): bool => str_starts_with($p, 'app/Console/Commands/')
                            || str_starts_with($p, 'tests/'),
                    )),
                );
                continue;
            }
            try {
                File::ensureDirectoryExists($quarantine);
                // Rewrite namespace to Quarantine so autoload stays honest.
                $quarantined = preg_replace(
                    '/namespace\s+App\\\\Services\\\\Ai\\\\Aaeos\\\\Generated;/',
                    'namespace App\\Services\\Ai\\Aaeos\\Quarantine;',
                    $src,
                    1
                ) ?? $src;
                File::put($quarantine.'/'.$basename, $quarantined);
                File::delete($file);
                foreach ($nonSelf as $rel) {
                    // Never delete peer Generated/Quarantine in this pass — only wrappers.
                    if (str_contains($rel, 'Aaeos/Generated/') || str_contains($rel, 'Aaeos/Quarantine/')) {
                        continue;
                    }
                    $abs = base_path($rel);
                    if (is_file($abs)) {
                        File::delete($abs);
                        $commandsDeleted[] = $rel;
                    }
                }
                $moved[] = $basename;
            } catch (\Throwable $e) {
                $failed[] = ['file' => $basename, 'error' => $e->getMessage()];
            }
        }

        return [
            'schema' => 'atlas.elite_compaction.prune_generated.v1',
            'dry_run' => $dryRun,
            'moved_count' => count($moved),
            'commands_deleted_count' => count(array_unique($commandsDeleted)),
            'failed_count' => count($failed),
            'inventory' => $inventory,
            'moved' => $moved,
            'commands_deleted' => array_values(array_unique($commandsDeleted)),
            'failed' => $failed,
        ];
    }

    /**
     * @return list<string>
     */
    private function listClassCallerFiles(string $class): array
    {
        $process = Process::fromShellCommandline(
            'rg --no-ignore -l -w '.escapeshellarg($class).' app bootstrap config routes tests 2>/dev/null || true',
            base_path(),
            null,
            null,
            60
        );
        $process->run();

        return array_values(array_filter(explode("\n", trim($process->getOutput()))));
    }

    private function referenceCountQuick(string $class): int
    {
        $process = Process::fromShellCommandline(
            'rg --no-ignore -w '.escapeshellarg($class).' app bootstrap config routes tests 2>/dev/null | wc -l',
            base_path(),
            null,
            null,
            30
        );
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function minimalLoopStub(string $namespace, string $class, string $signature, string $constantsBlock = ''): string
    {
        $constantsSection = $constantsBlock !== '' ? "\n{$constantsBlock}\n" : "\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use App\Console\Concerns\EmitsAcdeLoopDeprecation;
use Illuminate\Console\Command;

/** @deprecated Elite compaction — hard-remove after sunset. */
class {$class} extends Command
{
    use EmitsAcdeLoopDeprecation;
{$constantsSection}
    protected \$signature = '{$signature} {--json : machine-readable output}';

    protected \$description = 'Deprecated ACDE loop command (elite compaction alias).';

    public function handle(): int
    {
        return \$this->emitAcdeLoopDeprecation('{$signature}');
    }
}

PHP;
    }

    private function extractPublicConstants(string $src): string
    {
        if (! preg_match_all('/^\s*public\s+const\s+\w+\s*=.*?;/m', $src, $matches)) {
            return '';
        }

        return implode("\n", array_map(static fn (string $line): string => '    '.ltrim($line), $matches[0]));
    }

    private function injectDeprecationStub(string $src, string $signature): ?string
    {
        if (! str_contains($src, 'use Illuminate\Console\Command;')) {
            return null;
        }
        if (! str_contains($src, 'EmitsAcdeLoopDeprecation')) {
            $src = str_replace(
                "use Illuminate\Console\Command;\n",
                "use App\Console\Concerns\EmitsAcdeLoopDeprecation;\nuse Illuminate\Console\Command;\n",
                $src
            );
            $src = preg_replace(
                '/(class\s+\w+\s+extends\s+Command\s*\{)/',
                "$1\n    use EmitsAcdeLoopDeprecation;\n",
                $src,
                1
            ) ?? $src;
        }

        $stub = str_replace('{{SIGNATURE}}', $signature, self::DEPRECATION_STUB);
        $replaced = preg_replace(
            '/public function handle\([^)]*\)[^{]*\{.*?\n    \}/s',
            $stub,
            $src,
            1
        );

        return is_string($replaced) && $replaced !== $src ? $replaced : null;
    }
}
