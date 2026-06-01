<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRoadmapService;
use Tests\TestCase;

final class AtlasCognitiveRoadmapTest extends TestCase
{
    private AtlasCognitiveRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasCognitiveRoadmapService();
    }

    public function testAuthorityChainIsTheDocumentedOrderHighestFirst(): void
    {
        // Doc "Authority": Tese central > Kernel > este doc > spec individual.
        $this->assertSame([
            'tese_central',
            'kernel',
            'roadmap_doc',
            'spec_individual',
        ], AtlasCognitiveRoadmapService::AUTHORITY_CHAIN);
    }

    public function testAuthorityResolutionFollowsTheChain(): void
    {
        // Kernel outranks the roadmap doc.
        $vsKernel = $this->service->resolveAuthority('roadmap_doc', 'kernel');
        $this->assertSame('kernel', $vsKernel['winner']);
        $this->assertSame('higher_authority_in_chain', $vsKernel['reason']);

        // The roadmap doc outranks an individual spec.
        $vsSpec = $this->service->resolveAuthority('roadmap_doc', 'spec_individual');
        $this->assertSame('roadmap_doc', $vsSpec['winner']);

        // Tese central beats everything.
        $top = $this->service->resolveAuthority('spec_individual', 'tese_central');
        $this->assertSame('tese_central', $top['winner']);

        // Two unknown sources resolve to nobody (never guess an authority).
        $unknown = $this->service->resolveAuthority('chat', 'obsidian');
        $this->assertNull($unknown['winner']);
        $this->assertSame('both_sources_outside_authority_chain', $unknown['reason']);
    }

    public function testGoldenRuleBlocksOtherPhasesUntilDreyfusIsDelivered(): void
    {
        // Anti-pattern "Pular Fase 1 (Dreyfus)": cannot start Latticework first.
        $blocked = $this->service->gatePhaseStart('cross_domain_latticework', false);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('golden_rule_dreyfus_must_ship_first', $blocked['reason']);

        // Once Dreyfus is delivered the same phase is unblocked.
        $unblocked = $this->service->gatePhaseStart('cross_domain_latticework', true);
        $this->assertTrue($unblocked['allowed']);

        // Dreyfus itself is always allowed to start — it is the first phase.
        $first = $this->service->gatePhaseStart('dreyfus_dynamic_pedagogy', false);
        $this->assertTrue($first['allowed']);
        $this->assertTrue($first['is_dreyfus']);
    }

    public function testDefinitionOfDoneRequiresAllEightGates(): void
    {
        $allGates = [
            'has_ap' => true,
            'code_migration_tests' => true,
            'architecture_validate' => true,
            'docs_health' => true,
            'cli_or_api_e2e' => true,
            'evidence_events' => true,
            'slo_targets_measured' => true,
            'doc_points_to_ap' => true,
        ];

        // There are exactly eight documented DoD gates.
        $complete = $this->service->gateDefinitionOfDone($allGates);
        $this->assertSame(8, $complete['total_gates']);
        $this->assertSame(8, $complete['satisfied']);
        $this->assertTrue($complete['done']);
        $this->assertSame('implemented_operational_read_model', $complete['effective_status']);

        // Missing even one gate keeps the phase in scaffold.
        $missingOne = $allGates;
        $missingOne['evidence_events'] = false;
        $partial = $this->service->gateDefinitionOfDone($missingOne);
        $this->assertFalse($partial['done']);
        $this->assertSame('scaffold', $partial['effective_status']);
        $this->assertSame(['evidence_events'], $partial['missing']);
        $this->assertSame('definition_of_done_incomplete', $partial['reason']);
    }

    public function testContestedCapabilityCannotBecomeDefaultWithoutValidation(): void
    {
        // Anti-pattern: a contested/speculative capability may not be promoted without positive validation.
        $contestedBare = $this->service->gatePromoteToDefault('dual_n_back', 'contested', false);
        $this->assertFalse($contestedBare['promote_to_default']);
        $this->assertTrue($contestedBare['validation_gated']);
        $this->assertSame('contested_or_speculative_requires_positive_validation', $contestedBare['reason']);

        // Speculative is gated the same way.
        $speculative = $this->service->gatePromoteToDefault('tmr_reactivation', 'speculative', false);
        $this->assertFalse($speculative['promote_to_default']);

        // A positive validation unlocks promotion.
        $validated = $this->service->gatePromoteToDefault('dual_n_back', 'contested', true);
        $this->assertTrue($validated['promote_to_default']);

        // A stable capability is never validation-gated.
        $stable = $this->service->gatePromoteToDefault('spaced_repetition_engine', 'stable', false);
        $this->assertTrue($stable['promote_to_default']);
        $this->assertFalse($stable['validation_gated']);
    }

    public function testApStatusReflectsDocumentedReadModel(): void
    {
        $byId = [];
        foreach ($this->service->apStatus()['aps'] as $row) {
            $byId[$row['id']] = $row;
        }

        // AP-163 (Dreyfus) is the operational read model.
        $this->assertSame('implemented_operational_read_model', $byId['ap_163']['status']);
        $this->assertFalse($byId['ap_163']['partial']);
        $this->assertTrue($byId['ap_163']['status_known']);

        // The three explicitly partial APs carry the partial flag.
        $this->assertTrue($byId['ap_168']['partial']);
        $this->assertTrue($byId['ap_169']['partial']);
        $this->assertTrue($byId['ap_170']['partial']);

        // implemented_partial is an operational state outside the canonical taxonomy.
        $this->assertSame('implemented_partial', $byId['ap_170']['status']);
        $this->assertFalse($byId['ap_170']['status_known']);
    }
}
