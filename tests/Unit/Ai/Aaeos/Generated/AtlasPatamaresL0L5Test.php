<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPatamaresL0L5Service;
use Tests\TestCase;

/**
 * Pins the documented Obras maturity-ladder rules: the ordered L0..L5 ladder,
 * monotonic promotion, the L0 minimum contract (12 types / 7 statuses), the
 * A0..A5 autonomy gate ("initial target A2/A3", "Enterprise A4+ requires logs,
 * permissions and reversibility") and the six L5 non-negotiable gates.
 *
 * Pure, no database, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/obras/patamares-l0-l5.md
 */
class AtlasPatamaresL0L5Test extends TestCase
{
    private function service(): AtlasPatamaresL0L5Service
    {
        return new AtlasPatamaresL0L5Service();
    }

    /** The doc defines exactly six ordered levels L0..L5, each with a readiness gate. */
    public function test_ladder_has_six_ordered_levels_with_readiness(): void
    {
        $this->assertSame(
            ['L0', 'L1', 'L2', 'L3', 'L4', 'L5'],
            AtlasPatamaresL0L5Service::LEVELS,
        );

        $levels = $this->service()->levels();
        $this->assertCount(6, $levels);
        $this->assertSame('L0', $levels[0]['level']);
        $this->assertSame(0, $levels[0]['index']);
        // L0 readiness gate is the documented "Ready when ..." for Tela Obras.
        $this->assertStringContainsString('create, list, open', $levels[0]['ready_when']);
    }

    /**
     * Promotion is monotonic: one step up is allowed only when the source level
     * is ready; skipping a level and demotion are both blocked.
     */
    public function test_promotion_is_one_step_and_gated_by_readiness(): void
    {
        $svc = $this->service();

        // One step, source ready -> allowed.
        $ok = $svc->evaluatePromotion('L1', 'L2', true);
        $this->assertSame('pass', $ok['status']);
        $this->assertTrue($ok['allowed']);
        $this->assertSame([], $ok['blocking_reasons']);

        // One step, source NOT ready -> blocked with the readiness reason.
        $notReady = $svc->evaluatePromotion('L1', 'L2', false);
        $this->assertFalse($notReady['allowed']);
        $this->assertContains(
            'source_level_not_ready: L1 readiness gate is not satisfied',
            $notReady['blocking_reasons'],
        );

        // Skipping a level is forbidden even when ready.
        $skip = $svc->evaluatePromotion('L0', 'L2', true);
        $this->assertFalse($skip['allowed']);
        $this->assertContains(
            'level_skip_forbidden: promotion must be one step (got L0 -> L2)',
            $skip['blocking_reasons'],
        );

        // Demotion is not a promotion.
        $down = $svc->evaluatePromotion('L3', 'L2', true);
        $this->assertFalse($down['allowed']);
        $this->assertContains('demotion_not_a_promotion: L3 -> L2', $down['blocking_reasons']);
    }

    /** L0 minimum contract: 12 valid types, 7 valid statuses; out-of-set values are rejected. */
    public function test_l0_contract_enforces_closed_type_and_status_sets(): void
    {
        $this->assertCount(12, AtlasPatamaresL0L5Service::OBRA_TYPES);
        $this->assertCount(7, AtlasPatamaresL0L5Service::OBRA_STATUSES);

        $base = [
            'id' => 'o1', 'name' => 'n', 'type' => 'technical', 'domain' => 'd',
            'objective' => 'o', 'status' => 'Idea', 'deadline' => '2026-01-01',
            'priority' => 'high', 'next_step' => 'step', 'description' => 'desc',
        ];

        $valid = $this->service()->validateL0Obra($base);
        $this->assertSame('pass', $valid['status']);
        $this->assertTrue($valid['valid']);

        // Unknown type + unknown status both surface as blocking reasons.
        $bad = $base;
        $bad['type'] = 'gardening';
        $bad['status'] = 'Paused';
        $report = $this->service()->validateL0Obra($bad);
        $this->assertFalse($report['valid']);
        $this->assertSame('gardening', $report['invalid_type']);
        $this->assertSame('Paused', $report['invalid_status']);

        // A missing required field is reported.
        $missing = $base;
        $missing['next_step'] = '   ';
        $miss = $this->service()->validateL0Obra($missing);
        $this->assertFalse($miss['valid']);
        $this->assertContains('next_step', $miss['missing_fields']);
    }

    /**
     * Autonomy gate: A2/A3 is the personal ceiling (allowed in personal mode);
     * A4+ is blocked unless enterprise mode AND logs+permissions+reversibility.
     */
    public function test_autonomy_ceiling_and_enterprise_controls(): void
    {
        $svc = $this->service();

        // A3 in personal mode -> permitted, and it actually runs work.
        $a3 = $svc->evaluateAutonomy('A3', false, []);
        $this->assertTrue($a3['permitted']);
        $this->assertTrue($a3['runs']);
        $this->assertFalse($a3['requires_enterprise_controls']);

        // A4 in personal mode -> blocked: above ceiling AND missing all controls.
        $a4personal = $svc->evaluateAutonomy('A4', false, []);
        $this->assertFalse($a4personal['permitted']);
        $this->assertTrue($a4personal['requires_enterprise_controls']);
        $this->assertSame(['logs', 'permissions', 'reversibility'], $a4personal['missing_controls']);

        // A4 in enterprise mode WITH all controls -> permitted.
        $a4ok = $svc->evaluateAutonomy('A4', true, [
            'logs' => true, 'permissions' => true, 'reversibility' => true,
        ]);
        $this->assertTrue($a4ok['permitted']);

        // A4 enterprise but missing reversibility -> still blocked.
        $a4partial = $svc->evaluateAutonomy('A4', true, [
            'logs' => true, 'permissions' => true, 'reversibility' => false,
        ]);
        $this->assertFalse($a4partial['permitted']);
        $this->assertSame(['reversibility'], $a4partial['missing_controls']);
    }

    /**
     * L5 sovereign gates: a strategy passes only when it violates none of the six
     * non-negotiable rules; any breach blocks and is named.
     */
    public function test_sovereign_gates_block_on_any_violation(): void
    {
        $svc = $this->service();

        $clean = $svc->evaluateSovereignGates([]);
        $this->assertSame('pass', $clean['status']);
        $this->assertTrue($clean['admissible']);
        $this->assertSame([], $clean['violated_gates']);

        // Money up while destroying health -> hard block (the first documented gate).
        $breach = $svc->evaluateSovereignGates([
            'increases_money_destroying_health' => true,
        ]);
        $this->assertFalse($breach['admissible']);
        $this->assertSame(['increases_money_destroying_health'], $breach['violated_gates']);
        $this->assertContains(
            'sovereign_gate_violated: no strategy may increase money while destroying health',
            $breach['blocking_reasons'],
        );
    }

    /** The aggregate audit fails if any single surface fails. */
    public function test_audit_aggregates_to_fail_on_any_subcheck(): void
    {
        $result = $this->service()->audit([
            'autonomy' => ['level' => 'A5', 'enterprise' => false, 'controls' => []],
            'sovereign' => [],
        ]);

        $this->assertSame('fail', $result['status']);
        $this->assertSame('fail', $result['checks']['autonomy']['status']);
        $this->assertCount(6, $result['levels']);
    }
}
