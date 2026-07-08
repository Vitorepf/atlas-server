<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Baseline + vivo/morto inventory for the elite compaction obra.
 */
final class EliteCompactionInventoryService
{
    public function captureBaseline(): array
    {
        $aiRoot = base_path('app/Services/Ai');
        $scorecard = $this->scorecardSnapshot();
        $smokes = $this->smokeSnapshots();

        return [
            'schema' => 'atlas.elite_compaction.baseline.v1',
            'captured_at' => now()->toIso8601String(),
            'freeze_active' => (bool) config('atlas_elite_compaction.freeze.active', false),
            'metrics' => [
                'ai_services_files' => $this->countPhpFiles($aiRoot),
                'ai_services_loc' => $this->countLoc($aiRoot),
                'atlas_commands' => $this->countAtlasCommands(),
                'atlas_loop_commands' => $this->countLoopCommands(),
                'acde_outside_brain_files' => $this->countPhpFiles(base_path('app/Services/Ai/AutonomousEvolution'), excludeBrain: true),
                'aaeos_generated_files' => $this->countPhpFiles(base_path('app/Services/Ai/Aaeos/Generated')),
                'self_construction_files' => $this->countPhpFiles(base_path('app/Services/Ai/SelfConstruction')),
                'self_construction_root_files' => count(glob(base_path('app/Services/Ai/SelfConstruction/*.php')) ?: []),
            ],
            'scorecard' => $scorecard,
            'smokes' => $smokes,
        ];
    }

    public function inventoryAcde(): array
    {
        $keepList = config('atlas_elite_compaction.acde_keep_list', []);
        $root = base_path('app/Services/Ai/AutonomousEvolution');
        $files = $this->listPhpFiles($root, excludeBrain: true);
        $classes = [];
        foreach ($files as $file) {
            $src = File::get($file);
            if (! preg_match('/\bclass\s+(\w+)/', $src, $m)) {
                continue;
            }
            $classes[$m[1]] = $file;
        }

        $refCounts = $this->bulkReferenceCounts(array_keys($classes));
        $dead = [];
        $live = [];
        foreach ($classes as $class => $file) {
            if (in_array($class, $keepList, true)) {
                $live[] = ['class' => $class, 'file' => $this->rel($file), 'reason' => 'keep-list'];
                continue;
            }
            $count = $refCounts[$class] ?? 0;
            if ($count <= 1) {
                $dead[] = ['class' => $class, 'file' => $this->rel($file), 'refs' => $count];
            } else {
                $live[] = ['class' => $class, 'file' => $this->rel($file), 'refs' => $count];
            }
        }

        return [
            'schema' => 'atlas.elite_compaction.acde_inventory.v1',
            'total_outside_brain' => count($files),
            'keep_list_count' => count($keepList),
            'dead_candidates_rg0' => count($dead),
            'live_or_clustered' => count($live),
            'dead_sample' => array_slice($dead, 0, max(50, (int) config('atlas_elite_compaction.prune.acde_sample_limit', 200))),
        ];
    }

    public function inventoryGenerated(): array
    {
        $root = base_path('app/Services/Ai/Aaeos/Generated');
        $files = $this->listPhpFiles($root);
        $withCallers = 0;
        $orphan = 0;
        foreach ($files as $file) {
            $src = File::get($file);
            if (! preg_match('/\bclass\s+(\w+)/', $src, $m)) {
                continue;
            }
            $count = $this->referenceCount($m[1]);
            if ($count <= 1) {
                $orphan++;
            } else {
                $withCallers++;
            }
        }

        return [
            'schema' => 'atlas.elite_compaction.generated_inventory.v1',
            'total_files' => count($files),
            'with_live_callers' => $withCallers,
            'orphan_rg0' => $orphan,
            'hot_path_enabled' => (bool) config('atlas_elite_compaction.generated.hot_path_enabled', false),
        ];
    }

    public function inventorySelfConstruction(): array
    {
        $root = base_path('app/Services/Ai/SelfConstruction');
        $subdirs = array_values(array_filter(scandir($root) ?: [], fn (string $d): bool => $d !== '.' && $d !== '..' && is_dir($root.'/'.$d)));
        $rootFiles = glob($root.'/*.php') ?: [];

        return [
            'schema' => 'atlas.elite_compaction.self_construction_inventory.v1',
            'total_files' => $this->countPhpFiles($root),
            'root_file_count' => count($rootFiles),
            'subdir_count' => count($subdirs),
            'subdirs' => $subdirs,
            'live_criteria' => 'brain|task|ScopedCommitter|TaskServing path OR keep-list OR E2E green',
        ];
    }

    public function persistBaseline(array $baseline): string
    {
        $dir = storage_path('app/atlas/elite-compaction');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/phase0-baseline.json';
        File::put($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, int>
     */
    private function bulkReferenceCounts(array $classNames): array
    {
        if ($classNames === []) {
            return [];
        }
        $pattern = '\b('.implode('|', array_map('preg_quote', $classNames)).')\b';
        $process = Process::fromShellCommandline(
            'rg --no-ignore -o '.escapeshellarg($pattern).' app bootstrap config routes tests database 2>/dev/null || true',
            base_path(),
            null,
            null,
            120
        );
        $process->run();
        $counts = array_fill_keys($classNames, 0);
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/:(Atlas\w+)$/', $line, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function referenceCount(string $class): int
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

    private function scorecardSnapshot(): array
    {
        try {
            Artisan::call('atlas:cognition:scorecard', ['--json' => true]);
            $decoded = json_decode(Artisan::output(), true);

            return [
                'schema_version' => data_get($decoded, 'report.schema_version'),
                'overall' => data_get($decoded, 'report.score.overall_out_of_10'),
                'hash' => data_get($decoded, 'report.scorecard_hash'),
                'subsystem_count' => data_get($decoded, 'report.subsystem_count'),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function smokeSnapshots(): array
    {
        $out = [];
        foreach ([
            'dev_proof' => ['atlas:proof:status', ['--json' => true]],
            'brain' => ['atlas:brain:summary', ['--compact' => true]],
            'task_serving' => ['atlas:task:serving', ['action' => 'status', '--json' => true]],
        ] as $key => [$cmd, $args]) {
            try {
                Artisan::call($cmd, $args);
                $out[$key] = ['ok' => true, 'output' => trim(Artisan::output())];
            } catch (\Throwable $e) {
                $out[$key] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    private function countPhpFiles(string $root, bool $excludeBrain = false): int
    {
        return count($this->listPhpFiles($root, $excludeBrain));
    }

    /**
     * @return list<string>
     */
    private function listPhpFiles(string $root, bool $excludeBrain = false): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if ($excludeBrain && str_contains($path, '/Brain/')) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    private function countLoc(string $root): int
    {
        $process = Process::fromShellCommandline(
            'find '.escapeshellarg($root).' -name "*.php" -print0 | xargs -0 wc -l 2>/dev/null | tail -1 | awk "{print \$1}"',
            base_path(),
            null,
            null,
            60
        );
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function countAtlasCommands(): int
    {
        $process = Process::fromShellCommandline(
            "rg -l 'protected \\\$signature.*atlas:' app/Console/Commands 2>/dev/null | wc -l",
            base_path(),
            null,
            null,
            30
        );
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function countLoopCommands(): int
    {
        // Hard-delete gate: only live AtlasLoop* command files count.
        // Do not count historical comments mentioning "atlas:loop" in other commands.
        return count(glob(base_path('app/Console/Commands/AtlasLoop*.php')) ?: []);
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace(base_path().'/', '', $path), '/');
    }
}
