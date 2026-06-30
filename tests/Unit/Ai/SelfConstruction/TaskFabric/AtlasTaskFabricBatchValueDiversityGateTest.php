<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricBatchValueDiversityGate;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricBatchValueDiversityGateTest extends TestCase
{
    private function spec(string $objective, array $criteria = ['Gate passes'], array $files = ['app/Foo.php', 'tests/FooTest.php']): array
    {
        return ['objective' => $objective, 'acceptance_criteria' => $criteria, 'allowed_files' => $files];
    }

    // ── happy-path ────────────────────────────────────────────────────────────

    public function test_single_spec_skips_checks_and_passes(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([$this->spec('Implement Foo')]);

        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['blockers']);
        $this->assertTrue($r['diversity_facts']['skipped_minimum_not_met']);
    }

    public function test_diverse_batch_passes_all_checks(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Fix queue jam in replenisher', ['Queue drains without error'], ['app/Queue/Replenisher.php', 'tests/QueueTest.php']),
            $this->spec('Verify gate proof chain end to end', ['Test passes green'], ['app/Gate/Chain.php', 'tests/GateChainTest.php']),
            $this->spec('Implement memory capture stream', ['Memory captured and persisted'], ['app/Memory/Capture.php', 'app/Memory/Store.php', 'tests/MemoryTest.php']),
        ]);

        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_batch_with_distinct_dimensions_passes(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Drain queue backlog via jammed packets', ['Queue drains without error']),
            $this->spec('Learn from verification gate outputs and capture insight', ['Learning captured and retrievable']),
        ]);

        $this->assertTrue($r['passed']);
        $this->assertGreaterThanOrEqual(2, $r['diversity_facts']['distinct_dimension_count']);
    }

    // ── AC1: homogeneous batches are rejected ─────────────────────────────────

    public function test_same_objective_fingerprint_concentration_blocks(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Implement service class for alpha'),
            $this->spec('Implement service class for beta'),
            $this->spec('Implement service class for gamma'),
        ]);

        $this->assertFalse($r['passed']);
        $this->assertContains('objective_fingerprint_concentration', $r['blockers']);
        $this->assertNotEmpty($r['repair_hints']);
    }

    public function test_same_acceptance_shape_concentration_blocks(): void
    {
        $sharedShape = ['gate passes green', 'test runs without error', 'no regression found'];
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Implement Foo', $sharedShape),
            $this->spec('Implement Bar', $sharedShape),
            $this->spec('Implement Baz', $sharedShape),
        ]);

        $this->assertFalse($r['passed']);
        $this->assertContains('acceptance_shape_concentration', $r['blockers']);
    }

    public function test_one_file_concentration_blocks(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Fix queue jam', ['Queue drains'], ['app/Queue.php']),
            $this->spec('Verify gate proof', ['Gate passes'], ['app/Gate.php']),
            $this->spec('Learn from outputs', ['Learning captured'], ['app/Learning.php']),
        ]);

        $this->assertFalse($r['passed']);
        $this->assertContains('one_file_concentration', $r['blockers']);
    }

    public function test_insufficient_dimension_diversity_blocks(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Add implementation for alpha service A'),
            $this->spec('Build and ship implementation for beta service B'),
        ]);

        $this->assertFalse($r['passed']);
        $this->assertContains('insufficient_dimension_diversity', $r['blockers']);
        $this->assertSame(1, $r['diversity_facts']['distinct_dimension_count']);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_concentration_at_exactly_threshold_does_not_block(): void
    {
        // 2 of 4 = 0.5 — NOT > 0.5, so no block.
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Fix queue backlog jam A'),
            $this->spec('Fix queue backlog jam B'),
            $this->spec('Verify certification gate proof chain A'),
            $this->spec('Verify certification gate proof chain B'),
        ]);

        $this->assertNotContains('objective_fingerprint_concentration', $r['blockers']);
    }

    public function test_diversity_facts_always_present(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Implement Foo'),
            $this->spec('Verify Bar'),
        ]);

        foreach (['total', 'objective_fingerprint_concentration', 'acceptance_shape_concentration', 'one_file_concentration', 'distinct_dimensions', 'distinct_dimension_count'] as $key) {
            $this->assertArrayHasKey($key, $r['diversity_facts'], "diversity_facts missing '{$key}'");
        }
    }

    public function test_empty_batch_skips_checks_and_passes(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([]);

        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_output_has_stable_schema_version(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([]);
        $this->assertSame(AtlasTaskFabricBatchValueDiversityGate::SCHEMA, $r['schema_version']);
    }

    public function test_gate_source_has_no_side_effects(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../../../app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricBatchValueDiversityGate.php');
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'exec(', 'DB::', 'Http::', 'Queue::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "gate must NOT contain {$forbidden}");
        }
    }
}
