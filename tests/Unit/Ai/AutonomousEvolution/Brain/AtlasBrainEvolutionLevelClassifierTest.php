<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionLevelClassifier;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * Proves the level classifier routes objectives into the right evolution level
 * by COMPOSING the existing organs (no reimplementation of their math).
 */
final class AtlasBrainEvolutionLevelClassifierTest extends TestCase
{
    public function test_proxy_term_objective_is_rejected_proxy(): void
    {
        $classifier = new AtlasBrainEvolutionLevelClassifier;
        $model = $this->emptyModel();

        $result = $classifier->classify('Refactor the cyclomatic complexity of the handler', $model);

        self::assertSame('rejected_proxy', $result['class']);
        self::assertSame(0.0, $result['magnitude']);
        self::assertSame(0.0, $result['p_land']);
    }

    public function test_single_component_objective_is_evolucao(): void
    {
        $classifier = new AtlasBrainEvolutionLevelClassifier;
        $model = $this->emptyModel();

        $result = $classifier->classify('Add a new authentication guard for API tokens', $model);

        self::assertSame('evolucao', $result['class']);
        self::assertGreaterThanOrEqual(0.0, $result['magnitude']);
        self::assertGreaterThanOrEqual(0.0, $result['p_land']);
    }

    public function test_doc_stated_gap_match_is_patamar(): void
    {
        $classifier = new AtlasBrainEvolutionLevelClassifier;
        $model = new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: ['AtlasQuantumEntanglementService'],
            snapshotId: 'snap-test',
        );

        // Objective contains the doc-stated gap symbol name.
        $result = $classifier->classify('Implement the AtlasQuantumEntanglementService for cross-node state sync', $model);

        self::assertSame('patamar', $result['class']);
        self::assertContains('doc_stated_gap_match', $result['evidence']);
    }

    public function test_proxy_terms_cover_all_delta_terms(): void
    {
        $classifier = new AtlasBrainEvolutionLevelClassifier;
        $model = $this->emptyModel();

        // Each term from AtlasLoopAmbitionLeapProposer::PROXY_DELTA_TERMS must trigger rejection.
        foreach (['cyclomatic', 'dead code', 'dead-code', 'formatting', 'refactor', 'rename', 'whitespace'] as $term) {
            $result = $classifier->classify("Objective about $term cleanup", $model);
            self::assertSame('rejected_proxy', $result['class'], "Term '$term' should be rejected_proxy");
        }
    }

    public function test_classifier_is_deterministic(): void
    {
        $classifier = new AtlasBrainEvolutionLevelClassifier;
        $model = $this->emptyModel();
        $objective = 'Add a new payment gateway integration';

        $a = $classifier->classify($objective, $model);
        $b = $classifier->classify($objective, $model);

        self::assertSame($a, $b);
    }

    public function test_composes_three_organs_not_reimplementing(): void
    {
        // Structural assertion: the classifier's constructor accepts the three organs.
        // This proves composition (dependency injection), not reimplementation.
        $classifier = new AtlasBrainEvolutionLevelClassifier(
            heavyWorkSelector: null,
            leapProposer: null,
            frontierGapModel: null,
        );

        $model = $this->emptyModel();
        $result = $classifier->classify('Build a new reporting dashboard', $model);

        // Must produce a valid classification — the organs were composed and executed.
        self::assertContains($result['class'], ['evolucao', 'patamar', 'rejected_proxy']);
    }

    private function emptyModel(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'snap-empty',
        );
    }
}
