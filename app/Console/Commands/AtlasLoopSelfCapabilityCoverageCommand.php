<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfCapabilityCoverageReporter;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopSelfCapabilityCoverageReporter} at the operator surface for the first time: a
 * read-only scan that emits the built-vs-armed coverage map of the loop's canonical capabilities as facts-only
 * structure (schema atlas.loop.self_capability_coverage_facts.v1: per-capability state MISSING/BUILT_ONLY/
 * BUILT/ARMED with class_fqcn + wiring_evidence, plus has_built_only) as JSON. No queue/DB/provider/process.
 */
final class AtlasLoopSelfCapabilityCoverageCommand extends Command
{
    protected $signature = 'atlas:loop:self-capability-coverage {--json}';

    protected $description = 'Read-only built-vs-armed coverage map of the loop canonical capabilities (facts only).';

    public function handle(AtlasLoopSelfCapabilityCoverageReporter $reporter): int
    {
        $facts = $reporter->scan();
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
