<?php

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\ExecutionEvidence;
use App\Services\Ai\EngineeringKernel\FalseClaimInvariant;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use Tests\TestCase;

/**
 * SLICE 2 — Proof Loop DURO. The gate MUST turn red when the guarded feature is
 * mutated: strip the real test run out of a claimed-pass and the proof flips from
 * proven_real → fake_green, and the OutcomeMemory refuses to promote it into learning.
 * If any of these passes on a mutated (lying) input, the gate is fake-green.
 */
class OutcomeProofGateMutationTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->migration->down();
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        parent::tearDown();
    }

    /** A real execution block (green) with the SAME feature mutated (tests stripped). */
    private function realExecution(): array
    {
        return [
            'commands' => ['php artisan test tests/Feature/Ai/Foo.php'],
            'tests_run' => 12,
            'assertions_executed' => 34,
            'artifacts' => ['storage/atlas/runs/foo.junit.xml'],
        ];
    }

    public function test_gate_is_green_on_real_execution_and_red_when_mutated(): void
    {
        $gate = new OutcomeProofGate;

        $green = $gate->assess('success', $this->realExecution());
        $this->assertTrue($green['proven_real']);
        $this->assertFalse($green['fake_green']);

        // MUTATION #1: claim the same pass but 0 tests actually ran (the suite was gutted).
        $mutantZeroTests = $this->realExecution();
        $mutantZeroTests['tests_run'] = 0;
        $red = $gate->assess('success', $mutantZeroTests);
        $this->assertFalse($red['proven_real'], 'gate stayed green after tests were stripped — fake-green');
        $this->assertTrue($red['fake_green']);
        $this->assertSame('claimed_pass_with_zero_tests_run', $red['reason']);

        // MUTATION #2: lint-as-suite — ran only `php -l`, no test runner, but claims a suite.
        $lintOnly = $gate->assess('success', [
            'commands' => ['php -l app/Foo.php'],
            'tests_run' => 3,
            'assertions_executed' => 3,
            'selected_tests' => ['php artisan test tests/Feature/Ai/Foo.php'],
        ]);
        $this->assertTrue($lintOnly['fake_green']);
        $this->assertSame('lint_only_run_presented_as_suite', $lintOnly['reason']);
    }

    public function test_floor_and_gate_share_one_anti_fake_green_rule(): void
    {
        // The floor delegates to the SAME invariant the gate reuses — no drift.
        $lie = ExecutionEvidence::fromArray([
            'claimed_status' => 'passed',
            'tests_run' => 0,
            'assertions_executed' => 0,
        ]);
        $this->assertSame('fail', (new FalseClaimInvariant)->evaluate($lie)['status']);

        $fixedSmoke = ExecutionEvidence::fromArray([
            'claimed_status' => 'passed',
            'tests_run' => 5,
            'assertions_executed' => 5,
            'artifacts' => [SovereignHonestyFloor::FIXED_SMOKE_SIGNATURE.'_artifact'],
        ]);
        $this->assertSame('fail', (new FalseClaimInvariant)->evaluate($fixedSmoke)['status']);
    }

    public function test_outcome_memory_never_promotes_a_fake_green_and_counts_it(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'dev-proof', 'task_id' => 'task-proof', 'objective' => 'proof loop',
            'task_class' => 'feature', 'risk_band' => 'medium', 'workspace_slug' => 'atlas-server',
            'allowed_files' => ['app/Foo.php'], 'expected_files' => ['app/Foo.php'],
            'suggested_tests' => ['php artisan test tests/Feature/Ai/Foo.php'],
        ]);
        $outcomes = new DevOutcomeMemoryService;

        // Proven-real success → promotes to learning, no review, no fake-green marker.
        $real = $outcomes->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['phpunit'],
            'execution' => $this->realExecution(),
        ], $packet);
        $this->assertTrue($real->should_promote_to_aemor);
        $this->assertFalse($real->human_review_required);
        $this->assertNotContains(DevOutcomeMemoryService::FAKE_GREEN_MARKER, (array) $real->learning_candidates);

        // MUTATION: same claimed success, tests stripped → refused as fake-green.
        $mutant = $this->realExecution();
        $mutant['tests_run'] = 0;
        $fake = $outcomes->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['phpunit'],
            'execution' => $mutant,
        ], $packet);
        $this->assertFalse($fake->should_promote_to_aemor, 'fake-green was promoted into the learning signal — garbage-in');
        $this->assertTrue($fake->human_review_required);
        $this->assertContains(DevOutcomeMemoryService::FAKE_GREEN_MARKER, (array) $fake->learning_candidates);

        // The real counter reflects exactly one suppressed fake-green.
        $this->assertSame(1, DevOutcomeMemoryService::fakeGreenSuppressedCount());
    }

    public function test_thin_but_honest_success_is_unproven_not_fake_green(): void
    {
        // No execution block supplied: cannot prove real, but it is NOT a positive lie —
        // legacy no-evidence successes are left to baseline, never counted as fake-green.
        $gate = (new OutcomeProofGate)->assess('success', []);
        $this->assertFalse($gate['proven_real']);
        $this->assertFalse($gate['fake_green']);
        $this->assertSame('no_execution_evidence_supplied', $gate['reason']);
    }
}
