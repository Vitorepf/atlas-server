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

    // ── AC2/AC3: replenish_soon worker-floor batches ───────────────────────────

    public function test_replenish_soon_batch_of_five_specs_across_two_dimensions_passes(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Top up worker claimable queue for lane alpha', ['Lane alpha claimable restored']),
            $this->spec('Verify gate proof chain for lane beta', ['Lane beta proof verified']),
            $this->spec('Drain queue backlog jam for lane gamma', ['Lane gamma backlog drained']),
            $this->spec('Learn from capture insight for lane delta', ['Lane delta insight captured']),
            $this->spec('Harden guard resilient check for lane epsilon', ['Lane epsilon guard hardened']),
        ], ['batch_purpose' => 'replenish_soon']);

        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['blockers']);
        $this->assertGreaterThanOrEqual(2, $r['diversity_facts']['distinct_dimension_count']);
        $this->assertSame('replenish_soon', $r['diversity_facts']['batch_purpose']);
    }

    public function test_worker_floor_boilerplate_batch_sharing_template_is_blocked(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Top up worker claimable queue for lane 1', ['Lane claimable restored']),
            $this->spec('Top up worker claimable queue for lane 2', ['Lane claimable restored']),
            $this->spec('Top up worker claimable queue for lane 3', ['Lane claimable restored']),
        ], ['batch_purpose' => 'replenish_soon']);

        $this->assertFalse($r['passed']);
        $this->assertContains('template_farm_concentration', $r['blockers']);
        $this->assertNotEmpty($r['repair_hints']);
    }

    public function test_non_boilerplate_batch_is_never_checked_for_template_farm(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Fix queue jam in replenisher', ['Queue drains without error']),
            $this->spec('Verify gate proof chain end to end', ['Test passes green']),
        ]);

        $this->assertArrayNotHasKey('template_farm_concentration', $r['diversity_facts']);
    }

    public function test_mixed_batch_with_some_non_boilerplate_specs_skips_template_farm_check(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Top up worker claimable queue for lane 1', ['Lane claimable restored']),
            $this->spec('Top up worker claimable queue for lane 2', ['Lane claimable restored']),
            $this->spec('Build and ship a new memory capture pipeline', ['Pipeline ships green']),
        ]);

        $this->assertArrayNotHasKey('template_farm_concentration', $r['diversity_facts']);
    }

    // ── AC2: renamed-wrapper specs are rejected even without worker-floor language ──

    public function test_renamed_wrapper_batch_is_rejected_as_template_width_not_value_diversity(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Harden AtlasFooWidgetService so it validates every input field', ['Passes validation'], ['app/Foo/A.php']),
            $this->spec('Harden AtlasBarGadgetHandler so it validates every input field', ['Passes validation'], ['app/Foo/B.php']),
            $this->spec('Harden AtlasBazModuleWorker so it validates every input field', ['Passes validation'], ['app/Foo/C.php']),
        ]);

        $this->assertFalse($r['passed']);
        $this->assertContains('template_width_not_value_diversity', $r['blockers']);
        $this->assertNotEmpty($r['repair_hints']);
    }

    // ── AC3: a genuinely diverse batch (repair, simplification, hardening, learning) passes ──

    public function test_batch_of_bug_repair_simplification_gate_hardening_and_outcome_learning_passes(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Fix the bug causing packets to double-claim under contention', ['No double-claim reproduces'], ['app/Queue/Claim.php', 'tests/ClaimTest.php']),
            $this->spec('Consolidate and deduplicate the redundant retry wrappers', ['Retry paths collapse to one'], ['app/Retry/Wrapper.php', 'app/Retry/Handler.php']),
            $this->spec('Harden the release gate so it rejects unverified proof', ['Gate rejects unverified proof'], ['app/Gate/Release.php', 'tests/ReleaseGateTest.php']),
            $this->spec('Capture the outcome learning signal from resolved tasks into memory', ['Insight retrievable after capture'], ['app/Learning/Capture.php', 'app/Learning/Store.php']),
        ]);

        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['blockers']);
        $this->assertGreaterThanOrEqual(2, $r['diversity_facts']['distinct_dimension_count']);
    }

    // ── AC4: duplicate mechanism clusters are reported for the originator ─────

    public function test_duplicate_mechanism_clusters_names_the_duplicate_specs(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Harden AtlasFooWidgetService so it validates every input field'),
            $this->spec('Harden AtlasBarGadgetHandler so it validates every input field'),
            $this->spec('Verify gate proof chain end to end'),
        ]);

        $clusters = $r['diversity_facts']['duplicate_mechanism_clusters'];
        $this->assertNotEmpty($clusters);
        $this->assertSame(2, $clusters[0]['count']);
        $this->assertSame([0, 1], $clusters[0]['spec_indices']);
        $this->assertContains('Harden AtlasFooWidgetService so it validates every input field', $clusters[0]['objectives']);
        $this->assertContains('Harden AtlasBarGadgetHandler so it validates every input field', $clusters[0]['objectives']);
    }

    public function test_duplicate_mechanism_clusters_is_empty_when_no_two_specs_share_a_mechanism(): void
    {
        $r = (new AtlasTaskFabricBatchValueDiversityGate)->evaluate([
            $this->spec('Fix queue jam in replenisher', ['Queue drains without error'], ['app/Queue/Replenisher.php', 'tests/QueueTest.php']),
            $this->spec('Verify gate proof chain end to end', ['Test passes green'], ['app/Gate/Chain.php', 'tests/GateChainTest.php']),
        ]);

        $this->assertSame([], $r['diversity_facts']['duplicate_mechanism_clusters']);
    }

    public function test_gate_source_has_no_side_effects(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../../../app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricBatchValueDiversityGate.php');
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'exec(', 'DB::', 'Http::', 'Queue::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "gate must NOT contain {$forbidden}");
        }
    }
}
