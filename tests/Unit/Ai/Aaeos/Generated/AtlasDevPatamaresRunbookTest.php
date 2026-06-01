<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevPatamaresRunbookService;
use Tests\TestCase;

/**
 * Pins the documented decision rules of the Atlas Dev Patamares Runbook:
 * the A0..A7 ladder, the promotion gate (all source slices green + the
 * numeric gate threshold) and the A3 scope-violation hard block.
 *
 * Pure logic — no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-patamares-runbook.md
 */
final class AtlasDevPatamaresRunbookTest extends TestCase
{
    private AtlasDevPatamaresRunbookService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AtlasDevPatamaresRunbookService();
    }

    /** Doc: the ladder is exactly the 8 patamares A0..A7, in order, A1 binds atlas.dev.run.v1. */
    public function test_ladder_has_eight_patamares_a0_to_a7_with_documented_dtos(): void
    {
        $rows = $this->svc->patamares();

        $this->assertCount(8, $rows);
        $this->assertSame(
            ['A0', 'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7'],
            array_column($rows, 'patamar'),
        );

        $this->assertNull($this->svc->dtoFor('A0'), 'A0 has no DTO (n/a in the doc table).');
        $this->assertSame('atlas.dev.run.v1', $this->svc->dtoFor('A1'));
        $this->assertSame('atlas.dev.scope_guard.v1', $this->svc->dtoFor('A3'));
        $this->assertSame('atlas.dev.self_improvement.v1', $this->svc->dtoFor('A7'));
    }

    /** Doc: A1 -> A2 promotes only when all 4 A1 slices are green AND 50-run gate met. */
    public function test_a1_to_a2_promotes_when_all_slices_green_and_50_run_gate_met(): void
    {
        $verdict = $this->svc->evaluatePromotion(
            'A1',
            'A2',
            [
                'discovery' => true,
                'prompt projection' => true,
                'telemetry' => true,
                'persistence' => true,
            ],
            50,
        );

        $this->assertTrue($verdict['promote']);
        $this->assertSame('pass', $verdict['status']);
        $this->assertSame(50, $verdict['gate_required']);
        $this->assertTrue($verdict['gate_met']);
        $this->assertSame([], $verdict['missing_slices']);
        $this->assertSame([], $verdict['blocking_reasons']);
    }

    /** Doc: gate threshold is a hard floor — 49 green runs is NOT enough for A1 -> A2. */
    public function test_a1_to_a2_blocked_below_50_run_gate(): void
    {
        $verdict = $this->svc->evaluatePromotion(
            'A1',
            'A2',
            [
                'discovery' => true,
                'prompt projection' => true,
                'telemetry' => true,
                'persistence' => true,
            ],
            49,
        );

        $this->assertFalse($verdict['promote']);
        $this->assertSame('fail', $verdict['status']);
        $this->assertFalse($verdict['gate_met']);
        $this->assertContains(
            'gate_threshold_not_met: need 50 (50 runs verdes A1), have 49',
            $verdict['blocking_reasons'],
        );
    }

    /** Doc "Regras para IA": a missing source slice blocks promotion even if the gate count is met. */
    public function test_missing_source_slice_blocks_promotion(): void
    {
        $verdict = $this->svc->evaluatePromotion(
            'A1',
            'A2',
            [
                'discovery' => true,
                'prompt projection' => false, // not green
                'telemetry' => true,
                'persistence' => true,
            ],
            999,
        );

        $this->assertFalse($verdict['promote']);
        $this->assertSame(['prompt projection'], $verdict['missing_slices']);
        $this->assertContains('source_slices_not_green: prompt projection', $verdict['blocking_reasons']);
    }

    /** Doc Fluxo: at A3 any scope violation blocks promotion regardless of green count. */
    public function test_a3_scope_violation_blocks_promotion(): void
    {
        $allGreenA3 = [
            'scope guard runtime' => true,
            'verification receipt' => true,
            'provider attestation' => true,
        ];

        // Clean A3 with the 20-run gate met -> promotes.
        $clean = $this->svc->evaluatePromotion('A3', 'A4', $allGreenA3, 20, 0);
        $this->assertTrue($clean['promote']);

        // Same, but one scope violation present -> blocked.
        $violated = $this->svc->evaluatePromotion('A3', 'A4', $allGreenA3, 20, 1);
        $this->assertFalse($violated['promote']);
        $this->assertContains(
            'scope_violation_blocks_promotion: 1 violation(s) at A3',
            $violated['blocking_reasons'],
        );
    }

    /** Doc: promotion is monotonic one-step — skipping (A1 -> A3) and demotion are forbidden. */
    public function test_skip_and_demotion_are_forbidden(): void
    {
        $skip = $this->svc->evaluatePromotion('A1', 'A3', [], 0);
        $this->assertFalse($skip['promote']);
        $this->assertContains(
            'patamar_skip_forbidden: promotion must be one step (got A1 -> A3)',
            $skip['blocking_reasons'],
        );

        $demote = $this->svc->evaluatePromotion('A3', 'A2', [], 0);
        $this->assertFalse($demote['promote']);
        $this->assertContains('demotion_not_a_promotion: A3 -> A2', $demote['blocking_reasons']);
    }
}
