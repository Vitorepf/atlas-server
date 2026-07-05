<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHeavyWorkSelector;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopHeavyWorkSelector's adaptive panel floor: behind a config
 * flag defaulting off, a high capability_factor relaxes the value floor so
 * the loop surfaces a wider leverage surface instead of only the highest-value
 * candidate.
 */
final class AtlasLoopHeavyWorkSelectorAdaptiveFloorTest extends TestCase
{
    private AtlasLoopHeavyWorkSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new AtlasLoopHeavyWorkSelector;
    }

    /**
     * A candidate with only 1 measured signal (below the panel's QUORUM of 2)
     * is excluded when the adaptive floor is OFF.
     */
    public function test_under_evidenced_candidate_is_excluded_when_adaptive_floor_off(): void
    {
        $candidates = [
            [
                'candidateId' => 'fully-measured',
                'kind' => 'refactor',
                'class' => 'trusted_class',
                'node_count' => 2,
                'evidence' => [
                    'refactor_leverage' => 0.8,
                    'cyclomatic_total' => 80,
                ],
            ],
            [
                'candidateId' => 'under-evidenced',
                'kind' => 'refactor',
                'class' => 'trusted_class',
                'node_count' => 5,
                'evidence' => [
                    'refactor_leverage' => 0.5,
                    // Only 1 signal — below QUORUM of 2.
                ],
            ],
        ];

        $result = $this->selector->select($candidates, [
            'adaptive_floor_enabled' => false,
            'capability_factor' => 0.8,
        ]);

        // The under-evidenced candidate must NOT appear in ranked (excluded by panel).
        $rankedIds = array_map(
            static fn (array $r): string => (string) ($r['candidateId'] ?? ''),
            $result['ranked'] ?? [],
        );
        $this->assertContains('fully-measured', $rankedIds);
        $this->assertNotContains('under-evidenced', $rankedIds,
            'under-evidenced candidate must be excluded when adaptive floor is off');
    }

    /**
     * With adaptive floor ON and high capability_factor, under-evidenced
     * candidates are given a baseline score and compete in the ranking.
     */
    public function test_under_evidenced_candidate_is_included_when_adaptive_floor_on(): void
    {
        $candidates = [
            [
                'candidateId' => 'fully-measured',
                'kind' => 'refactor',
                'class' => 'trusted_class',
                'node_count' => 2,
                'evidence' => [
                    'refactor_leverage' => 0.8,
                    'cyclomatic_total' => 80,
                ],
            ],
            [
                'candidateId' => 'under-evidenced',
                'kind' => 'refactor',
                'class' => 'trusted_class',
                'node_count' => 5,
                'evidence' => [
                    'refactor_leverage' => 0.5,
                    // Only 1 signal — below QUORUM of 2.
                ],
            ],
        ];

        $result = $this->selector->select($candidates, [
            'adaptive_floor_enabled' => true,
            'capability_factor' => 0.8,
        ]);

        $rankedIds = array_map(
            static fn (array $r): string => (string) ($r['candidateId'] ?? ''),
            $result['ranked'] ?? [],
        );
        $this->assertContains('under-evidenced', $rankedIds,
            'under-evidenced candidate must be included when adaptive floor is on');
    }

    /**
     * When adaptive floor is ON but capability_factor is low, the floor
     * stays restrictive (no change).
     */
    public function test_low_capability_does_not_relax_floor_even_when_flag_on(): void
    {
        $candidates = [
            [
                'candidateId' => 'fully-measured',
                'kind' => 'refactor',
                'class' => 'trusted_class',
                'node_count' => 2,
                'evidence' => [
                    'refactor_leverage' => 0.8,
                    'cyclomatic_total' => 80,
                ],
            ],
            [
                'candidateId' => 'under-evidenced',
                'kind' => 'refactor',
                'class' => 'trusted_class',
                'node_count' => 5,
                'evidence' => [
                    'refactor_leverage' => 0.5,
                ],
            ],
        ];

        $result = $this->selector->select($candidates, [
            'adaptive_floor_enabled' => true,
            'capability_factor' => 0.3,
        ]);

        $rankedIds = array_map(
            static fn (array $r): string => (string) ($r['candidateId'] ?? ''),
            $result['ranked'] ?? [],
        );
        $this->assertNotContains('under-evidenced', $rankedIds,
            'under-evidenced candidate must stay excluded when capability_factor is low');
    }
}
