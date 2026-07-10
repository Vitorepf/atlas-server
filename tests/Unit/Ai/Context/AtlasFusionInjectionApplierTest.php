<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasFusionInjectionApplier;
use Tests\TestCase;

final class AtlasFusionInjectionApplierTest extends TestCase
{
    public function test_apply_reorders_pack_sections_by_fusion_candidates(): void
    {
        $pack = [
            'code_graph' => [
                ['id' => 'code:b', 'file_path' => 'b.php'],
                ['id' => 'code:a', 'file_path' => 'a.php'],
            ],
            'memory' => [
                ['id' => 'mem:2', 'title' => 'two'],
                ['id' => 'mem:1', 'title' => 'one'],
            ],
            'reality_graph_paths' => [
                [
                    'chain' => [
                        ['id' => 'memory:m2'],
                        ['id' => 'code:module:y'],
                    ],
                ],
                [
                    'chain' => [
                        ['id' => 'memory:m1'],
                        ['id' => 'code:module:x'],
                    ],
                ],
            ],
            'retrieval_fusion' => [
                'status' => 'ready',
                'algorithm' => 'reciprocal_rank_fusion',
                'candidates' => [
                    ['source' => 'memory', 'ref' => 'mem:1', 'fused_score' => 0.9],
                    ['source' => 'code', 'ref' => 'code:a', 'fused_score' => 0.8],
                    ['source' => 'reality', 'ref' => 'memory:m1>code:module:x', 'fused_score' => 0.7],
                    ['source' => 'memory', 'ref' => 'mem:2', 'fused_score' => 0.6],
                    ['source' => 'code', 'ref' => 'code:b', 'fused_score' => 0.5],
                    ['source' => 'reality', 'ref' => 'memory:m2>code:module:y', 'fused_score' => 0.4],
                ],
            ],
        ];

        $out = (new AtlasFusionInjectionApplier)->apply($pack);

        $this->assertSame(['mem:1', 'mem:2'], array_column($out['memory'], 'id'));
        $this->assertSame(['code:a', 'code:b'], array_column($out['code_graph'], 'id'));
        $this->assertSame(
            ['memory:m1>code:module:x', 'memory:m2>code:module:y'],
            array_map(
                static fn (array $path): string => implode('>', array_column($path['chain'], 'id')),
                $out['reality_graph_paths'],
            ),
        );
        $this->assertTrue((bool) data_get($out, 'retrieval_fusion.applied_to_sections'));
    }

    public function test_apply_is_noop_when_no_fusion_candidates(): void
    {
        $pack = [
            'code_graph' => [['id' => 'code:a']],
            'memory' => [],
            'reality_graph_paths' => [],
        ];
        $out = (new AtlasFusionInjectionApplier)->apply($pack);
        $this->assertSame($pack['code_graph'], $out['code_graph']);
        $this->assertFalse((bool) data_get($out, 'retrieval_fusion.applied_to_sections'));
    }

    public function test_apply_appends_items_missing_from_fusion_candidates(): void
    {
        $pack = [
            'code_graph' => [
                ['id' => 'code:z'],
                ['id' => 'code:a'],
            ],
            'memory' => [],
            'reality_graph_paths' => [],
            'retrieval_fusion' => [
                'candidates' => [
                    ['source' => 'code', 'ref' => 'code:a', 'fused_score' => 1.0],
                ],
            ],
        ];

        $out = (new AtlasFusionInjectionApplier)->apply($pack);

        $this->assertSame(['code:a', 'code:z'], array_column($out['code_graph'], 'id'));
        $this->assertTrue((bool) data_get($out, 'retrieval_fusion.applied_to_sections'));
    }
}
