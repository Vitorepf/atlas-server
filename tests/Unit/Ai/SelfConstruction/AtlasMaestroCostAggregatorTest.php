<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use ReflectionClass;
use Tests\TestCase;

class AtlasMaestroCostAggregatorTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-cost-agg-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function fact(array $override = []): array
    {
        return array_replace([
            'task_packet_id' => 'pk-1',
            'task_class' => 'refactor',
            'provider' => 'atlas_native',
            'model' => 'atlas-native-1',
            'cycle_id' => 'cycle-A',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_cents' => 10,
            'recorded_at' => '2026-06-25T00:00:00Z',
        ], $override);
    }

    public function test_aggregate_by_provider_sums_match_hand_computed_six_row_fixture(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        // 2 providers × 3 records.
        foreach ([['atlas_native', 'm1', 100, 10], ['atlas_native', 'm1', 200, 20], ['atlas_native', 'm1', 300, 30]] as $r) {
            $ledger->append($this->fact(['provider' => $r[0], 'model' => $r[1], 'tokens_in' => $r[2], 'cost_cents' => $r[3], 'recorded_at' => '2026-06-25T00:00:0'.$r[3].'Z']));
        }
        foreach ([['codex', 'm2', 50, 5], ['codex', 'm2', 60, 6], ['codex', 'm2', 70, 7]] as $r) {
            $ledger->append($this->fact(['provider' => $r[0], 'model' => $r[1], 'tokens_in' => $r[2], 'cost_cents' => $r[3], 'recorded_at' => '2026-06-25T01:00:0'.$r[3].'Z']));
        }

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByProvider();

        self::assertSame(['atlas_native:m1', 'codex:m2'], array_keys($verdict));
        self::assertSame(60, $verdict['atlas_native:m1']['sum_cost_cents']);
        self::assertSame(600, $verdict['atlas_native:m1']['sum_tokens_in']);
        self::assertSame(3, $verdict['atlas_native:m1']['count_records']);
        self::assertSame(18, $verdict['codex:m2']['sum_cost_cents']);
        self::assertSame(180, $verdict['codex:m2']['sum_tokens_in']);
    }

    public function test_aggregate_by_cycle_filters_when_cycle_id_supplied(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['cycle_id' => 'cycle-A', 'cost_cents' => 5]));
        $ledger->append($this->fact(['cycle_id' => 'cycle-B', 'cost_cents' => 7]));

        $byCycleA = (new AtlasMaestroCostAggregator($ledger))->aggregateByCycle('cycle-A');
        self::assertArrayHasKey('cycle-A', $byCycleA);
        self::assertArrayNotHasKey('cycle-B', $byCycleA);

        $byCycleAll = (new AtlasMaestroCostAggregator($ledger))->aggregateByCycle(null);
        self::assertArrayHasKey('cycle-A', $byCycleAll);
        self::assertArrayHasKey('cycle-B', $byCycleAll);
    }

    public function test_aggregate_by_task_class_groups_by_task_class(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_class' => 'refactor', 'cost_cents' => 10]));
        $ledger->append($this->fact(['task_class' => 'refactor', 'cost_cents' => 5]));
        $ledger->append($this->fact(['task_class' => 'docs', 'cost_cents' => 3]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByTaskClass();
        self::assertSame(['docs', 'refactor'], array_keys($verdict));
        self::assertSame(15, $verdict['refactor']['sum_cost_cents']);
        self::assertSame(3, $verdict['docs']['sum_cost_cents']);
    }

    public function test_empty_ledger_returns_empty_array_for_all_three_views(): void
    {
        $aggregator = new AtlasMaestroCostAggregator(new AtlasMaestroCostLedger($this->ledgerPath));

        self::assertSame([], $aggregator->aggregateByTaskClass());
        self::assertSame([], $aggregator->aggregateByProvider());
        self::assertSame([], $aggregator->aggregateByCycle());
    }

    public function test_malformed_row_does_not_throw_and_is_not_summed(): void
    {
        // Build the input rows directly via the pure primitive.
        $rows = [
            ['task_class' => 'refactor', 'tokens_in' => 100, 'tokens_out' => 50, 'cost_cents' => 10, 'recorded_at' => '2026-06-25T00:00:00Z'],
            ['task_class' => 'refactor', 'tokens_in' => 'BAD', 'tokens_out' => 50, 'cost_cents' => 10, 'recorded_at' => '2026-06-25T00:00:01Z'],
        ];
        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup($rows, 'task_class');

        self::assertSame(1, $verdict['refactor']['count_records'], 'malformed row must be skipped from count_records');
        self::assertSame(10, $verdict['refactor']['sum_cost_cents']);
    }

    public function test_public_methods_return_array_shape_only_no_quality_field(): void
    {
        $reflection = new ReflectionClass(AtlasMaestroCostAggregator::class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            self::assertSame('array', (string) $method->getReturnType(), "method {$method->getName()} must return array");
        }

        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup([
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:00Z'],
        ], 'task_class');
        foreach ($verdict as $group) {
            foreach (array_keys($group) as $key) {
                self::assertDoesNotMatchRegularExpression('/(quality|score|rating)/i', (string) $key);
            }
        }
    }

    public function test_first_and_last_recorded_at_track_extents_within_group(): void
    {
        $rows = [
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:02Z'],
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:01Z'],
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:03Z'],
        ];
        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup($rows, 'task_class');
        self::assertSame('2026-06-25T00:00:01Z', $verdict['x']['first_recorded_at']);
        self::assertSame('2026-06-25T00:00:03Z', $verdict['x']['last_recorded_at']);
    }
}
