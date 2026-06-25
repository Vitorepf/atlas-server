<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepDriftDetector;
use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepInventoryReporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only external-deps CLI: inventory | drift | history.
 *
 * NEVER writes composer.json or composer.lock. NEVER calls composer. NEVER contacts the network.
 * Drift is a FACT, never a failure — the operator decides.
 */
final class AtlasLoopExternalDepCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:deps
        {action : inventory|drift|history}
        {--limit=10 : history row cap}
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'External-deps FACT CLI: inventory | drift | history (no composer, no network).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        try {
            return match ($action) {
                'inventory' => $this->doInventory($json),
                'drift' => $this->doDrift($json),
                'history' => $this->doHistory($json),
                default => $this->failJson('unknown_action:'.$action, $json),
            };
        } catch (Throwable $e) {
            return $this->failJson($e->getMessage(), $json);
        }
    }

    private function doInventory(bool $json): int
    {
        $reporter = $this->reporter();
        $payload = $reporter->inventory();
        $this->emit($json, $payload);

        return self::SUCCESS;
    }

    private function doDrift(bool $json): int
    {
        $detector = $this->detector();
        [$lock, $jsonContent] = $this->loadLockAndJson();
        $report = $detector->detect($lock, $jsonContent);
        $this->emit($json, $report);

        return self::SUCCESS; // drift is a FACT, not a failure
    }

    private function doHistory(bool $json): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = $this->snapshotHistory($limit);
        $this->emit($json, ['snapshots' => $rows]);

        return self::SUCCESS;
    }

    private function reporter(): AtlasLoopExternalDepInventoryReporter
    {
        if (app()->bound('atlas.loop.deps.reporter')) {
            $bound = app('atlas.loop.deps.reporter');
            if ($bound instanceof AtlasLoopExternalDepInventoryReporter) {
                return $bound;
            }
        }
        [$composerJson, $composerLock] = $this->composerPaths();

        return new AtlasLoopExternalDepInventoryReporter($composerJson, $composerLock);
    }

    private function detector(): AtlasLoopExternalDepDriftDetector
    {
        if (app()->bound('atlas.loop.deps.detector')) {
            $bound = app('atlas.loop.deps.detector');
            if ($bound instanceof AtlasLoopExternalDepDriftDetector) {
                return $bound;
            }
        }

        return new AtlasLoopExternalDepDriftDetector($this->snapshotRoot());
    }

    /**
     * @return array{0:array<string,mixed>, 1:array<string,mixed>}
     */
    private function loadLockAndJson(): array
    {
        [$composerJson, $composerLock] = $this->composerPaths();
        $lock = is_file($composerLock) ? (array) json_decode((string) file_get_contents($composerLock), true) : [];
        $jsonContent = is_file($composerJson) ? (array) json_decode((string) file_get_contents($composerJson), true) : [];

        return [$lock, $jsonContent];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function composerPaths(): array
    {
        if (app()->bound('atlas.loop.deps.paths')) {
            $bound = app('atlas.loop.deps.paths');
            if (is_array($bound) && isset($bound[0], $bound[1])) {
                return [(string) $bound[0], (string) $bound[1]];
            }
        }

        return [base_path('composer.json'), base_path('composer.lock')];
    }

    private function snapshotRoot(): string
    {
        if (app()->bound('atlas.loop.deps.snapshot_root')) {
            return (string) app('atlas.loop.deps.snapshot_root');
        }

        return storage_path('app');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function snapshotHistory(int $limit): array
    {
        $dir = $this->snapshotRoot().'/'.AtlasLoopExternalDepDriftDetector::SNAPSHOT_DIR;
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, $limit);

        $rows = [];
        foreach ($files as $path) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            $packageCount = is_array($decoded['packages'] ?? null) ? count($decoded['packages']) : 0;
            $rows[] = [
                'timestamp' => date('c', (int) filemtime($path)),
                'content_hash' => (string) ($decoded['content_hash'] ?? ''),
                'package_count' => $packageCount,
            ];
        }

        return $rows;
    }

    private function emit(bool $json, array $payload): void
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }
        $this->line(print_r($payload, true));
    }

    private function failJson(string $reason, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $reason], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($reason);
        }

        return self::FAILURE;
    }
}
