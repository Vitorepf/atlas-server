<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDualCoreEngineeringSystemService;
use Tests\TestCase;

final class AtlasDualCoreEngineeringSystemTest extends TestCase
{
    private AtlasDualCoreEngineeringSystemService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasDualCoreEngineeringSystemService();
    }

    /**
     * Doc "Fluxo": "Bug pequeno/local -> Dev". A small, clear, single-module
     * task stays on the fast path, sdd_required is false, and the decision is
     * always operator-visible with the canonical schema + dev evidence set.
     */
    public function testSmallClearTaskRoutesToDevFastPath(): void
    {
        $d = $this->service->decideRoute([
            'intent_summary' => 'Fix a local off-by-one bug',
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'expected_duration' => 'minutes',
            'modules_touched_estimate' => 1,
        ]);

        $this->assertSame('atlas.dual_core.route_decision.v1', $d['schema']);
        $this->assertSame('dev', $d['route']);
        $this->assertSame('small_clear_task_fast_path', $d['reason']);
        $this->assertFalse($d['sdd_required']);
        $this->assertFalse($d['escalation']);
        $this->assertTrue($d['operator_visible']);
        $this->assertSame([], $d['forge_signals']);
        $this->assertSame(['plan', 'receipt', 'verification'], $d['evidence_required']);
    }

    /**
     * Doc "Fluxo": "Prompt ambiguo mas pequeno/medio -> Dev Senior Engineer
     * Loop". Ambiguity alone must NEVER push a small task to Forge — that is
     * the "Dev e Forge mini" failure mode. It stays dev, with the loop reason.
     */
    public function testAmbiguousButSmallStaysDevNotForge(): void
    {
        $d = $this->service->decideRoute([
            'intent_summary' => 'Do something with the importer, not sure exactly what',
            'ambiguity_level' => 'high',
            'risk_level' => 'low',
            'expected_duration' => 'hours',
            'modules_touched_estimate' => 1,
        ]);

        $this->assertSame('dev', $d['route']);
        $this->assertSame('ambiguous_but_small_dev_senior_engineer_loop', $d['reason']);
        $this->assertFalse($d['escalation']);
    }

    /**
     * Doc "Fluxo": "Mudanca em muitos modulos -> Forge" (threshold = 4). The
     * many-modules signal forces Forge, sdd_required flips true, and the Forge
     * evidence set (sdd/work_packets/evidence_pack) is demanded. This pins the
     * "tarefa pesada nunca cai no Dev" guarantee.
     */
    public function testManyModulesIsBornAsObraForge(): void
    {
        $below = $this->service->decideRoute(['modules_touched_estimate' => 3]);
        $this->assertSame('dev', $below['route'], '3 modules is still fast-path Dev');

        $d = $this->service->decideRoute([
            'intent_summary' => 'Touch many modules across the app',
            'modules_touched_estimate' => 4,
        ]);

        $this->assertSame('forge', $d['route']);
        $this->assertSame('born_as_obra:many_modules', $d['reason']);
        $this->assertTrue($d['sdd_required']);
        $this->assertContains('many_modules', $d['forge_signals']);
        $this->assertSame(
            ['sdd', 'plan', 'work_packets', 'receipt', 'verification', 'evidence_pack'],
            $d['evidence_required'],
        );
    }

    /**
     * Doc "Fluxo": "Alto risco (dados, seguranca, billing, auth, compliance)
     * -> Forge" and "Trabalho de dias/semanas/meses -> Forge". Either signal
     * alone is sufficient; a small, low-ambiguity demand that is nonetheless
     * high-risk must be Forge.
     */
    public function testHighRiskAndLongDurationEachForceForge(): void
    {
        $highRisk = $this->service->decideRoute([
            'risk_level' => 'critical',
            'expected_duration' => 'hours',
            'modules_touched_estimate' => 1,
        ]);
        $this->assertSame('forge', $highRisk['route']);
        $this->assertContains('high_risk', $highRisk['forge_signals']);

        $longRun = $this->service->decideRoute([
            'risk_level' => 'low',
            'expected_duration' => 'weeks',
            'modules_touched_estimate' => 1,
        ]);
        $this->assertSame('forge', $longRun['route']);
        $this->assertContains('long_duration', $longRun['forge_signals']);
    }

    /**
     * Doc "Contratos": "route=dev_to_forge quando o Dev comecou ou analisou e
     * descobriu que passou do limite dele". When Dev is already in flight and
     * scope expanded, the route is the honest escalation — NOT a fresh `forge`
     * route — and it carries the escalation_packet evidence slot.
     */
    public function testDevInFlightWithExpandedScopeEscalates(): void
    {
        $d = $this->service->decideRoute([
            'intent_summary' => 'Started a small fix, it now spans many modules',
            'dev_in_flight' => true,
            'scope_expanded' => true,
            'modules_touched_estimate' => 8,
            'risk_level' => 'high',
        ]);

        $this->assertSame('dev_to_forge', $d['route']);
        $this->assertSame('dev_in_flight_scope_expanded_to_obra', $d['reason']);
        $this->assertTrue($d['escalation']);
        $this->assertTrue($d['sdd_required']);
        $this->assertContains('escalation_packet', $d['evidence_required']);
    }

    /**
     * Schema/enum hardening: unknown enum values fall back to the safe
     * defaults rather than leaking through, and the route stays inside the
     * three canonical values. An empty intent gets a non-empty placeholder so
     * the auditable payload is never blank.
     */
    public function testInvalidEnumsFallBackAndRouteStaysCanonical(): void
    {
        $d = $this->service->decideRoute([
            'intent_summary' => '   ',
            'ambiguity_level' => 'bogus',
            'risk_level' => 'apocalyptic',
            'expected_duration' => 'eternity',
            'modules_touched_estimate' => 0,
        ]);

        $this->assertContains($d['route'], AtlasDualCoreEngineeringSystemService::ROUTES);
        $this->assertSame('dev', $d['route']);
        $this->assertSame('low', $d['ambiguity_level']);
        $this->assertSame('low', $d['risk_level']);
        $this->assertSame('minutes', $d['expected_duration']);
        $this->assertSame(1, $d['modules_touched_estimate']);
        $this->assertSame('unspecified_programming_demand', $d['intent_summary']);
    }
}
