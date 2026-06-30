<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFrontierGapModel;
use PHPUnit\Framework\TestCase;

final class AtlasLoopFrontierGapModelTest extends TestCase
{
    public function test_zero_gaps_when_capability_is_rising_and_origination_delivers(): void
    {
        $gaps = (new AtlasLoopFrontierGapModel)->compute([
            'capability_trend' => [
                'buckets' => [
                    $this->bucket(1, 0.2),
                    $this->bucket(2, 0.4),
                    $this->bucket(3, 0.6),
                ],
            ],
            'origination_pipeline' => [
                'cycles' => [
                    $this->cycle(1, 'produced'),
                    $this->cycle(2, 'produced'),
                    $this->cycle(3, 'produced'),
                ],
            ],
        ]);

        $this->assertSame([], $gaps);
    }

    public function test_flat_trend_and_consecutive_abstain_cycles_emit_frontier_gap(): void
    {
        $gaps = (new AtlasLoopFrontierGapModel)->compute([
            'comprehension_originator' => [
                'orphans' => [
                    ['id' => 'originator:orphan:AtlasLoopAmbitionLeapProposer', 'scope' => 'loop-origination'],
                ],
            ],
            'capability_trend' => [
                'buckets' => [
                    $this->bucket(1, 0.5),
                    $this->bucket(2, 0.5),
                    $this->bucket(3, 0.5),
                ],
            ],
            'attempt_ledger' => [
                'rows' => [
                    ['id' => 'attempt:round:17', 'scope' => 'loop-origination', 'passed' => false],
                ],
            ],
            'origination_pipeline' => [
                'cycles' => [
                    $this->cycle(1, 'abstain', 'abstain_and_ask'),
                    $this->cycle(2, 'abstain', 'abstain_and_ask'),
                    $this->cycle(3, 'abstain', 'abstain_and_ask'),
                ],
            ],
        ]);

        $this->assertCount(1, $gaps);
        $this->assertSame('FrontierGap', $gaps[0]['record_type']);
        $this->assertSame('loop-origination', $gaps[0]['scope']);
        $this->assertTrue($gaps[0]['plateau_signal']);
        $this->assertSame([
            'attempt:round:17',
            'originator:orphan:AtlasLoopAmbitionLeapProposer',
            'pipeline:cycle:1',
            'pipeline:cycle:2',
            'pipeline:cycle:3',
            'trend:bucket:1',
            'trend:bucket:2',
            'trend:bucket:3',
        ], $gaps[0]['evidence_refs']);
        $this->assertStringStartsWith('frontier_gap:', $gaps[0]['gap_id']);
    }

    public function test_emitted_gap_requires_canonical_evidence_id(): void
    {
        $gaps = (new AtlasLoopFrontierGapModel)->compute([
            'capability_trend' => [
                'buckets' => [
                    ['scope' => 'loop-origination', 'index' => 1, 'total' => 3, 'rate' => 0.5],
                    ['scope' => 'loop-origination', 'index' => 2, 'total' => 3, 'rate' => 0.5],
                    ['scope' => 'loop-origination', 'index' => 3, 'total' => 3, 'rate' => 0.5],
                ],
            ],
            'origination_pipeline' => [
                'cycles' => [
                    ['scope' => 'loop-origination', 'action' => 'abstain'],
                    ['scope' => 'loop-origination', 'action' => 'abstain'],
                    ['scope' => 'loop-origination', 'action' => 'abstain'],
                ],
            ],
        ]);

        $this->assertSame([], $gaps, 'frontier gaps without persisted evidence ids are discarded instead of synthesized');
    }

    public function test_abstain_only_gap_carries_leverage_fields_and_classifies_operator_dependency(): void
    {
        // No orphan_inventory, no attempt_ledger — only capability buckets + abstain cycles.
        $gaps = (new AtlasLoopFrontierGapModel)->compute([
            'capability_trend' => [
                'buckets' => [$this->bucket(1, 0.5), $this->bucket(2, 0.5), $this->bucket(3, 0.5)],
            ],
            'origination_pipeline' => [
                'cycles' => [
                    $this->cycle(1, 'abstain', 'operator_question'),
                    $this->cycle(2, 'abstain', 'operator_question'),
                    $this->cycle(3, 'abstain', 'operator_question'),
                ],
            ],
        ]);

        $this->assertCount(1, $gaps);
        $g = $gaps[0];
        $this->assertArrayHasKey('next_leverage_class', $g);
        $this->assertArrayHasKey('leverage_reason', $g);
        $this->assertArrayHasKey('source_signal_counts', $g);
        $this->assertSame('operator_dependency', $g['next_leverage_class']);
        $this->assertNotEmpty($g['leverage_reason']);
        $this->assertSame(3, $g['source_signal_counts']['capability_buckets']);
        $this->assertSame(3, $g['source_signal_counts']['abstain_cycles']);
        $this->assertSame(0, $g['source_signal_counts']['orphan_inventory']);
        $this->assertSame(0, $g['source_signal_counts']['attempt_ledger']);
    }

    public function test_orphan_inventory_overrides_abstain_cycles_to_task_fabric(): void
    {
        $gaps = (new AtlasLoopFrontierGapModel)->compute([
            'comprehension_originator' => [
                'orphans' => [['id' => 'originator:orphan:AtlasMissingService', 'scope' => 'loop-origination']],
            ],
            'capability_trend' => [
                'buckets' => [$this->bucket(1, 0.5), $this->bucket(2, 0.5), $this->bucket(3, 0.5)],
            ],
            'origination_pipeline' => [
                'cycles' => [
                    $this->cycle(1, 'abstain', 'abstain_and_ask'),
                    $this->cycle(2, 'abstain', 'abstain_and_ask'),
                    $this->cycle(3, 'abstain', 'abstain_and_ask'),
                ],
            ],
        ]);

        $this->assertCount(1, $gaps);
        $this->assertSame('task_fabric', $gaps[0]['next_leverage_class'], 'orphan_inventory must override abstain_cycles');
        $this->assertGreaterThan(0, $gaps[0]['source_signal_counts']['abstain_cycles'], 'abstain signals still counted');
        $this->assertGreaterThan(0, $gaps[0]['source_signal_counts']['orphan_inventory']);
    }

    /** @return array{id:string,scope:string,index:int,total:int,rate:float,ended_at:string} */
    private function bucket(int $index, float $rate): array
    {
        return [
            'id' => "trend:bucket:{$index}",
            'scope' => 'loop-origination',
            'index' => $index,
            'total' => 5,
            'rate' => $rate,
            'ended_at' => "2026-06-24T10:0{$index}:00Z",
        ];
    }

    /** @return array{id:string,scope:string,action:string,reason:string,created_at:string} */
    private function cycle(int $index, string $action, string $reason = ''): array
    {
        return [
            'id' => "pipeline:cycle:{$index}",
            'scope' => 'loop-origination',
            'action' => $action,
            'reason' => $reason,
            'created_at' => "2026-06-24T11:0{$index}:00Z",
        ];
    }
}
