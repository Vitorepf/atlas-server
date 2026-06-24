<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLearningAppendService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Proves the APRENDER learning-append service: flag-OFF null no-op; append() writes one row per closed cycle;
 * idempotent by cycle_id; NO score/rank scalar in the row (anti-Goodhart); and an append-only public surface
 * (no update/delete API).
 */
final class AtlasLoopLearningAppendServiceTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-learn-'.bin2hex(random_bytes(6)).'/cycle-ledger.ndjson';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        @rmdir(dirname($this->ledgerPath));
        parent::tearDown();
    }

    private function service(): AtlasLoopLearningAppendService
    {
        return new AtlasLoopLearningAppendService($this->ledgerPath);
    }

    private function append(string $cycleId): ?array
    {
        return $this->service()->append(
            $cycleId,
            ['schema' => 'atlas.loop.orientation.v1', 'scope_roots' => ['app/X']],
            ['schema_version' => 'atlas.loop.leverage_decision.v1', 'selected_objective' => 'wire X'],
            ['plan_fingerprint' => 'fp-123', 'ordered_tasks' => []],
            ['certified' => true, 'terminal_reason' => 'accepted'],
        );
    }

    public function test_flag_off_is_null_no_op(): void
    {
        config(['atlas.loop.learning_append_enabled' => false]);

        $this->assertNull($this->append('cycle-1'));
        $this->assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_append_writes_one_row_and_is_idempotent_by_cycle_id(): void
    {
        config(['atlas.loop.learning_append_enabled' => true]);

        $row = $this->append('cycle-1');
        $this->assertNotNull($row);
        $this->assertSame('atlas.loop.learning_row.v1', $row['schema_version']);
        $this->assertSame('cycle-1', $row['cycle_id']);
        $this->assertSame('fp-123', $row['decomposition_plan_fingerprint']);
        $this->assertTrue($row['terminal_certified']);
        $this->assertSame('accepted', $row['terminal_reason']);

        // idempotent: a second append of the SAME cycle_id adds no duplicate row.
        $this->append('cycle-1');
        $this->assertCount(1, $this->service()->entries(), 'idempotent by cycle_id — no duplicate');

        // a different cycle appends a new row.
        $this->append('cycle-2');
        $this->assertCount(2, $this->service()->entries());
    }

    public function test_row_contains_no_score_or_rank_scalar(): void
    {
        config(['atlas.loop.learning_append_enabled' => true]);

        $row = $this->append('cycle-1');
        foreach (array_keys($row) as $key) {
            $this->assertDoesNotMatchRegularExpression('/(score|rank|leverage_value|quality_scalar)/i', $key, "no scalar key '{$key}'");
        }
    }

    public function test_public_api_is_append_only_no_mutation_methods(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(AtlasLoopLearningAppendService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach ($methods as $name) {
            $this->assertDoesNotMatchRegularExpression('/^(update|delete|mutate|edit|remove|overwrite)/i', $name, "append-only: no mutation method '{$name}'");
        }
        $this->assertContains('append', $methods);
        $this->assertContains('entries', $methods);
    }
}
