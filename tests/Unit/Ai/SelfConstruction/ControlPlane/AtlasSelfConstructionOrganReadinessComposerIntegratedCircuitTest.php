<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionOrganReadinessComposer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the integrated circuit-path readiness model: every organ present
 * AND every canonical circuit edge connected.
 */
final class AtlasSelfConstructionOrganReadinessComposerIntegratedCircuitTest extends TestCase
{
    private AtlasSelfConstructionOrganReadinessComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new AtlasSelfConstructionOrganReadinessComposer();
    }

    /**
     * Fixture helper: every canonical organ present, ready, fresh, with evidence.
     * Optionally drop one circuit edge.
     *
     * @param  string|null  $missingEdge  edge key to omit from the circuit_edges array
     */
    private function fullCircuitFixture(?string $missingEdge = null): array
    {
        $organs = [];
        foreach (AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS as $organ) {
            $organs[$organ] = [
                'status' => 'ready',
                'evidence_refs' => ['ref/' . $organ],
                'last_verified_at' => '2026-06-30T12:00:00Z',
                'freshness_status' => 'fresh',
            ];
        }

        $edges = [];
        foreach (AtlasSelfConstructionOrganReadinessComposer::CIRCUIT_EDGES as $edge) {
            if ($edge === $missingEdge) {
                continue;
            }
            $edges[$edge] = ['connected' => true];
        }

        return [
            'organs' => $organs,
            'circuit_edges' => $edges,
        ];
    }

    public function test_complete_circuit_returns_ready_true_with_path_summary(): void
    {
        $fixture = $this->fullCircuitFixture(null);
        $result = $this->composer->compose($fixture['organs'], $fixture['circuit_edges']);

        $this->assertTrue($result['all_ready'], 'all_ready must be true for complete circuit');
        $this->assertTrue($result['circuit_complete'], 'circuit_complete must be true');
        $this->assertArrayHasKey('circuit_path', $result);
        $this->assertNotEmpty($result['circuit_path']);
        // Path should be the canonical organ chain
        $this->assertSame(
            AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS,
            $result['circuit_path']
        );
    }

    public function test_missing_proof_to_outcome_learning_edge_returns_ready_false(): void
    {
        $fixture = $this->fullCircuitFixture('proof_to_outcome_learning');
        $result = $this->composer->compose($fixture['organs'], $fixture['circuit_edges']);

        $this->assertFalse($result['all_ready'], 'all_ready must be false when an edge is missing');
        $this->assertFalse($result['circuit_complete']);
        $this->assertContains('proof_to_outcome_learning', $result['missing_edges']);
        $this->assertSame('proof_to_outcome_learning', $result['missing_edge']);
    }

    public function test_all_organs_present_but_circuit_edge_missing_still_fails(): void
    {
        $fixture = $this->fullCircuitFixture('verification_court_to_merge_governor');
        $result = $this->composer->compose($fixture['organs'], $fixture['circuit_edges']);

        $this->assertFalse($result['all_ready']);
        $this->assertFalse($result['circuit_complete']);
        $this->assertContains('verification_court_to_merge_governor', $result['missing_edges']);
    }
}
