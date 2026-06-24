<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroGiveBackPatternMiner;
use Tests\TestCase;

final class AtlasMaestroGiveBackPatternMinerTest extends TestCase
{
    public function test_mine_giveback_shapes_returns_integer_fact_counters(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner($this->rows(5, 2, 'feature'));

        $result = $miner->mineGiveBackShapes();

        $this->assertSame(AtlasMaestroGiveBackPatternMiner::SCHEMA, $result['schema']);
        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertArrayHasKey('give_back_count', $row);
        $this->assertArrayHasKey('served_count', $row);
        foreach ($row as $key => $value) {
            $this->assertIsNotFloat($value, $key);
        }
        $this->assertSame(2, $row['give_back_count']);
        $this->assertSame(5, $row['served_count']);
    }

    public function test_insufficient_sample_boundary_omits_four_and_keeps_five(): void
    {
        $rows = [
            ...$this->rows(4, 4, 'too-small'),
            ...$this->rows(5, 1, 'large-enough'),
        ];

        $result = (new AtlasMaestroGiveBackPatternMiner($rows))->mineGiveBackShapes(minSample: 5);

        $this->assertCount(1, $result['rows']);
        $this->assertStringContainsString('large-enough', $result['rows'][0]['shape_key']);
        $this->assertCount(1, $result['abstentions']);
        $this->assertStringContainsString('too-small', $result['abstentions'][0]['shape_key']);
        $this->assertSame('insufficient_sample', $result['abstentions'][0]['abstain_reason']);
        $this->assertSame(4, $result['abstentions'][0]['served_count']);
    }

    public function test_default_floor_comes_from_config_with_fallback_five(): void
    {
        config(['atlas.maestro.adaptive.miner_min_sample' => null]);

        $result = (new AtlasMaestroGiveBackPatternMiner($this->rows(4, 4, 'default-floor')))->mineGiveBackShapes();

        $this->assertSame([], $result['rows']);
        $this->assertSame('insufficient_sample', $result['abstentions'][0]['abstain_reason']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(int $served, int $giveBack, string $taskClass): array
    {
        $rows = [];
        for ($i = 0; $i < $served; $i++) {
            $rows[] = [
                'task_class' => $taskClass,
                'served_delta' => 1,
                'give_back_delta' => $i < $giveBack ? 1 : 0,
                'allowed_files' => ['app/Services/Ai/SelfConstruction/Maestro/Adaptive/Foo.php', 'tests/Unit/FooTest.php'],
                'scope_in' => ['app/Services/Ai/SelfConstruction/Maestro/Adaptive/Foo.php'],
                'required_evidence' => ['tests_or_gates_result'],
            ];
        }

        return $rows;
    }
}
