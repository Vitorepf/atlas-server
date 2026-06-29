<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopServingQueueDiskConformanceSentinel;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopServingQueueDiskConformanceSentinel::check()} at the operator surface:
 * emits whether the live serving queue is reading its dedicated disk (the expected disk from config matches
 * the disk the health service's queue repository actually resolves) as deterministic facts — surfacing the
 * drift when it diverges. Read-only — it only inspects the resolved disk; it moves nothing.
 */
final class AtlasLoopServingQueueDiskConformanceCommand extends Command
{
    protected $signature = 'atlas:loop:serving-queue-disk-conformance {--json}';

    protected $description = 'Read-only: is the serving queue on its dedicated disk (env vs resolved)?';

    public function handle(AtlasLoopServingQueueDiskConformanceSentinel $sentinel): int
    {
        $this->line((string) json_encode($sentinel->check(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
