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

    // ── root-cause classification ─────────────────────────────────────────────

    public function test_classify_give_back_reason_forbidden_target(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        $this->assertSame('forbidden_target', $miner->classifyGiveBackReason(['give_back_reason' => 'forbidden_self_target']));
        $this->assertSame('forbidden_target', $miner->classifyGiveBackReason(['commit_failed_reason' => 'property_gated']));
    }

    public function test_classify_give_back_reason_missing_impl_file(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        $this->assertSame('missing_impl_file', $miner->classifyGiveBackReason(['impl_file_missing' => true]));
        $this->assertSame('missing_impl_file', $miner->classifyGiveBackReason(['reason' => 'missing impl file']));
    }

    public function test_classify_give_back_reason_contradictory_acceptance(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        $this->assertSame('contradictory_acceptance', $miner->classifyGiveBackReason(['give_back_reason' => 'contradictory_acceptance']));
    }

    public function test_classify_give_back_reason_schema_mismatch(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        $this->assertSame('schema_mismatch', $miner->classifyGiveBackReason(['reason' => 'schema mismatch detected']));
    }

    public function test_classify_give_back_reason_duplicate_or_noop(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        $this->assertSame('duplicate_or_noop', $miner->classifyGiveBackReason(['reason' => 'duplicate task']));
        $this->assertSame('duplicate_or_noop', $miner->classifyGiveBackReason(['reason' => 'nothing_to_commit_in_scope']));
    }

    public function test_classify_give_back_reason_unknown_for_unrecognized(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        $this->assertSame('unknown', $miner->classifyGiveBackReason(['reason' => 'something weird']));
        $this->assertSame('unknown', $miner->classifyGiveBackReason([]));
    }

    public function test_mined_rows_include_bucket_and_respec_hint(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'task_class' => 'forbidden-class',
                'served_delta' => 1,
                'give_back_delta' => 1,
                'give_back_reason' => 'forbidden_self_target',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/Maestro/Foo.php', 'tests/Unit/FooTest.php'],
                'scope_in' => ['app/Services/Ai/SelfConstruction/Maestro/Foo.php'],
                'required_evidence' => ['tests_or_gates_result'],
            ];
        }

        $result = (new AtlasMaestroGiveBackPatternMiner($rows))->mineGiveBackShapes(minSample: 5);

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('forbidden_target', $row['bucket']);
        $this->assertNotEmpty($row['respec_hint']);
    }

    public function test_low_sample_buckets_abstain_instead_of_becoming_policy(): void
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'task_class' => 'small-class',
                'served_delta' => 1,
                'give_back_delta' => 1,
                'give_back_reason' => 'contradictory_acceptance',
                'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
                'scope_in' => ['app/Services/Foo.php'],
                'required_evidence' => ['tests_or_gates_result'],
            ];
        }

        $result = (new AtlasMaestroGiveBackPatternMiner($rows))->mineGiveBackShapes(minSample: 5);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['abstentions']);
        $this->assertSame('insufficient_sample', $result['abstentions'][0]['abstain_reason']);
    }

    public function test_each_bucket_emits_a_respec_hint(): void
    {
        $miner = new AtlasMaestroGiveBackPatternMiner;
        foreach (['missing_impl_file', 'forbidden_target', 'contradictory_acceptance', 'schema_mismatch', 'duplicate_or_noop', 'unknown'] as $bucket) {
            // Build enough rows for each bucket to pass the sample floor.
            $rows = [];
            for ($i = 0; $i < 5; $i++) {
                $rows[] = [
                    'task_class' => $bucket.'-class',
                    'served_delta' => 1,
                    'give_back_delta' => 1,
                    'give_back_reason' => $bucket,
                    'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
                    'scope_in' => ['app/Services/Foo.php'],
                    'required_evidence' => ['tests_or_gates_result'],
                ];
            }
            $result = $miner->mineGiveBackShapes($rows, minSample: 5);
            $this->assertNotEmpty($result['rows'], "bucket {$bucket} must produce a row");
            $row = $result['rows'][0];
            $this->assertSame($bucket, $row['bucket']);
            $this->assertStringStartsWith('respec:', $row['respec_hint']);
        }
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
