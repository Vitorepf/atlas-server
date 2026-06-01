<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasResearchIntelligencePipelineService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Research Intelligence Pipeline contracts.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md
 */
class AtlasResearchIntelligencePipelineTest extends TestCase
{
    private function service(): AtlasResearchIntelligencePipelineService
    {
        return new AtlasResearchIntelligencePipelineService();
    }

    /**
     * Contract 1 — pipeline ordering. Steps advance strictly in order; the
     * first incomplete step is "next"; a step completed before its predecessor
     * is flagged out of order; "decide" is unreachable until "create_packet".
     */
    public function test_pipeline_advances_in_documented_order(): void
    {
        $svc = $this->service();

        // Fresh pipeline: the only runnable step is the first one.
        $fresh = $svc->advancePipeline([]);
        $this->assertSame('define_objective', $fresh['next_step']);
        $this->assertFalse($fresh['complete']);
        $this->assertTrue($fresh['ordered']);

        // First two done => next is the third (classify_tier).
        $two = $svc->advancePipeline(['define_objective', 'discover_sources']);
        $this->assertSame('classify_tier', $two['next_step']);
        $this->assertSame(2, $two['completed_count']);

        // "Research must be packetized before it changes docs": with everything
        // up to create_packet done but the packet NOT yet created, the next step
        // is create_packet, NOT decide.
        $preDecide = $svc->advancePipeline([
            'define_objective', 'discover_sources', 'classify_tier',
            'extract_claims', 'detect_conflicts', 'synthesize_impact',
        ]);
        $this->assertSame('create_packet', $preDecide['next_step']);
        $this->assertSame('must_packetize_before_decide', $preDecide['reason']);

        // Out of order: decide marked done while create_packet is not.
        $disordered = $svc->advancePipeline(['define_objective', 'decide']);
        $this->assertFalse($disordered['ordered']);
        $this->assertSame('decide', $disordered['blocked_step']);
        $this->assertSame('out_of_order_step_completed_before_predecessor', $disordered['reason']);

        // All 8 done => complete, no next step.
        $complete = $svc->advancePipeline(AtlasResearchIntelligencePipelineService::STEPS);
        $this->assertTrue($complete['complete']);
        $this->assertNull($complete['next_step']);
    }

    /**
     * Contract 2 — discovery mix. "repo evidence first" makes repo a required
     * anchor; "community only as discovery lead" means a community-only mix is
     * rejected (no backing), while community alongside real backing is a valid
     * lead.
     */
    public function test_discovery_mix_requires_repo_anchor_and_rejects_community_only(): void
    {
        $svc = $this->service();

        // Community only => not backing, unbalanced.
        $communityOnly = $svc->assessDiscoveryMix(['community']);
        $this->assertFalse($communityOnly['balanced']);
        $this->assertFalse($communityOnly['has_backing']);
        $this->assertSame('missing_repo_evidence_anchor', $communityOnly['reason']);

        // Official doc + paper but NO repo => still missing the anchor.
        $noRepo = $svc->assessDiscoveryMix(['official_doc', 'paper']);
        $this->assertFalse($noRepo['balanced']);
        $this->assertTrue($noRepo['has_backing']);
        $this->assertContains('repo', $noRepo['missing']);

        // Repo + official_doc + community => balanced, community is lead-only.
        $balanced = $svc->assessDiscoveryMix(['repo', 'official_doc', 'community']);
        $this->assertTrue($balanced['balanced']);
        $this->assertTrue($balanced['has_repo_anchor']);
        $this->assertTrue($balanced['community_as_lead_only']);
        $this->assertSame('balanced_mix', $balanced['reason']);

        // Empty set => empty.
        $this->assertSame('empty_discovery_set', $svc->assessDiscoveryMix([])['reason']);
    }

    /**
     * Contract 3 — output quality. The doc's bad signals always fail; the
     * load-bearing good signals are required. Generic/citation-free output is
     * not publishable; specific/source-backed/conflict-aware/bounded/actionable
     * output is.
     */
    public function test_output_quality_classifier_enforces_good_and_bad_signals(): void
    {
        $svc = $this->service();

        // A single bad signal poisons the output even if good ones are present.
        $hyped = $svc->classifyOutputQuality([
            'specific', 'source_backed', 'conflict_aware', 'bounded', 'actionable', 'hype_driven',
        ]);
        $this->assertFalse($hyped['publishable']);
        $this->assertContains('hype_driven', $hyped['bad']);
        $this->assertSame('has_bad_signals', $hyped['reason']);

        // Missing a required good signal (no conflict_aware) => not publishable.
        $blind = $svc->classifyOutputQuality(['specific', 'source_backed', 'bounded', 'actionable']);
        $this->assertFalse($blind['publishable']);
        $this->assertContains('conflict_aware', $blind['missing_required']);
        $this->assertSame('missing_required_good_signals', $blind['reason']);

        // All required good signals, zero bad => publishable.
        $clean = $svc->classifyOutputQuality([
            'specific', 'source_backed', 'conflict_aware', 'time_aware', 'actionable', 'bounded',
        ]);
        $this->assertTrue($clean['publishable']);
        $this->assertSame([], $clean['bad']);
        $this->assertSame('publishable', $clean['reason']);
    }

    /**
     * Contract 4 — terminal disposition. The binding decision "preserve
     * uncertainty and conflicting evidence" means an unresolved conflict or a
     * live uncertainty can NEVER promote: it routes to research_more. A clean,
     * publishable, impactful packet promotes.
     */
    public function test_disposition_preserves_conflicts_and_uncertainty(): void
    {
        $svc = $this->service();

        // Unresolved conflict => research_more, never promote, even though
        // quality and impact are fine.
        $conflicted = $svc->decidePacketDisposition([
            'atlas_impact' => ['touches docs'],
            'conflicts' => [['id' => 'c1', 'resolved' => false]],
            'uncertainties' => [],
            'recommended_action' => 'promote_to_doc',
            'quality_publishable' => true,
        ]);
        $this->assertSame('research_more', $conflicted['disposition']);
        $this->assertFalse($conflicted['may_change_atlas']);
        $this->assertTrue($conflicted['preserved_uncertainty']);
        $this->assertSame(1, $conflicted['open_conflicts']);

        // Live uncertainty (bare-string item) => research_more.
        $uncertain = $svc->decidePacketDisposition([
            'atlas_impact' => ['x'],
            'conflicts' => [],
            'uncertainties' => ['unknown latency under load'],
            'recommended_action' => 'promote_to_doc',
            'quality_publishable' => true,
        ]);
        $this->assertSame('research_more', $uncertain['disposition']);
        $this->assertSame(1, $uncertain['open_uncertainties']);

        // Non-publishable quality => hold (cannot mutate Atlas from it).
        $held = $svc->decidePacketDisposition([
            'atlas_impact' => ['x'],
            'conflicts' => [],
            'uncertainties' => [],
            'recommended_action' => 'promote_to_doc',
            'quality_publishable' => false,
        ]);
        $this->assertSame('hold', $held['disposition']);
        $this->assertSame('quality_not_publishable_hold', $held['reason']);

        // No impact + recommended archive => archive.
        $archived = $svc->decidePacketDisposition([
            'atlas_impact' => [],
            'conflicts' => [],
            'uncertainties' => [],
            'recommended_action' => 'archive',
            'quality_publishable' => true,
        ]);
        $this->assertSame('archive', $archived['disposition']);

        // Clean, publishable, impactful, conflict-free => promote.
        $promoted = $svc->decidePacketDisposition([
            'atlas_impact' => ['touches research docs'],
            'conflicts' => [['id' => 'c1', 'resolved' => true]],
            'uncertainties' => [],
            'recommended_action' => 'promote_to_doc',
            'quality_publishable' => true,
        ]);
        $this->assertSame('promote', $promoted['disposition']);
        $this->assertTrue($promoted['may_change_atlas']);
        $this->assertSame(0, $promoted['open_conflicts']);
    }

    /**
     * Research Packet validation against the doc's JSON contract: known
     * schema_version + required non-empty fields + recommended_action in the
     * closed enum.
     */
    public function test_packet_validation_enforces_schema_and_enum(): void
    {
        $svc = $this->service();

        $valid = $svc->validatePacket([
            'schema_version' => AtlasResearchIntelligencePipelineService::PACKET_SCHEMA,
            'objective' => 'o',
            'question' => 'q',
            'recommended_action' => 'promote_to_doc',
            'created_at' => '2026-06-01T00:00:00Z',
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([], $valid['missing_fields']);

        // Out-of-enum recommended_action.
        $badEnum = $svc->validatePacket([
            'schema_version' => AtlasResearchIntelligencePipelineService::PACKET_SCHEMA,
            'objective' => 'o',
            'question' => 'q',
            'recommended_action' => 'delete_everything',
            'created_at' => '2026-06-01T00:00:00Z',
        ]);
        $this->assertFalse($badEnum['valid']);
        $this->assertContains('recommended_action_out_of_enum', $badEnum['enum_errors']);

        // Unknown schema_version.
        $unknown = $svc->validatePacket(['schema_version' => 'atlas.not_a_packet.v9']);
        $this->assertFalse($unknown['valid']);
        $this->assertSame('unknown_schema_version', $unknown['reason']);
    }

    /**
     * End-to-end run folds the quality verdict into the disposition: a fully
     * advanced pipeline with a balanced mix and clean quality but ONE open
     * conflict must terminate as research_more, not promote.
     */
    public function test_run_pipeline_folds_quality_and_preserves_conflict(): void
    {
        $svc = $this->service();

        $result = $svc->runPipeline(
            AtlasResearchIntelligencePipelineService::STEPS,
            ['repo', 'official_doc', 'community'],
            ['specific', 'source_backed', 'conflict_aware', 'time_aware', 'actionable', 'bounded'],
            [
                'schema_version' => AtlasResearchIntelligencePipelineService::PACKET_SCHEMA,
                'objective' => 'demo',
                'question' => 'demo',
                'atlas_impact' => ['touches research docs'],
                'conflicts' => [['id' => 'c1', 'resolved' => false]],
                'uncertainties' => [],
                'recommended_action' => 'promote_to_doc',
                'created_at' => '2026-06-01T00:00:00Z',
            ],
        );

        $this->assertTrue($result['advance']['complete']);
        $this->assertTrue($result['discovery_mix']['balanced']);
        $this->assertTrue($result['output_quality']['publishable']);
        // Despite clean quality, the open conflict blocks promotion.
        $this->assertSame('research_more', $result['decision']['disposition']);
        $this->assertFalse($result['decision']['may_change_atlas']);
    }
}
