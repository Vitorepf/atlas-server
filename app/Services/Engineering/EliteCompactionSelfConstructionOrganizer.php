<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Support\Facades\File;

/**
 * Classify and move SelfConstruction root PHP files into existing subdirs (no *Defactor* dirs).
 */
final class EliteCompactionSelfConstructionOrganizer
{
    public const SCHEMA_VERSION = 'atlas.elite_compaction.sc_organize.v1';

    /** Hot-path files that must stay at SelfConstruction root. */
    private const KEEP_AT_ROOT = [
        'AgentControlPlaneTaskQueueOrchestrator.php',
        'AtlasTaskServingService.php',
        'AtlasTaskServingStack.php',
        'AtlasTaskServingSwitch.php',
        'AtlasTaskServingSentinel.php',
        'AtlasTaskScopedCommitter.php',
        'AtlasTaskPacketQualityInspector.php',
        'AtlasTaskCommitVerificationGate.php',
        'NormalizesPostStartGateInput.php',
        'TaskOutcomeLearningCandidate.php',
    ];

    /** @var array<string, string> basename prefix => subdir */
    private const PREFIX_ROUTES = [
        'AgentControlPlane' => 'ControlPlane',
        'AgentAutomatic' => 'ControlPlane',
        'AgentDispatch' => 'ControlPlane',
        'AgentMerge' => 'ControlPlane',
        'AgentProvider' => 'ControlPlane',
        'AgentRuntime' => 'ControlPlane',
        'AtlasSelfConstructionReadiness' => 'Readiness',
        'ReadinessProjection' => 'Readiness',
        'AtlasMaestro' => 'Maestro',
        'AtlasTask' => 'TaskServing',
        'AtlasSelfConstruction' => 'NativeImplementation',
    ];

    public function plan(): array
    {
        $root = base_path('app/Services/Ai/SelfConstruction');
        $moves = [];
        $kept = [];
        foreach (glob($root.'/*.php') ?: [] as $file) {
            $basename = basename($file);
            if (in_array($basename, self::KEEP_AT_ROOT, true)) {
                $kept[] = $basename;
                continue;
            }
            $subdir = $this->resolveSubdir($basename);
            $moves[] = [
                'from' => $this->rel($file),
                'to' => 'app/Services/Ai/SelfConstruction/'.$subdir.'/'.$basename,
                'subdir' => $subdir,
                'class' => str_replace('.php', '', $basename),
            ];
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'root_file_count' => count(glob($root.'/*.php') ?: []),
            'keep_count' => count($kept),
            'move_count' => count($moves),
            'target_root_after' => count($kept),
            'kept' => $kept,
            'moves' => $moves,
        ];
    }

    public function organize(bool $dryRun = false): array
    {
        $plan = $this->plan();
        $moved = [];
        $failed = [];
        $importUpdates = 0;

        foreach ($plan['moves'] as $row) {
            $from = base_path((string) $row['from']);
            $to = base_path((string) $row['to']);
            $subdir = (string) $row['subdir'];
            $class = (string) $row['class'];
            if (! is_file($from)) {
                continue;
            }
            if ($dryRun) {
                $moved[] = $row;
                continue;
            }
            try {
                File::ensureDirectoryExists(dirname($to));
                $src = File::get($from);
                $newNs = 'App\\Services\\Ai\\SelfConstruction\\'.$subdir;
                $src = preg_replace(
                    '/^namespace\s+App\\\\Services\\\\Ai\\\\SelfConstruction;/m',
                    'namespace '.$newNs.';',
                    $src,
                    1
                ) ?? $src;
                File::put($to, $src);
                File::delete($from);
                $importUpdates += $this->rewriteImports($class, $newNs.'\\'.$class);
                $moved[] = $row;
            } catch (\Throwable $e) {
                $failed[] = ['file' => $row['from'], 'error' => $e->getMessage()];
            }
        }

        $persistPath = storage_path('app/atlas/elite-compaction/sc-organize-'.now()->format('Y-m-d-His').'.json');
        if (! $dryRun) {
            File::ensureDirectoryExists(dirname($persistPath));
            File::put($persistPath, json_encode([
                'schema' => self::SCHEMA_VERSION,
                'moved_count' => count($moved),
                'failed_count' => count($failed),
                'import_updates' => $importUpdates,
                'moved' => $moved,
                'failed' => $failed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'dry_run' => $dryRun,
            'moved_count' => count($moved),
            'failed_count' => count($failed),
            'import_updates' => $importUpdates,
            'root_after' => $plan['target_root_after'],
            'persisted_at' => $dryRun ? null : $persistPath,
            'failed' => $failed,
        ];
    }

    private function resolveSubdir(string $basename): string
    {
        foreach (self::PREFIX_ROUTES as $prefix => $subdir) {
            if (str_starts_with($basename, $prefix)) {
                return $subdir;
            }
        }

        return 'Support';
    }

    private function rewriteImports(string $class, string $fqcn): int
    {
        $oldUse = 'App\\Services\\Ai\\SelfConstruction\\'.$class;
        $roots = [
            base_path('app'),
            base_path('bootstrap'),
            base_path('config'),
            base_path('routes'),
            base_path('tests'),
            base_path('database'),
        ];
        $updated = 0;
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $content = File::get($path);
                $newContent = str_replace($oldUse, $fqcn, $content);
                if ($newContent !== $content) {
                    File::put($path, $newContent);
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /**
     * Obra 3 / ELITE-02: repair stale root FQCNs for classes already under subdirs.
     * Also rewrites relative `Readiness\Foo` inside the Readiness namespace.
     *
     * @return array{schema: string, import_updates: int, files_touched: int}
     */
    public function repairMovedFqcnImports(bool $dryRun = false): array
    {
        $root = base_path('app/Services/Ai/SelfConstruction');
        $map = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $rel = ltrim(str_replace($root.'/', '', $path), '/');
            $parts = explode('/', $rel);
            $class = basename((string) array_pop($parts), '.php');
            $subdir = $parts === [] ? '' : implode('\\', $parts);
            $fqcn = 'App\\Services\\Ai\\SelfConstruction'.($subdir !== '' ? '\\'.$subdir : '').'\\'.$class;
            $old = 'App\\Services\\Ai\\SelfConstruction\\'.$class;
            if ($old !== $fqcn) {
                $map[$old] = $fqcn;
            }
        }

        $filesTouched = 0;
        $importUpdates = 0;
        $scanRoots = [
            base_path('app'),
            base_path('bootstrap'),
            base_path('config'),
            base_path('routes'),
            base_path('tests'),
            base_path('database'),
        ];
        foreach ($scanRoots as $scanRoot) {
            if (! is_dir($scanRoot)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scanRoot));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $content = File::get($path);
                $newContent = $content;
                foreach ($map as $old => $fqcn) {
                    if (str_contains($newContent, $old)) {
                        $newContent = str_replace($old, $fqcn, $newContent);
                        $importUpdates++;
                    }
                }
                // Mother in Readiness\: relative Readiness\X → X
                if (str_contains($path, '/SelfConstruction/Readiness/') && str_contains($newContent, 'Readiness\\')) {
                    $rewritten = preg_replace(
                        '/(?<![\\\\A-Za-z0-9_])Readiness\\\\(Readiness[A-Za-z0-9_]+)/',
                        '$1',
                        $newContent
                    );
                    if (is_string($rewritten) && $rewritten !== $newContent) {
                        $newContent = $rewritten;
                        $importUpdates++;
                    }
                }
                if ($newContent !== $content) {
                    $filesTouched++;
                    if (! $dryRun) {
                        File::put($path, $newContent);
                    }
                }
            }
        }

        return [
            'schema' => 'atlas.elite_compaction.sc_fqcn_repair.v1',
            'dry_run' => $dryRun,
            'import_updates' => $importUpdates,
            'files_touched' => $filesTouched,
            'mapped_classes' => count($map),
        ];
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace(base_path().'/', '', $path), '/');
    }
}
