<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopSignalAnalyzer::scan()} at the operator surface: a read-only structural-signal
 * scan over the AutonomousEvolution tree, emitting the deterministic flags (code clones, complexity hotspots,
 * coverage gaps, doc drift/duplication) and the summary counts as JSON. Facts only — no edit, no score.
 */
final class AtlasLoopSignalScanCommand extends Command
{
    protected $signature = 'atlas:loop:signal-scan {--json}';

    protected $description = 'Read-only structural-signal scan of the AutonomousEvolution tree (clones / complexity / coverage / doc).';

    public function handle(AtlasLoopSignalAnalyzer $analyzer): int
    {
        $result = $analyzer->scan(base_path(), ['code_roots' => ['app/Services/Ai/AutonomousEvolution']]);

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
