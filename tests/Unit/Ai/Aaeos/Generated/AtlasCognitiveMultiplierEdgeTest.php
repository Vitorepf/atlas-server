<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveMultiplierEdgeService;
use Tests\TestCase;

final class AtlasCognitiveMultiplierEdgeTest extends TestCase
{
    private AtlasCognitiveMultiplierEdgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasCognitiveMultiplierEdgeService();
    }

    public function testRegistryHasTheTenCardinalCapabilitiesWithDocumentedPhasesAndAps(): void
    {
        // Doc "As 10 Capabilities Cardinais": exactly ten rows.
        $registry = $this->service->capabilities();
        $this->assertSame(10, $registry['total']);
        $this->assertCount(10, $registry['capabilities']);

        // Capability 1 (Dreyfus) is Fase 1, the operational read model, AP-163.
        $dreyfus = $this->service->capability('dreyfus_dynamic_pedagogy');
        $this->assertTrue($dreyfus['found']);
        $this->assertSame(1, $dreyfus['capability']['number']);
        $this->assertSame(1, $dreyfus['capability']['phase']);
        $this->assertSame('AP-163', $dreyfus['capability']['ap']);

        // Capability 4 (Discord Detector) is the documented opt-in cardinal.
        $discord = $this->service->capability('multi_provider_discord_detector');
        $this->assertTrue($discord['capability']['opt_in']);

        // An unknown capability id is reported not found, never guessed.
        $unknown = $this->service->capability('made_up_capability');
        $this->assertFalse($unknown['found']);
        $this->assertNull($unknown['capability']);
    }

    public function testGoldenRuleBlocksEveryOtherCapabilityUntilDreyfusIsDelivered(): void
    {
        // Doc "Regra de ouro": Fase 1 (Dreyfus) sai antes de qualquer outra.
        $blocked = $this->service->gatePhaseStart('cross_domain_latticework', false);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('golden_rule_phase_1_dreyfus_must_ship_first', $blocked['reason']);

        // Once Dreyfus is delivered the same capability is unblocked.
        $unblocked = $this->service->gatePhaseStart('cross_domain_latticework', true);
        $this->assertTrue($unblocked['allowed']);

        // Dreyfus itself is always allowed to start — it is Fase 1.
        $first = $this->service->gatePhaseStart('dreyfus_dynamic_pedagogy', false);
        $this->assertTrue($first['allowed']);
        $this->assertTrue($first['is_dreyfus']);
    }

    public function testDreyfusPedagogyMapsEachOfTheFiveLevelsToItsDocumentedPedagogy(): void
    {
        // Doc "Capability 1" table: novato gets explicit rules + heavy scaffolding.
        $novato = $this->service->resolveDreyfusPedagogy('novato');
        $this->assertTrue($novato['known']);
        $this->assertSame('regras_explicitas_scaffolding_pesado_feedback_em_cada_gesto', $novato['pedagogy']);

        // Master gets peer dialogue / doctrine creation / forming others.
        $master = $this->service->resolveDreyfusPedagogy('master');
        $this->assertSame('dialogo_de_pares_criacao_de_doutrina_formacao_de_outros', $master['pedagogy']);

        // All five documented levels are present.
        $this->assertCount(5, $this->service->snapshot()['dreyfus_levels']);

        // An unrecognised level returns no pedagogy — never fall back to a wrong stage.
        $bad = $this->service->resolveDreyfusPedagogy('semi_expert');
        $this->assertFalse($bad['known']);
        $this->assertNull($bad['pedagogy']);
    }

    public function testDiscordDetectorIsNeverDefaultAndRespectsAllowAndDenyLists(): void
    {
        // Cardinal rule "NUNCA default": even on an allowed surface, default mode is blocked.
        $asDefault = $this->service->gateDiscordDetector('debate', true);
        $this->assertFalse($asDefault['allowed']);
        $this->assertSame('discord_detector_never_default', $asDefault['reason']);

        // Allowed surface, explicit (non-default) invocation: permitted.
        $explicit = $this->service->gateDiscordDetector('debate', false);
        $this->assertTrue($explicit['allowed']);
        $this->assertSame('discord_detector_allowed_opt_in_surface', $explicit['reason']);

        // Doc "Proibido em": flashcard / active_recall / daily_plan / micro_session are blocked.
        $flashcard = $this->service->gateDiscordDetector('flashcard', false);
        $this->assertFalse($flashcard['allowed']);
        $this->assertSame('discord_detector_forbidden_surface', $flashcard['reason']);

        $daily = $this->service->gateDiscordDetector('daily_plan', false);
        $this->assertFalse($daily['allowed']);

        // A surface that is neither allowed nor forbidden is not in the allow-list -> blocked.
        $other = $this->service->gateDiscordDetector('random_surface', false);
        $this->assertFalse($other['allowed']);
        $this->assertSame('discord_detector_surface_not_in_allow_list', $other['reason']);
    }

    public function testWorkedExampleFadesWithOperatorStage(): void
    {
        // Doc "Capability 8" table: novato gets the full example.
        $novato = $this->service->resolveWorkedExampleFading('novato');
        $this->assertSame('exemplo_completo_solucao_mais_raciocinio_passo_a_passo', $novato['output']);

        // Expert only gets the problem and reconstructs the rest.
        $expert = $this->service->resolveWorkedExampleFading('expert');
        $this->assertSame('so_problema_voce_reconstroi', $expert['output']);

        // Competente gets a partially-faded example with 2-3 missing steps.
        $competente = $this->service->resolveWorkedExampleFading('competente');
        $this->assertSame('exemplo_com_2_3_etapas_faltando_para_preencher', $competente['output']);
    }

    public function testCrossDomainRoutingMayReprioritiseButNeverEnrolInNewArea(): void
    {
        // Doc "Capability 6": routing prioritises the existing queue.
        $reprioritise = $this->service->gateCrossDomainRouting('reprioritize_existing_queue');
        $this->assertTrue($reprioritise['allowed']);
        $this->assertSame('routing_reprioritises_existing_queue_only', $reprioritise['reason']);

        // Anti-pattern "Cross-Domain Routing matricular automaticamente": new-area enrolment is blocked.
        $enrol = $this->service->gateCrossDomainRouting('enrol_new_area');
        $this->assertFalse($enrol['allowed']);
        $this->assertSame('routing_must_not_enrol_in_new_area', $enrol['reason']);
    }

    public function testPredictiveFailureInsertionRequiresAnExplicitNonUnknownTarget(): void
    {
        // Doc "Capability 9": alvo explicito obrigatorio, nunca `unknown`.
        $unknown = $this->service->gatePredictiveFailureInsertion('unknown');
        $this->assertFalse($unknown['allowed']);
        $this->assertFalse($unknown['has_explicit_target']);
        $this->assertSame('predictive_failure_target_unknown_forbidden', $unknown['reason']);

        // An empty target is equally rejected.
        $empty = $this->service->gatePredictiveFailureInsertion('   ');
        $this->assertFalse($empty['allowed']);
        $this->assertSame('predictive_failure_requires_explicit_target', $empty['reason']);

        // A concrete target node is accepted.
        $explicit = $this->service->gatePredictiveFailureInsertion('laravel_queue_backpressure');
        $this->assertTrue($explicit['allowed']);
        $this->assertTrue($explicit['has_explicit_target']);
    }
}
