<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImplementabilitySimulator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainImplementabilitySimulatorTest extends TestCase
{
    private AtlasExternalBrainImplementabilitySimulator $sim;

    protected function setUp(): void
    {
        $this->sim = new AtlasExternalBrainImplementabilitySimulator;
    }

    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'test-task-01',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php',
                'tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasFooTest.php',
            ],
            'acceptance_criteria' => ['must implement simulate()', 'must return schema'],
            'objective' => 'Implement AtlasFoo.',
            'unblocked_by' => [],
        ], $overrides);
    }

    // ── enqueueable ───────────────────────────────────────────────────────────

    public function test_clean_candidate_is_enqueueable(): void
    {
        $r = $this->sim->simulate($this->candidate());
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_ENQUEUEABLE, $r['verdict']);
        $this->assertSame([], $r['reasons']);
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::SCHEMA, $r['schema']);
        $this->assertSame('test-task-01', $r['candidate_id']);
    }

    // ── defer_for_dependency ──────────────────────────────────────────────────

    public function test_pending_dependency_defers(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(['unblocked_by' => ['upstream-task-99']]),
            ['pending_task_ids' => ['upstream-task-99', 'other-task']],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_DEFER, $r['verdict']);
        $this->assertContains('pending:upstream-task-99', $r['reasons']);
    }

    public function test_completed_dependency_does_not_defer(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(['unblocked_by' => ['upstream-task-99']]),
            ['pending_task_ids' => ['some-other-task']],  // upstream-task-99 is gone → done
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_ENQUEUEABLE, $r['verdict']);
    }

    // ── duplicate_existing_capability ────────────────────────────────────────

    public function test_all_impl_files_existing_is_duplicate(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(),
            ['implemented_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php']],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_DUPLICATE, $r['verdict']);
        $this->assertContains('all_impl_files_already_exist', $r['reasons']);
    }

    public function test_partial_impl_files_existing_is_not_duplicate(): void
    {
        // Two impl files; only one exists — still work to do.
        $r = $this->sim->simulate(
            $this->candidate(['allowed_files' => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php',
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasBar.php',
                'tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasFooTest.php',
            ]]),
            ['implemented_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php']],
        );
        $this->assertNotSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_DUPLICATE, $r['verdict']);
    }

    // ── collision ─────────────────────────────────────────────────────────────

    public function test_file_held_by_active_lease_is_collision(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(),
            ['active_allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php']],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_COLLISION, $r['verdict']);
        $this->assertContains('file_held:app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php', $r['reasons']);
    }

    public function test_no_overlap_with_active_leases_is_not_collision(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(),
            ['active_allowed_files' => ['app/Services/Ai/SelfConstruction/SomeOtherService.php']],
        );
        $this->assertNotSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_COLLISION, $r['verdict']);
    }

    // ── property_gated_missing_evidence ──────────────────────────────────────

    public function test_property_gate_not_satisfied_blocks(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(['task_packet_id' => 'gated-task-01']),
            ['property_gates' => ['gated-task-01' => false]],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_PROPERTY_GATED, $r['verdict']);
        $this->assertContains('property_gate_not_satisfied:gated-task-01', $r['reasons']);
    }

    public function test_property_gate_satisfied_does_not_block(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(['task_packet_id' => 'gated-task-01']),
            ['property_gates' => ['gated-task-01' => true]],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_ENQUEUEABLE, $r['verdict']);
    }

    // ── contradictory ─────────────────────────────────────────────────────────

    public function test_must_x_and_must_not_x_is_contradictory(): void
    {
        $r = $this->sim->simulate($this->candidate([
            'acceptance_criteria' => [
                'must return true',
                'must not return true',
            ],
        ]));
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_CONTRADICTORY, $r['verdict']);
        $this->assertNotEmpty($r['reasons']);
    }

    public function test_test_only_allowed_files_with_missing_prod_files_is_contradictory(): void
    {
        $r = $this->sim->simulate(
            $this->candidate([
                'allowed_files' => ['tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasFooTest.php'],
            ]),
            ['missing_prod_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php']],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_CONTRADICTORY, $r['verdict']);
        $this->assertStringContainsString('test_without_impl', $r['reasons'][0]);
    }

    // ── invariant: no suggested_allowed_files stripping impl files ────────────

    public function test_result_never_contains_suggested_allowed_files(): void
    {
        // Even for a contradictory candidate, the simulator must not produce a key that
        // strips impl files from allowed_files suggestions.
        $r = $this->sim->simulate(
            $this->candidate([
                'allowed_files' => ['tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasFooTest.php'],
            ]),
            ['missing_prod_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php']],
        );
        $this->assertArrayNotHasKey('suggested_allowed_files', $r);
    }

    // ── priority ordering ─────────────────────────────────────────────────────

    public function test_contradictory_takes_priority_over_collision(): void
    {
        $r = $this->sim->simulate(
            $this->candidate([
                'acceptance_criteria' => ['must return true', 'must not return true'],
            ]),
            ['active_allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php']],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_CONTRADICTORY, $r['verdict']);
    }

    public function test_duplicate_takes_priority_over_property_gate(): void
    {
        $r = $this->sim->simulate(
            $this->candidate(['task_packet_id' => 'gated-task-01']),
            [
                'implemented_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php'],
                'property_gates' => ['gated-task-01' => false],
            ],
        );
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_DUPLICATE, $r['verdict']);
    }
}
