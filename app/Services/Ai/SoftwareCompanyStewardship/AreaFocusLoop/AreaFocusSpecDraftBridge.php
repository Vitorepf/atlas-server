<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedSpecProposalAdapter;
use InvalidArgumentException;

/**
 * Area Focus Loop · Spec Draft Bridge (AP-718).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Turns ONE Area Focus Loop finding (`atlas.software_company_stewardship`
 * `.area_finding.v1`) into a proposal-only spec draft, by:
 *   1. mapping the finding onto the existing `atlas.evolution.gap_candidate.v1`
 *      shape (`candidate_hash = finding_hash`);
 *   2. reusing `SelfDirectedSpecProposalAdapter::draft()` to produce the draft.
 *
 * It is the single source of the finding -> candidate mapping shared with
 * `AreaFocusInboxService`. It creates NO parallel proposal registry, persists
 * nothing, writes no canonical doc/AP/owner service, invokes no provider, never
 * auto-approves and never auto-implements. A finding without a `finding_hash`
 * is blocked.
 */
class AreaFocusSpecDraftBridge
{
    public const DRAFT_SCHEMA = 'atlas.software_company_stewardship.area_focus_spec_draft.v1';

    /** Surfacing owner marker (the operator decides actual routing via route_hint). */
    public const SOURCE_OWNER = 'area_focus_loop';

    /** Small, local, verifiable code work routes to Atlas Dev, not to a spec. */
    public const ROUTE_ATLAS_DEV = 'atlas_dev';

    /** @var array<string,int> */
    private const RISK_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        'unknown' => 0,
    ];

    public function __construct(
        private readonly SelfDirectedSpecProposalAdapter $specAdapter,
    ) {}

    /**
     * Map an Area Focus finding onto the canonical gap-candidate shape that the
     * reused Self-Directed Evolution owners consume. Shared by the inbox.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException when the finding has no finding_hash.
     */
    public function findingToCandidate(array $finding): array
    {
        $hash = trim((string) ($finding['finding_hash'] ?? ''));
        if ($hash === '') {
            throw new InvalidArgumentException('finding_hash is required to bridge an Area Focus finding to a spec draft.');
        }

        $risk = AreaFocusScalarNormalizer::riskLevelOrMedium($finding['risk_level'] ?? ($finding['severity'] ?? null));
        $findingType = (string) ($finding['finding_type'] ?? ($finding['source'] ?? 'area_focus_finding'));
        $areaId = (string) ($finding['area_id'] ?? '');
        $rationale = (string) ($finding['detail'] ?? ($finding['recommended_action'] ?? ''));

        return [
            'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
            'candidate_hash' => $hash,
            'candidate_id' => (string) ($finding['finding_id'] ?? ('gapc_'.substr(hash('sha256', $hash), 0, 16))),
            'source_owner' => self::SOURCE_OWNER,
            'source_schema_version' => (string) ($finding['schema_version'] ?? 'atlas.software_company_stewardship.area_finding.v1'),
            'gap_kind' => $findingType,
            'title' => (string) ($finding['title'] ?? 'Area focus finding'),
            'rationale' => $rationale !== '' ? $rationale : 'Area Focus Loop surfaced a finding for operator review.',
            'capability' => $areaId !== '' ? $areaId : null,
            'risk_level' => $risk,
            'priority_score' => (int) ($finding['priority_score'] ?? ((self::RISK_RANK[$risk] ?? 0) * 100)),
            'evidence_refs' => array_values(array_filter(
                (array) ($finding['evidence_refs'] ?? []),
                static fn ($r): bool => is_string($r) && $r !== '',
            )),
            'owner_doc_refs' => [],
            'proposed_next_action' => (string) ($finding['recommended_action'] ?? 'Operator review required.'),
            'duplicate_authority_guard' => [
                'parallel_authority_created' => false,
                'parallel_proposal_registry_created' => false,
                'read_only' => true,
                'reused_owners' => [
                    SelfDirectedSpecProposalAdapter::class,
                ],
            ],
        ];
    }

    /**
     * A finding is spec-draftable unless it is small, local code work routed to
     * Atlas Dev. Advisory flag; drafting any valid finding is still allowed.
     *
     * @param  array<string,mixed>  $finding
     */
    public function isSpecDraftable(array $finding): bool
    {
        if (trim((string) ($finding['finding_hash'] ?? '')) === '') {
            return false;
        }

        return (string) ($finding['route_hint'] ?? '') !== self::ROUTE_ATLAS_DEV;
    }

    /**
     * Produce a proposal-only spec draft from a single finding by reusing the
     * Self-Directed Evolution Spec Proposal Adapter.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException when the finding is invalid (no finding_hash).
     */
    public function draftFromFinding(array $finding): array
    {
        $candidate = $this->findingToCandidate($finding);
        $specDraft = $this->specAdapter->draft($candidate);

        $payload = [
            'schema_version' => self::DRAFT_SCHEMA,
            'ap_contract' => 'AP-718',
            'area_id' => (string) ($finding['area_id'] ?? ''),
            'finding_hash' => $candidate['candidate_hash'],
            'finding_id' => $candidate['candidate_id'],
            'finding_type' => $candidate['gap_kind'],
            'route_hint' => (string) ($finding['route_hint'] ?? ''),
            'spec_draftable' => $this->isSpecDraftable($finding),
            'reused_owner' => SelfDirectedSpecProposalAdapter::class,
            'reused_owner_schema' => SelfDirectedSpecProposalAdapter::DRAFT_SCHEMA,
            // Deterministic reference to the reused draft (its own draft_hash
            // excludes the volatile generated_at), so bridge_hash stays stable.
            'spec_proposal_draft_hash' => (string) ($specDraft['draft_hash'] ?? ''),
            // Hard guarantees mirrored to the top level for easy enforcement.
            'operator_approval_required' => true,
            'canonical_doc_write_allowed' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'provider_invoked' => false,
            'parallel_proposal_registry_created' => false,
            'written' => false,
        ];
        $payload['bridge_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        // Attach the full reused draft AFTER hashing (it carries a volatile
        // generated_at), keeping the proposal-only payload available.
        $payload['spec_proposal_draft'] = $specDraft;
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }
}
