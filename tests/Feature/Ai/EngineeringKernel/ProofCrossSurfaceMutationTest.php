<?php

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\Product\AtlasProductDeliveryOutcomeMemoryService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeOutcomeMemoryService;
use Tests\TestCase;

/**
 * GOAL 2 · SLICE 1 — Proof CROSS-SURFACE. The SAME OutcomeProofGate (→ FalseClaimInvariant)
 * that guards Dev now guards Forge and Product: mutate the guarded feature (strip the real
 * test run out of a claimed success) and each surface's OutcomeMemory flips proven_real →
 * fake_green and refuses to promote it into learning. No per-surface gate logic — one root.
 */
class ProofCrossSurfaceMutationTest extends TestCase
{
    /** A real execution block (green); the mutation strips the test run out of it. */
    private function realExecution(): array
    {
        return [
            'commands' => ['php artisan test tests/Feature/Ai/Foo.php'],
            'tests_run' => 9,
            'assertions_executed' => 21,
            'artifacts' => ['storage/atlas/runs/foo.junit.xml'],
        ];
    }

    private function forgeCycle(array $execution): AiForgeWorkPacketExecutionCycle
    {
        return new AiForgeWorkPacketExecutionCycle([
            'uuid' => 'cyc-proof',
            'work_packet_canonical_id' => 'wp-proof',
            'execution_mode' => 'native',
            'outcome_status' => 'success',
            'execution_plan' => [],
            'evidence_refs' => [['kind' => 'tests']],
            'gate_result' => ['all_passed' => true, 'execution' => $execution],
        ]);
    }

    public function test_forge_outcome_is_green_on_real_run_and_red_when_mutated(): void
    {
        $svc = new ForgeOutcomeMemoryService;

        $green = $svc->summarize($this->forgeCycle($this->realExecution()));
        $this->assertTrue($green['proven_real']);
        $this->assertFalse($green['fake_green']);
        $this->assertTrue($green['should_promote_to_aemor']);
        $this->assertNotContains(OutcomeProofGate::FAKE_GREEN_MARKER, $green['learning_candidates']);

        // MUTATION: same claimed success, tests stripped → fake-green, never promoted.
        $mutant = $this->realExecution();
        $mutant['tests_run'] = 0;
        $red = $svc->summarize($this->forgeCycle($mutant));
        $this->assertFalse($red['proven_real'], 'Forge stayed green after tests stripped — fake-green');
        $this->assertTrue($red['fake_green']);
        $this->assertFalse($red['should_promote_to_aemor'], 'Forge promoted a fake-green into learning — garbage-in');
        $this->assertTrue($red['human_review_required']);
        $this->assertContains(OutcomeProofGate::FAKE_GREEN_MARKER, $red['learning_candidates']);
    }

    public function test_forge_success_without_execution_block_is_unproven_not_fake_green(): void
    {
        // Legacy cycle with no gate_result.execution → unproven, NOT fake-green: promote stays baseline.
        $svc = new ForgeOutcomeMemoryService;
        $summary = $svc->summarize($this->forgeCycle([]));
        $this->assertFalse($summary['proven_real']);
        $this->assertFalse($summary['fake_green']);
        $this->assertTrue($summary['should_promote_to_aemor']);
    }

    public function test_product_outcome_is_green_on_real_run_and_red_when_mutated(): void
    {
        $svc = new AtlasProductDeliveryOutcomeMemoryService;
        $delivery = ['delivery_hash' => 'd1', 'route' => 'aedpds', 'status' => 'ready'];
        $evidence = ['tests' => ['ran' => true]];

        $green = $svc->build($delivery, ['status' => 'ready', 'execution' => $this->realExecution()], $evidence);
        $this->assertTrue($green['proven_real']);
        $this->assertFalse($green['fake_green']);
        $this->assertTrue($green['should_promote_to_aemor']);
        $this->assertNotContains(OutcomeProofGate::FAKE_GREEN_MARKER, $green['learning_candidates']);

        // MUTATION: claims ready but the suite ran 0 tests → fake-green, never promoted.
        $mutant = $this->realExecution();
        $mutant['tests_run'] = 0;
        $red = $svc->build($delivery, ['status' => 'ready', 'execution' => $mutant], $evidence);
        $this->assertFalse($red['proven_real'], 'Product stayed green after tests stripped — fake-green');
        $this->assertTrue($red['fake_green']);
        $this->assertFalse($red['should_promote_to_aemor'], 'Product promoted a fake-green into learning — garbage-in');
        $this->assertTrue($red['human_review_required']);
        $this->assertContains(OutcomeProofGate::FAKE_GREEN_MARKER, $red['learning_candidates']);
    }

    public function test_product_counter_counts_only_persisted_fake_green(): void
    {
        $migration = require base_path('database/migrations/2026_05_22_171000_create_atlas_product_delivery_outcome_memories.php');
        $migration->down();
        $migration->up();

        try {
            $svc = new AtlasProductDeliveryOutcomeMemoryService;
            $svc->persist(['delivery_hash' => 'ok', 'route' => 'r', 'status' => 'ready'],
                ['status' => 'ready', 'execution' => $this->realExecution()], ['tests' => [1]]);

            $mutant = $this->realExecution();
            $mutant['tests_run'] = 0;
            $svc->persist(['delivery_hash' => 'lie', 'route' => 'r', 'status' => 'ready'],
                ['status' => 'ready', 'execution' => $mutant], ['tests' => [1]]);

            $this->assertSame(1, AtlasProductDeliveryOutcomeMemoryService::fakeGreenSuppressedCount());
        } finally {
            $migration->down();
        }
    }
}
