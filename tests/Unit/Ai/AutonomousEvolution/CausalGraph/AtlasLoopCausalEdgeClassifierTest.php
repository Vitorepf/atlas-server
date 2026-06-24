<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\CausalGraph;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopCausalEdgeClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCausalEdgeClassifierTest extends TestCase
{
    public function test_classify_returns_one_edge_per_consumer_across_the_four_break_kinds(): void
    {
        $result = (new AtlasLoopCausalEdgeClassifier)->classify('App\\TargetService', [
            ['consumer_fqcn' => 'App\\UsesChanged', 'uses' => ['renameMe']],
            ['consumer_fqcn' => 'App\\Unaffected', 'uses' => ['stillThere']],
            ['consumer_fqcn' => 'App\\UsesAdded', 'uses' => ['newHook']],
            ['consumer_fqcn' => 'App\\UsesRemoved', 'uses' => ['oldHook']],
        ], [
            'diff' => [
                ['method_name' => 'newHook', 'classification' => 'added'],
                ['method_name' => 'oldHook', 'classification' => 'removed'],
                ['method_name' => 'renameMe', 'classification' => 'changed'],
            ],
        ]);

        $this->assertSame(AtlasLoopCausalEdgeClassifier::SCHEMA_VERSION, $result['schema']);
        $this->assertSame([
            ['consumer_fqcn' => 'App\\Unaffected', 'break_kind' => 'none'],
            ['consumer_fqcn' => 'App\\UsesAdded', 'break_kind' => 'added_only'],
            ['consumer_fqcn' => 'App\\UsesChanged', 'break_kind' => 'signature_changed'],
            ['consumer_fqcn' => 'App\\UsesRemoved', 'break_kind' => 'removed_member'],
        ], $result['edges']);
    }

    public function test_removed_public_member_yields_removed_member_for_consumer_that_used_it(): void
    {
        $result = (new AtlasLoopCausalEdgeClassifier)->classify('App\\TargetService', [
            'App\\ConsumerA',
            'App\\ConsumerB',
        ], [
            'removed_members' => ['deleteMe'],
            'consumer_usage' => [
                'App\\ConsumerA' => ['deleteMe'],
                'App\\ConsumerB' => ['keepMe'],
            ],
        ]);

        $this->assertSame([
            ['consumer_fqcn' => 'App\\ConsumerA', 'break_kind' => 'removed_member'],
            ['consumer_fqcn' => 'App\\ConsumerB', 'break_kind' => 'none'],
        ], $result['edges']);
    }

    public function test_output_is_deterministic_and_contains_no_score_field(): void
    {
        $classifier = new AtlasLoopCausalEdgeClassifier;
        $consumers = [
            ['consumer_fqcn' => 'App\\Zed', 'method_calls' => ['changedSig']],
            ['consumer_fqcn' => 'App\\Alpha', 'method_calls' => []],
            ['consumer_fqcn' => 'App\\Zed', 'method_calls' => ['oldHook']],
        ];
        $change = [
            'changed_methods' => ['changedSig'],
            'removed_methods' => ['oldHook'],
        ];

        $first = $classifier->classify('App\\TargetService', $consumers, $change);
        $second = $classifier->classify('App\\TargetService', array_reverse($consumers), $change);

        $this->assertSame($first, $second);
        foreach ($first['edges'] as $edge) {
            $this->assertSame(['consumer_fqcn', 'break_kind'], array_keys($edge));
            $this->assertArrayNotHasKey('score', $edge);
            $this->assertContains($edge['break_kind'], AtlasLoopCausalEdgeClassifier::BREAK_KINDS);
        }
    }
}
