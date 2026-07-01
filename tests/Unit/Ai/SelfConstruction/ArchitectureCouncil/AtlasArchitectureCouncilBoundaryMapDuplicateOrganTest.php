<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ArchitectureCouncil;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilBoundaryMap;
use PHPUnit\Framework\TestCase;

/**
 * Pins responsibility_overlaps: two or more organs claiming the SAME responsibility must be
 * surfaced as a consolidation boundary risk, with a deterministic canonical_owner and the other
 * organ(s) listed as collapse_candidate. Clean, non-overlapping ownership reports none and
 * preserves the existing boundary map shape.
 */
final class AtlasArchitectureCouncilBoundaryMapDuplicateOrganTest extends TestCase
{
    public function test_two_organs_claiming_the_same_responsibility_emit_an_overlap_entry(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            [
                'organ' => 'Task Fabric',
                'non_authority' => ['cannot verify'],
                'responsibilities' => ['origination'],
                'integrations' => [],
            ],
            [
                'organ' => 'Worker Swarm',
                'non_authority' => ['cannot merge'],
                'responsibilities' => ['origination'],
                'integrations' => [],
            ],
        ]);

        $this->assertTrue($r['has_responsibility_overlaps']);
        $this->assertCount(1, $r['responsibility_overlaps']);

        $overlap = $r['responsibility_overlaps'][0];
        $this->assertSame('origination', $overlap['responsibility']);
        $this->assertSame(['Task Fabric', 'Worker Swarm'], $overlap['organs']);
        $this->assertSame('Task Fabric', $overlap['canonical_owner']);
        $this->assertSame(['Worker Swarm'], $overlap['collapse_candidate']);
    }

    public function test_clean_layer_ownership_preserves_existing_shape_and_reports_no_overlaps(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            [
                'organ' => 'Task Fabric',
                'non_authority' => ['cannot verify'],
                'responsibilities' => ['origination'],
                'integrations' => [
                    ['from' => 'Task Fabric', 'to' => 'Worker Swarm', 'action' => 'enqueue_packet'],
                ],
            ],
            [
                'organ' => 'Worker Swarm',
                'non_authority' => ['cannot merge'],
                'responsibilities' => ['execution'],
                'integrations' => [
                    ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'submit_evidence'],
                ],
            ],
        ]);

        $this->assertFalse($r['has_responsibility_overlaps']);
        $this->assertSame([], $r['responsibility_overlaps']);

        // Existing shape preserved.
        $this->assertSame([], $r['forbidden_edges']);
        $this->assertNotEmpty($r['allowed_edges']);
        $this->assertArrayHasKey('organ_profiles', $r);
        $this->assertSame(AtlasArchitectureCouncilBoundaryMap::SCHEMA, $r['schema']);
    }

    public function test_three_organs_claiming_the_same_responsibility_lists_all_as_collapse_candidates(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'responsibilities' => ['scheduling'], 'integrations' => []],
            ['organ' => 'Strategy Council', 'non_authority' => ['y'], 'responsibilities' => ['scheduling'], 'integrations' => []],
            ['organ' => 'Task Fabric', 'non_authority' => ['z'], 'responsibilities' => ['scheduling'], 'integrations' => []],
        ]);

        $this->assertCount(1, $r['responsibility_overlaps']);
        $overlap = $r['responsibility_overlaps'][0];
        $this->assertSame('Strategy Council', $overlap['canonical_owner']);
        $this->assertSame(['Task Fabric', 'Worker Swarm'], $overlap['collapse_candidate']);
    }

    public function test_no_overlap_when_organs_have_distinct_responsibilities(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'responsibilities' => ['origination'], 'integrations' => []],
            ['organ' => 'Worker Swarm', 'non_authority' => ['y'], 'responsibilities' => ['execution'], 'integrations' => []],
        ]);

        $this->assertSame([], $r['responsibility_overlaps']);
    }

    public function test_boundary_map_with_overlaps_is_deterministic(): void
    {
        $contracts = [
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'responsibilities' => ['origination'], 'integrations' => []],
            ['organ' => 'Worker Swarm', 'non_authority' => ['y'], 'responsibilities' => ['origination'], 'integrations' => []],
        ];

        $map = new AtlasArchitectureCouncilBoundaryMap;
        $this->assertSame($map->map($contracts), $map->map($contracts));
    }

    // ── merge/delete candidates + consumers (AC) ──────────────────────────────

    public function test_collapse_candidate_with_no_consumers_gets_delete_action(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'responsibilities' => ['origination'], 'integrations' => []],
            ['organ' => 'Worker Swarm', 'non_authority' => ['y'], 'responsibilities' => ['origination'], 'integrations' => []],
        ]);

        $candidates = $r['responsibility_overlaps'][0]['merge_delete_candidates'];
        $this->assertCount(1, $candidates);
        $this->assertSame('Worker Swarm', $candidates[0]['organ']);
        $this->assertSame([], $candidates[0]['consumers']);
        $this->assertSame('delete', $candidates[0]['action']);
    }

    public function test_collapse_candidate_with_consumers_gets_merge_action_and_lists_consumers(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'responsibilities' => ['origination'], 'integrations' => []],
            ['organ' => 'Worker Swarm', 'non_authority' => ['y'], 'responsibilities' => ['origination'], 'integrations' => []],
            [
                'organ' => 'Maestro',
                'non_authority' => ['z'],
                'responsibilities' => [],
                'integrations' => [
                    ['from' => 'Maestro', 'to' => 'Worker Swarm', 'action' => 'route_task'],
                ],
            ],
        ]);

        $candidates = $r['responsibility_overlaps'][0]['merge_delete_candidates'];
        $this->assertSame('Worker Swarm', $candidates[0]['organ']);
        $this->assertSame(['Maestro'], $candidates[0]['consumers']);
        $this->assertSame('merge', $candidates[0]['action']);
        $this->assertNotEmpty($candidates[0]['risk_notes']);
    }
}
