<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepDriftDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Arms the dormant {@see AtlasLoopExternalDepDriftDetector::detect()} at the operator surface: reads the
 * project's composer.lock + composer.json and emits the dependency drift facts (added/removed packages, version
 * changes, content-hash change, unexpected lock-only drift) vs. the last snapshot. The detector keeps its own
 * snapshot bookkeeping under the configured root.
 */
final class AtlasLoopExternalDepDriftCommand extends Command
{
    protected $signature = 'atlas:loop:external-dep-drift {--json}';

    protected $description = 'External-dependency drift facts (composer.lock/.json vs. the last snapshot).';

    public function handle(): int
    {
        $root = (string) config('atlas.loop.external_deps.snapshot_root', storage_path(AtlasLoopExternalDepDriftDetector::SNAPSHOT_DIR));
        File::ensureDirectoryExists($root);

        $report = (new AtlasLoopExternalDepDriftDetector($root))->detect(
            $this->readJson(base_path('composer.lock')),
            $this->readJson(base_path('composer.json')),
        );

        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
