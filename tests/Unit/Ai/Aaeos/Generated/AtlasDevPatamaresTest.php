<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevPatamaresService;
use Tests\TestCase;

/**
 * Pins the conceptual-ladder contract from the Atlas Dev Patamares doc: the ordered
 * A0..A7 ladder (A0 passado, A1 em construcao), exactly one current patamar, the
 * future-patamar anti-pattern guard (A4 blocked while A1 is current), and the
 * "Mudanca De Patamar" promotion process — one monotonic step, all five gates,
 * no "v2/v3" identities. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-patamares.md
 */
class AtlasDevPatamaresTest extends TestCase
{
    private function service(): AtlasDevPatamaresService
    {
        return new AtlasDevPatamaresService;
    }

    public function test_ladder_is_A0_through_A7_with_A0_passado_and_A1_em_construcao(): void
    {
        $levels = $this->service()->levels();

        $this->assertCount(8, $levels);
        $this->assertSame(
            ['A0', 'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7'],
            array_column($levels, 'level'),
        );

        // A0 is historical/passed; A1 is the one under construction today.
        $byLevel = array_column($levels, null, 'level');
        $this->assertSame(AtlasDevPatamaresService::STATUS_PASSADO, $byLevel['A0']['status']);
        $this->assertSame(AtlasDevPatamaresService::STATUS_EM_CONSTRUCAO, $byLevel['A1']['status']);

        // Prerequisite chain: each patamar above A0 requires the previous cert green.
        $this->assertNull($byLevel['A0']['prerequisite']);
        $this->assertSame('A1_cert_green', $byLevel['A2']['prerequisite']);
    }

    public function test_exactly_one_patamar_is_em_construcao_and_it_is_A1(): void
    {
        $current = $this->service()->currentPatamar();

        $this->assertSame(AtlasDevPatamaresService::STATUS_PASS, $current['status']);
        $this->assertSame('A1', $current['level']);
        $this->assertSame([], $current['blocking_reasons']);
    }

    public function test_future_patamar_is_an_anti_pattern_but_current_and_next_are_allowed(): void
    {
        $service = $this->service();

        // Doc failure mode #1: building A4 while A1 (current) is not yet green.
        $future = $service->evaluateWorkScope('A4');
        $this->assertFalse($future['allowed']);
        $this->assertSame('future', $future['classification']);
        $this->assertStringContainsString('future_patamar_anti_pattern', $future['blocking_reasons'][0]);

        // The current patamar is always a valid scope.
        $now = $service->evaluateWorkScope('A1');
        $this->assertTrue($now['allowed']);
        $this->assertSame('current', $now['classification']);

        // Exactly the next planejado patamar is allowed (one step ahead).
        $next = $service->evaluateWorkScope('A2');
        $this->assertTrue($next['allowed']);
        $this->assertSame('next_planned', $next['classification']);

        // A passado patamar is a regression, not work scope.
        $past = $service->evaluateWorkScope('A0');
        $this->assertFalse($past['allowed']);
        $this->assertSame('past', $past['classification']);
    }

    public function test_promotion_requires_one_step_plus_all_five_gates(): void
    {
        $service = $this->service();
        $allGates = [
            'ap_approved' => true,
            'capability_phrase_defined' => true,
            'components_implemented_and_tested' => true,
            'previous_cert_green' => true,
            'status_bump' => true,
        ];

        // Valid single-step promotion with all gates met.
        $ok = $service->evaluatePromotion('A1', 'A2', $allGates);
        $this->assertTrue($ok['allowed']);
        $this->assertSame([], $ok['missing_gates']);

        // Dropping one gate (no AP) blocks the promotion and names the gap.
        $missingAp = $allGates;
        $missingAp['ap_approved'] = false;
        $blocked = $service->evaluatePromotion('A1', 'A2', $missingAp);
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('ap_approved', $blocked['missing_gates']);

        // Skipping a patamar (A1 -> A3) is forbidden even with all gates.
        $skip = $service->evaluatePromotion('A1', 'A3', $allGates);
        $this->assertFalse($skip['allowed']);
        $this->assertStringContainsString(
            'patamar_skip_forbidden',
            implode('|', $skip['blocking_reasons']),
        );
    }

    public function test_numeric_version_name_is_rejected_as_a_patamar_identity(): void
    {
        // The doc forbids "v2/v3" as patamar identities; a vN target is rejected.
        $verdict = $this->service()->evaluatePromotion('A1', 'atlas-dev-v2', [
            'ap_approved' => true,
            'capability_phrase_defined' => true,
            'components_implemented_and_tested' => true,
            'previous_cert_green' => true,
            'status_bump' => true,
        ]);

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString(
            'numeric_version_forbidden',
            implode('|', $verdict['blocking_reasons']),
        );
    }

    public function test_audit_is_green_for_a_conformant_bundle_and_red_when_scope_is_future(): void
    {
        $service = $this->service();
        $allGates = [
            'ap_approved' => true,
            'capability_phrase_defined' => true,
            'components_implemented_and_tested' => true,
            'previous_cert_green' => true,
            'status_bump' => true,
        ];

        $green = $service->audit([
            'work_scope' => ['requested' => 'A1'],
            'promotion' => ['from' => 'A1', 'to' => 'A2', 'gates' => $allGates],
        ]);
        $this->assertSame(AtlasDevPatamaresService::STATUS_PASS, $green['status']);
        $this->assertSame('A1', $green['current']['level']);

        // A future-patamar work scope drags the whole audit to fail.
        $red = $service->audit([
            'work_scope' => ['requested' => 'A5'],
        ]);
        $this->assertSame(AtlasDevPatamaresService::STATUS_FAIL, $red['status']);
    }
}
