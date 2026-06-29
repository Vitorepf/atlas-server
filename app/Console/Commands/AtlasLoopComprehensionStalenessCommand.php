<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionStalenessDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopComprehensionStalenessDetector::detect()} at the operator surface: reports
 * whether a comprehension snapshot at --path is stale (missing, or older than the threshold) as deterministic
 * facts (is_stale, snapshot_age_seconds, threshold_seconds, reason). Read-only.
 */
final class AtlasLoopComprehensionStalenessCommand extends Command
{
    protected $signature = 'atlas:loop:comprehension-staleness {--path=} {--json}';

    protected $description = 'Read-only comprehension-snapshot staleness check for a given path.';

    public function handle(AtlasLoopComprehensionStalenessDetector $detector): int
    {
        $path = trim((string) $this->option('path'));
        if ($path === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'comprehension-staleness requires --path=<path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode($detector->detect($path), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
