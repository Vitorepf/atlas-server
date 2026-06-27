<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COMPOSED SELF-KNOWLEDGE REPORT — comprehension-deepening organ. Single façade that calls the
 * coverage matrix, dependency graph, frontier catalog, and schema contract registry to produce
 * one consolidated "what does the brain know about itself" payload. Pure composition over
 * already-built read-only organs.
 *
 * Pétreo: réu would hide one of the layers to launder a missing-organ or rogue-dependency.
 */
final class AtlasBrainComposedSelfKnowledgeReport
{
    public const SCHEMA = 'atlas.brain.composed_self_knowledge_report.v1';

    public function __construct(
        private readonly AtlasBrainCoverageMatrix $coverage,
        private readonly AtlasBrainOrganDependencyGraph $graph,
        private readonly AtlasBrainFrontierMethodCatalog $catalog,
        private readonly AtlasBrainSchemaContractRegistry $schemas,
    ) {}

    /**
     * @return array{schema:string, coverage:array<string,int>, dependency_graph:array<class-string, list<class-string>>, frontier:array, schema_count:int}
     */
    public function build(): array
    {
        return [
            'schema' => self::SCHEMA,
            'coverage' => $this->coverage->coverageByPath(),
            'dependency_graph' => $this->graph->graph(),
            'frontier' => $this->catalog->inspect(),
            'schema_count' => $this->schemas->count(),
        ];
    }
}
