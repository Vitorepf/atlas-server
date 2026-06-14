<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHeavyWorkSelector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTrustLadder;
use Tests\TestCase;

/**
 * THE HEAVY-WORK BRAIN — frozen proof of the composed decision: panel value -> ambition (biggest leap
 * wins) -> trust ladder (auto-merge only for a proven class) -> verification tier. Attempt the biggest
 * leap; merge only what is proven. A big leap of an UNPROVEN class wins selection but PARKS; a proven
 * class is auto-merge eligible.
 */
final class AtlasLoopHeavyWorkSelectorTest extends TestCase
{
    private function selector(): AtlasLoopHeavyWorkSelector
    {
        return new AtlasLoopHeavyWorkSelector();
    }

    public function test_the_biggest_leap_wins_selection_but_parks_until_its_class_earns_trust(): void
    {
        $result = $this->selector()->select(
            [
                ['candidateId' => 'big_obra', 'class' => 'big_obra', 'node_count' => 10, 'evidence' => ['refactor_leverage' => 0.9, 'cyclomatic_total' => 100, 'failure_evidence' => 0.9, 'blast_radius' => 4]],
                ['candidateId' => 'small_proven', 'class' => 'proven_refactor', 'node_count' => 2, 'evidence' => ['refactor_leverage' => 0.6, 'cyclomatic_total' => 40, 'failure_evidence' => 0.4]],
            ],
            ['class_stats' => [
                'big_obra' => ['successes' => 0, 'failures' => 0],         // no track record
                'proven_refactor' => ['successes' => 50, 'failures' => 0], // long proven record
            ]],
        );

        $this->assertSame('big_obra', $result['pick']['candidateId'], 'ambition: the biggest leap is attempted');
        $this->assertSame('maximal', $result['pick']['required_verification']['tier'], 'an enormous leap must clear every gate');
        $this->assertSame('park_for_operator_until_trust_earned', $result['pick']['gate'], 'an unproven big class never auto-merges');

        $proven = collect($result['ranked'])->firstWhere('candidateId', 'small_proven');
        $this->assertSame(AtlasLoopTrustLadder::AUTONOMOUS_MERGE, $proven['trust']['level']);
        $this->assertSame('autonomous_merge_eligible', $proven['gate'], 'a proven class is eligible for the governed auto-merge');
    }

    public function test_under_evidenced_candidate_does_not_compete(): void
    {
        $result = $this->selector()->select(
            [
                ['candidateId' => 'rich', 'class' => 'c', 'node_count' => 3, 'evidence' => ['refactor_leverage' => 0.7, 'cyclomatic_total' => 60, 'failure_evidence' => 0.5]],
                ['candidateId' => 'thin', 'class' => 'c', 'node_count' => 9, 'evidence' => ['refactor_leverage' => 0.99]], // 1 signal — excluded by the panel quorum
            ],
            ['class_stats' => []],
        );
        $this->assertSame('rich', $result['pick']['candidateId']);
        $this->assertCount(1, $result['ranked'], 'the under-evidenced candidate never reaches the ambition stage');
    }
}
