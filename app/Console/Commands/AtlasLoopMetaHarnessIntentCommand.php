<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMetaHarnessIntentSource;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopMetaHarnessIntentSource::candidates()} at the operator surface: enumerates
 * the real (non-pétreo) harness files under the repo root and emits the meta-harness self-improvement intent
 * candidates as deterministic facts (the material-fuel feed for the A/B meta arm). Read-only — it only reads
 * the tree and runs the guard-filtered source; it never enqueues. Double-flag-gated default-OFF ⇒ empty.
 */
final class AtlasLoopMetaHarnessIntentCommand extends Command
{
    protected $signature = 'atlas:loop:meta-harness-intent {--limit=} {--json}';

    protected $description = 'Read-only meta-harness self-improvement intent candidates (deterministic, guard-filtered).';

    public function handle(AtlasLoopMetaHarnessIntentSource $source): int
    {
        $limitOption = $this->option('limit');
        $limit = ($limitOption === null || trim((string) $limitOption) === '') ? 6 : max(1, (int) $limitOption);

        $candidates = $source->candidates(base_path(), $limit);

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.meta_harness_intent.v1',
            'count' => count($candidates),
            'candidates' => $candidates,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
