<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryDossierService;
use Tests\TestCase;

/**
 * WIRING — the delivery dossier now SURFACES the AtlasLoopFeatureSequenceWalker's step plan (produced into
 * quality['feature_sequence_steps'] by AtlasLoopIntentVerifierFactory) so the operator sees a multi-atom
 * feature's step-by-step progress, not just the completeness checklist. Proves the surfacing is absent-safe,
 * loads the real walker shape, and is covered by the dossier's tamper-evident HMAC signature.
 *
 * Pure (no DB): dossierFor()/verify() are deterministic; one service instance signs + verifies consistently.
 */
final class AtlasLoopDeliveryDossierFeatureSequenceWiringTest extends TestCase
{
    private function service(): AtlasLoopDeliveryDossierService
    {
        return new AtlasLoopDeliveryDossierService;
    }

    /** Three walker-shape steps; the last carries is_last=true (its cumulative atoms ARE the whole feature). */
    private function walkerSteps(): array
    {
        return [
            ['step' => 1, 'active_atoms' => ['a1'], 'regression_atoms' => [], 'cumulative_atoms' => ['a1'], 'is_last' => false],
            ['step' => 2, 'active_atoms' => ['a2'], 'regression_atoms' => ['a1'], 'cumulative_atoms' => ['a1', 'a2'], 'is_last' => false],
            ['step' => 3, 'active_atoms' => ['a3'], 'regression_atoms' => ['a1', 'a2'], 'cumulative_atoms' => ['a1', 'a2', 'a3'], 'is_last' => true],
        ];
    }

    /** @param array<string,mixed> $quality */
    private function dossier(array $quality): array
    {
        return $this->service()->dossierFor([
            'proposal_id' => 'p-1',
            'target_path' => 'app/Feature/Thing.php',
            'objective' => 'ship the sequenced feature',
            'delivered_at' => '2026-06-24 00:00:00',
            'quality' => $quality,
        ]);
    }

    // (a) no feature_sequence_steps in quality ⇒ empty surfacing, total 0, last_is_full false — and verify() holds.
    public function test_absent_feature_sequence_surfaces_empty_and_verifies(): void
    {
        $svc = $this->service();
        $dossier = $svc->dossierFor([
            'proposal_id' => 'p-0',
            'target_path' => 'app/Feature/Thing.php',
            'objective' => 'no sequence',
            'delivered_at' => '2026-06-24 00:00:00',
            'quality' => ['completeness_checklist' => [['item' => 'x', 'done' => true]]],
        ]);

        $this->assertSame([], $dossier['feature_sequence_steps']);
        $this->assertSame(0, $dossier['feature_sequence_total_steps']);
        $this->assertFalse($dossier['feature_sequence_last_is_full']);
        $this->assertTrue($svc->verify($dossier), 'the dossier still verifies (signature covers the new empty keys)');
    }

    // (b) three walker steps ⇒ all three surfaced, total 3, last_is_full reflects the last step's is_last.
    public function test_three_walker_steps_are_surfaced_total_and_last_full(): void
    {
        $svc = $this->service();
        $dossier = $svc->dossierFor([
            'proposal_id' => 'p-1',
            'target_path' => 'app/Feature/Thing.php',
            'objective' => 'ship the sequenced feature',
            'delivered_at' => '2026-06-24 00:00:00',
            'quality' => ['feature_sequence_steps' => $this->walkerSteps()],
        ]);

        $this->assertCount(3, $dossier['feature_sequence_steps']);
        $this->assertSame(3, $dossier['feature_sequence_total_steps']);
        $this->assertTrue($dossier['feature_sequence_last_is_full'], 'last step is_last=true ⇒ cumulative atoms are the whole feature');
        $this->assertSame(['a1', 'a2', 'a3'], $dossier['feature_sequence_steps'][2]['cumulative_atoms']);
        $this->assertTrue($svc->verify($dossier));
    }

    // (c) tampering a surfaced step breaks the signature — the new keys are covered by tamper-evidence.
    public function test_tampering_a_feature_step_breaks_verify(): void
    {
        $svc = $this->service();
        $dossier = $svc->dossierFor([
            'proposal_id' => 'p-1',
            'target_path' => 'app/Feature/Thing.php',
            'objective' => 'ship the sequenced feature',
            'delivered_at' => '2026-06-24 00:00:00',
            'quality' => ['feature_sequence_steps' => $this->walkerSteps()],
        ]);
        $this->assertTrue($svc->verify($dossier), 'sanity: the untampered dossier verifies');

        $dossier['feature_sequence_steps'][0]['step'] = 999; // tamper

        $this->assertFalse($svc->verify($dossier), 'tampering a feature step must break the HMAC signature');
    }

    // last_is_full=false when the final step is not the last (defensive: reflects the real is_last).
    public function test_last_is_full_false_when_final_step_not_marked_last(): void
    {
        $steps = $this->walkerSteps();
        $steps[2]['is_last'] = false; // no terminal step
        $dossier = $this->dossier(['feature_sequence_steps' => $steps]);

        $this->assertSame(3, $dossier['feature_sequence_total_steps']);
        $this->assertFalse($dossier['feature_sequence_last_is_full']);
    }
}
