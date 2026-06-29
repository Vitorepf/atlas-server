<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfDependencyGraphReporter;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopSelfDependencyGraphReporter} at the operator surface for the first time: a
 * read-only parse of the bounded app/Services/Ai/AutonomousEvolution tree that emits the constructor-dependency
 * graph as deterministic structural facts (schema atlas.loop.self_dependency_graph_facts.v1: nodes, edges,
 * graph_depth, graph_width, cycles) as JSON. No queue/DB/provider/process — facts only.
 */
final class AtlasLoopSelfDependencyGraphCommand extends Command
{
    protected $signature = 'atlas:loop:self-dependency-graph {--json}';

    protected $description = 'Read-only constructor-dependency graph of the AutonomousEvolution tree (structural facts only).';

    public function handle(AtlasLoopSelfDependencyGraphReporter $reporter): int
    {
        $facts = $reporter->scan();
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
