<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRoadmapApIndexService;
use Tests\TestCase;

final class AtlasRoadmapApIndexTest extends TestCase
{
    private AtlasRoadmapApIndexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasRoadmapApIndexService();
    }

    public function testPhaseMapIsTheClosedOrderedZeroToTwelveSet(): void
    {
        $map = $this->service->phaseMap();

        // The doc defines exactly 13 phases, 0..12.
        $this->assertSame(13, $map['count']);
        $this->assertSame(0, $map['min']);
        $this->assertSame(12, $map['max']);

        // Phase numbers are contiguous 0..12 in order.
        $this->assertSame(range(0, 12), array_column($map['phases'], 'phase'));

        // Endpoint purposes are pinned verbatim from the Phase Map table.
        $this->assertSame(
            'Promote docs and establish canonical governance',
            $this->service->resolvePhase(0)['purpose']
        );
        $this->assertSame(
            'Self-Evolution Curator',
            $this->service->resolvePhase(12)['purpose']
        );
    }

    public function testResolvePhaseFlagsOutOfRangeWithoutGuessing(): void
    {
        $ok = $this->service->resolvePhase(7);
        $this->assertTrue($ok['resolved']);
        $this->assertSame('Provider Driver Contract', $ok['purpose']);

        // 13 is one past the documented maximum -> not resolved, no purpose.
        $over = $this->service->resolvePhase(13);
        $this->assertFalse($over['resolved']);
        $this->assertNull($over['purpose']);
        $this->assertSame('phase_out_of_range_0_to_12', $over['reason']);

        // Negative phase is equally out of range.
        $this->assertFalse($this->service->resolvePhase(-1)['resolved']);
    }

    public function testRouteApMapsNumbersToTheDocumentedFamilyRanges(): void
    {
        // Range boundaries from the Active AP Families table.
        $this->assertSame('retrieval_and_open_brain', $this->service->routeAp(100)['family']);
        $this->assertSame('retrieval_and_open_brain', $this->service->routeAp('AP-105')['family']);
        $this->assertSame('learning_proposals_and_inbox', $this->service->routeAp(106)['family']);
        $this->assertSame('learning_proposals_and_inbox', $this->service->routeAp(125)['family']);
        $this->assertSame('architecture_operations', $this->service->routeAp(130)['family']);
        $this->assertSame('decision_receipt_replay', $this->service->routeAp(134)['family']);
        $this->assertSame('ledger_projections', $this->service->routeAp(143)['family']);
        $this->assertSame('documentation_governance', $this->service->routeAp(177)['family']);

        // String forms normalize to the same routing.
        $this->assertSame('decision_receipt_replay', $this->service->routeAp('AP-140')['family']);
    }

    public function testProviderPerformanceFamilyOwnsBothItsRangeAndTheAp99Family(): void
    {
        // Documented as "AP-146 to AP-147 and AP-99 family".
        $this->assertSame('provider_performance', $this->service->routeAp(146)['family']);
        $this->assertSame('provider_performance', $this->service->routeAp(147)['family']);
        $this->assertSame('provider_performance', $this->service->routeAp('AP-99')['family']);
    }

    public function testRouteApReportsUnmappedNumbersInsteadOfGuessing(): void
    {
        // AP-300 is in no active family — it must be placed via the canonical index.
        $unmapped = $this->service->routeAp(300);
        $this->assertFalse($unmapped['resolved']);
        $this->assertNull($unmapped['family']);
        $this->assertSame('ap_not_in_active_families_place_via_canonical_index', $unmapped['reason']);

        // Singleton APs the doc calls out by name still resolve.
        $this->assertSame('external_graph_candidates', $this->service->routeAp(684)['family']);
        $this->assertSame('voice_runtime_boundaries', $this->service->routeAp(687)['family']);
        $this->assertSame('recurring_agent_behavior_schedule', $this->service->routeAp(161)['family']);
    }

    public function testAcceptanceIsNotDoneUntilAllSevenConditionsHold(): void
    {
        // Six of seven met (missing the guard-path test) -> not done, that one listed.
        $almost = $this->service->evaluateAcceptance([
            'contract_documented' => true,
            'artifact_exists_when_needed' => true,
            'events_and_read_models_declared' => true,
            'surfaces_aligned_when_applicable' => true,
            'tests_happy_and_guard_path' => false,
            'gates_run' => true,
            'knowledge_refreshed' => true,
        ]);
        $this->assertFalse($almost['done']);
        $this->assertSame('not_done', $almost['verdict']);
        $this->assertSame(7, $almost['total']);
        $this->assertSame(6, $almost['met_count']);
        $this->assertSame(['tests_happy_and_guard_path'], $almost['unmet']);
        $this->assertSame('ap_not_done_unmet_acceptance_conditions', $almost['reason']);

        // All seven met -> done.
        $complete = $this->service->evaluateAcceptance([
            'contract_documented' => true,
            'artifact_exists_when_needed' => true,
            'events_and_read_models_declared' => true,
            'surfaces_aligned_when_applicable' => true,
            'tests_happy_and_guard_path' => true,
            'gates_run' => true,
            'knowledge_refreshed' => true,
        ]);
        $this->assertTrue($complete['done']);
        $this->assertSame('done', $complete['verdict']);
        $this->assertSame([], $complete['unmet']);
    }

    public function testReadinessCannotBeDeclaredWithoutGreenGatesAndRefreshedKnowledge(): void
    {
        // forbidden_changes: even with every functional box ticked, claiming done
        // while the docs-health/architecture gate is red is demoted to not_done.
        $noGate = $this->service->evaluateAcceptance([
            'contract_documented' => true,
            'artifact_exists_when_needed' => true,
            'events_and_read_models_declared' => true,
            'surfaces_aligned_when_applicable' => true,
            'tests_happy_and_guard_path' => true,
            'gates_run' => false,
            'knowledge_refreshed' => true,
        ]);
        $this->assertFalse($noGate['done']);
        // gates_run is itself one of the seven, so it shows up unmet here.
        $this->assertContains('gates_run', $noGate['unmet']);

        // An empty signal map means nothing is proven -> not done, all unmet.
        $empty = $this->service->evaluateAcceptance([]);
        $this->assertFalse($empty['done']);
        $this->assertSame(0, $empty['met_count']);
        $this->assertSame(7, count($empty['unmet']));
    }
}
