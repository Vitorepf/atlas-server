<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasRetrievalFusionService;
use PHPUnit\Framework\TestCase;

final class AtlasRetrievalFusionServiceTest extends TestCase
{
    public function test_it_fuses_ranked_sources_deterministically_without_losing_source_diversity(): void
    {
        $service = new AtlasRetrievalFusionService;
        $code = [
            ['id' => 'sym:A', 'file_path' => 'app/A.php', 'symbol_type' => 'class'],
            ['id' => 'sym:B', 'file_path' => 'app/B.php', 'symbol_type' => 'class'],
        ];
        $memory = [
            ['id' => 'mem-1', 'title' => 'Canonical decision', 'type' => 'decision'],
        ];
        $paths = [[
            'chain' => [
                ['id' => 'memory:1', 'label' => 'Decision', 'source_kind' => 'memory'],
                ['id' => 'code:1', 'label' => 'Module', 'source_kind' => 'code'],
            ],
            'cross_layer' => true,
        ]];

        $first = $service->fuse($code, $memory, $paths, ['limit' => 4]);
        $second = $service->fuse($code, $memory, $paths, ['limit' => 4]);

        $this->assertSame('ready', $first['status']);
        $this->assertSame(['code' => 2, 'memory' => 1, 'reality' => 1], $first['source_counts']);
        $this->assertSame(['code', 'memory', 'reality'], array_values(array_unique(array_column($first['candidates'], 'source'))));
        $this->assertSame($first['fusion_hash'], $second['fusion_hash']);
        $this->assertSame($first['candidates'], $second['candidates']);
    }

    public function test_empty_sources_return_an_honest_empty_receipt(): void
    {
        $result = (new AtlasRetrievalFusionService)->fuse([], [], []);

        $this->assertSame('empty', $result['status']);
        $this->assertSame([], $result['candidates']);
    }
}
