<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphCompoundingBridge;
use PHPUnit\Framework\TestCase;

class CodeGraphCompoundingBridgeTest extends TestCase
{
    /**
     * @return array<int,array{node_id:string,degree:int,in_degree:int,out_degree:int}>
     */
    private function godNodes(): array
    {
        return [
            ['node_id' => 'node:app/Services/Ai/Router', 'degree' => 12, 'in_degree' => 9, 'out_degree' => 3],
            ['node_id' => 'node:app/Services/Memory/Core', 'degree' => 7, 'in_degree' => 5, 'out_degree' => 2],
        ];
    }

    /**
     * @return array<int,array{from_node_id:string,to_node_id:string,score:float,why:array<int,string>}>
     */
    private function surprises(): array
    {
        return [
            [
                'from_node_id' => 'node:app/Domains/Finance/Leaf',
                'to_node_id' => 'node:app/Services/Ai/Router',
                'score' => 5.0,
                'why' => ['cross-community (3 -> 1)', 'periphery->hub (deg 1 -> deg 12)'],
            ],
            [
                'from_node_id' => 'node:app/Support/Helper',
                'to_node_id' => 'node:app/Services/Memory/Core',
                'score' => 1.5,
                'why' => ['ambiguous-confidence edge'],
            ],
        ];
    }

    public function test_every_candidate_is_proposal_only(): void
    {
        $candidates = (new CodeGraphCompoundingBridge)
            ->toMemoryCandidates($this->godNodes(), $this->surprises());

        $this->assertNotEmpty($candidates);
        $this->assertCount(4, $candidates); // 2 god-nodes + 2 surprises

        foreach ($candidates as $candidate) {
            $this->assertSame(CodeGraphCompoundingBridge::CANDIDATE_TYPE, $candidate['candidate_type']);
            $this->assertSame('code_graph_insight', $candidate['candidate_type']);

            // The governance invariant — proposal-only, never auto-promoted.
            $this->assertArrayHasKey('promotion_allowed', $candidate);
            $this->assertFalse($candidate['promotion_allowed']);
            $this->assertSame(false, $candidate['promotion_allowed']);

            $this->assertArrayHasKey('requires_review', $candidate);
            $this->assertTrue($candidate['requires_review']);
            $this->assertSame(true, $candidate['requires_review']);

            // Restated inside metadata for metadata-only consumers.
            $this->assertFalse($candidate['metadata']['promotion_allowed']);
            $this->assertTrue($candidate['metadata']['requires_review']);

            // Carries a human-readable summary and an auditable basis.
            $this->assertNotEmpty($candidate['summary']);
            $this->assertNotEmpty($candidate['basis']);
        }
    }

    public function test_god_node_candidates_summarize_hub_and_degree(): void
    {
        $candidates = (new CodeGraphCompoundingBridge)
            ->toMemoryCandidates($this->godNodes(), []);

        $this->assertCount(2, $candidates);

        $top = $candidates[0];
        $this->assertSame(CodeGraphCompoundingBridge::KIND_GOD_NODE, $top['metadata']['insight_kind']);
        $this->assertStringContainsString('node:app/Services/Ai/Router', $top['summary']);
        $this->assertStringContainsString('degree 12', $top['summary']);
        // Basis exposes the centrality breakdown for the reviewer.
        $this->assertStringContainsString('in_degree=9', $top['basis']);
        $this->assertStringContainsString('out_degree=3', $top['basis']);
        $this->assertStringContainsString('total_degree=12', $top['basis']);
        // Structured signal is preserved for downstream review tooling.
        $this->assertSame('node:app/Services/Ai/Router', $top['metadata']['signal']['node_id']);
        $this->assertSame(12, $top['metadata']['signal']['degree']);
        // Still proposal-only.
        $this->assertFalse($top['promotion_allowed']);
        $this->assertTrue($top['requires_review']);
    }

    public function test_surprise_candidates_summarize_connection_and_reasons(): void
    {
        $candidates = (new CodeGraphCompoundingBridge)
            ->toMemoryCandidates([], $this->surprises());

        $this->assertCount(2, $candidates);

        $first = $candidates[0];
        $this->assertSame(CodeGraphCompoundingBridge::KIND_SURPRISE, $first['metadata']['insight_kind']);
        $this->assertStringContainsString('node:app/Domains/Finance/Leaf', $first['summary']);
        $this->assertStringContainsString('node:app/Services/Ai/Router', $first['summary']);
        // The "why" reasons are folded into the basis verbatim.
        $this->assertStringContainsString('cross-community (3 -> 1)', $first['basis']);
        $this->assertStringContainsString('periphery->hub', $first['basis']);
        // Structured signal preserved.
        $this->assertSame('node:app/Domains/Finance/Leaf', $first['metadata']['signal']['from_node_id']);
        $this->assertSame(5.0, $first['metadata']['signal']['score']);
        $this->assertSame(['cross-community (3 -> 1)', 'periphery->hub (deg 1 -> deg 12)'], $first['metadata']['signal']['why']);
        // Still proposal-only.
        $this->assertFalse($first['promotion_allowed']);
        $this->assertTrue($first['requires_review']);
    }

    public function test_score_is_formatted_cleanly_in_summary(): void
    {
        $bridge = new CodeGraphCompoundingBridge;

        // Whole-number score renders without a trailing ".0".
        $whole = $bridge->toMemoryCandidates([], [[
            'from_node_id' => 'node:a',
            'to_node_id' => 'node:b',
            'score' => 3.0,
            'why' => ['inferred (non-extracted) edge'],
        ]]);
        $this->assertStringContainsString('surprise score 3)', $whole[0]['summary']);

        // Fractional score keeps significant digits, trims trailing zeros.
        $frac = $bridge->toMemoryCandidates([], [[
            'from_node_id' => 'node:a',
            'to_node_id' => 'node:b',
            'score' => 2.5,
            'why' => ['ambiguous-confidence edge'],
        ]]);
        $this->assertStringContainsString('surprise score 2.5)', $frac[0]['summary']);
    }

    public function test_missing_or_malformed_signal_rows_are_skipped(): void
    {
        $bridge = new CodeGraphCompoundingBridge;

        $candidates = $bridge->toMemoryCandidates(
            [
                'not-an-array',
                ['degree' => 5], // no node_id -> skipped
                ['node_id' => '   '], // blank node_id -> skipped
                ['node_id' => 'node:real', 'degree' => 4],
            ],
            [
                ['to_node_id' => 'node:b'], // missing from -> skipped
                ['from_node_id' => 'node:a', 'to_node_id' => 'node:b'], // valid, score defaults to 0
            ],
        );

        // Only the one valid god-node and the one valid surprise survive.
        $this->assertCount(2, $candidates);
        foreach ($candidates as $candidate) {
            $this->assertFalse($candidate['promotion_allowed']);
            $this->assertTrue($candidate['requires_review']);
        }
    }

    public function test_max_per_kind_caps_each_insight_kind(): void
    {
        $bridge = new CodeGraphCompoundingBridge;

        $god = [];
        $surprise = [];
        for ($i = 0; $i < 25; $i++) {
            $god[] = ['node_id' => "node:g{$i}", 'degree' => 25 - $i, 'in_degree' => 1, 'out_degree' => 1];
            $surprise[] = ['from_node_id' => "node:s{$i}", 'to_node_id' => 'node:hub', 'score' => 1.0, 'why' => ['inferred (non-extracted) edge']];
        }

        $candidates = $bridge->toMemoryCandidates($god, $surprise, maxPerKind: 3);

        // 3 god-nodes + 3 surprises, capped.
        $this->assertCount(6, $candidates);
        $kinds = array_count_values(array_map(
            static fn (array $c): string => $c['metadata']['insight_kind'],
            $candidates,
        ));
        $this->assertSame(3, $kinds[CodeGraphCompoundingBridge::KIND_GOD_NODE]);
        $this->assertSame(3, $kinds[CodeGraphCompoundingBridge::KIND_SURPRISE]);
    }

    public function test_empty_inputs_produce_no_candidates(): void
    {
        $candidates = (new CodeGraphCompoundingBridge)->toMemoryCandidates([], []);

        $this->assertSame([], $candidates);
    }
}
