<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedSpecProposalAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSpecDraftBridge;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AP-718 · Spec Draft Bridge contract tests.
 *
 * The bridge maps an Area Focus finding onto the canonical gap-candidate shape
 * and REUSES SelfDirectedSpecProposalAdapter — it must stay proposal-only and
 * block any finding with no finding_hash.
 */
class AreaFocusSpecDraftBridgeTest extends TestCase
{
    private function bridge(): AreaFocusSpecDraftBridge
    {
        return app(AreaFocusSpecDraftBridge::class);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function finding(array $over = []): array
    {
        $raw = hash('sha256', 'agentic_engineering_os|self_directed_spec_gap|svc');

        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_finding.v1',
            'area_id' => 'agentic_engineering_os',
            'finding_type' => 'self_directed_spec_gap',
            'title' => 'Uncontracted spec gap in app/Services/Foo.php',
            'detail' => 'Building status without an accepted AP.',
            'severity' => 'medium',
            'risk_level' => 'medium',
            'confidence' => 'medium',
            'route_hint' => 'self_directed_evolution',
            'evidence_refs' => ['status:building', 'has_ap:false'],
            'recommended_action' => 'Draft an AP before implementation.',
            'finding_id' => 'aef_'.substr($raw, 0, 16),
            'finding_hash' => 'sha256:'.$raw,
            'priority_score' => 206,
        ], $over);
    }

    public function test_draft_from_finding_is_proposal_only_and_reuses_adapter(): void
    {
        $draft = $this->bridge()->draftFromFinding($this->finding());

        $this->assertSame(AreaFocusSpecDraftBridge::DRAFT_SCHEMA, $draft['schema_version']);
        $this->assertSame('AP-718', $draft['ap_contract']);
        $this->assertStringStartsWith('sha256:', (string) $draft['finding_hash']);

        // Reuses the canonical Spec Proposal Adapter payload.
        $this->assertArrayHasKey('spec_proposal_draft', $draft);
        $this->assertSame(
            SelfDirectedSpecProposalAdapter::DRAFT_SCHEMA,
            $draft['spec_proposal_draft']['schema_version']
        );
        $this->assertSame(SelfDirectedSpecProposalAdapter::class, $draft['reused_owner']);

        // Hard proposal-only guarantees, both at top level and in the reused draft.
        $this->assertTrue($draft['operator_approval_required']);
        $this->assertFalse($draft['canonical_doc_write_allowed']);
        $this->assertFalse($draft['autoapproval_allowed']);
        $this->assertFalse($draft['autoimplementation_allowed']);
        $this->assertFalse($draft['provider_invoked']);
        $this->assertFalse($draft['written']);
        $this->assertFalse($draft['spec_proposal_draft']['canonical_doc_write_allowed']);
        $this->assertFalse($draft['spec_proposal_draft']['autoapproval_allowed']);
    }

    public function test_invalid_finding_without_hash_is_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->bridge()->draftFromFinding(['title' => 'no finding_hash here']);
    }

    public function test_finding_to_candidate_maps_canonical_shape(): void
    {
        $candidate = $this->bridge()->findingToCandidate($this->finding());

        $this->assertSame(SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA, $candidate['schema_version']);
        $this->assertSame('sha256:'.hash('sha256', 'agentic_engineering_os|self_directed_spec_gap|svc'), $candidate['candidate_hash']);
        $this->assertSame(AreaFocusSpecDraftBridge::SOURCE_OWNER, $candidate['source_owner']);
        $this->assertSame('self_directed_spec_gap', $candidate['gap_kind']);
        $this->assertSame('medium', $candidate['risk_level']);
        $this->assertFalse($candidate['duplicate_authority_guard']['parallel_proposal_registry_created']);
    }

    public function test_is_spec_draftable_follows_route_hint(): void
    {
        $this->assertTrue($this->bridge()->isSpecDraftable($this->finding(['route_hint' => 'self_directed_evolution'])));
        $this->assertTrue($this->bridge()->isSpecDraftable($this->finding(['route_hint' => 'forge'])));
        $this->assertFalse($this->bridge()->isSpecDraftable($this->finding(['route_hint' => 'atlas_dev'])));
        $this->assertFalse($this->bridge()->isSpecDraftable(['title' => 'no hash']));
    }

    public function test_bridge_hash_is_deterministic(): void
    {
        $finding = $this->finding();
        $first = $this->bridge()->draftFromFinding($finding);
        $second = $this->bridge()->draftFromFinding($finding);

        $this->assertSame($first['bridge_hash'], $second['bridge_hash']);
    }
}
