<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;

/**
 * Binds Simplification CampaignControlPlane + OutcomeCreditGate to the
 * AAEOS+ACOS elite lane. Pure: tags campaigns, fail-closes unsafe consumers,
 * and refuses LOC/proxy credit.
 */
final class AtlasAaeosAcosSimplificationLane
{
    public const SCHEMA = 'atlas.aaeos_acos.simplification_lane.v1';

    public const CAMPAIGN_ID = 'aaeos_acos';

    public function __construct(
        private readonly AtlasSelfConstructionSimplificationCampaignControlPlane $campaign = new AtlasSelfConstructionSimplificationCampaignControlPlane,
        private readonly AtlasSelfConstructionSimplificationOutcomeCreditGate $creditGate = new AtlasSelfConstructionSimplificationOutcomeCreditGate,
        private readonly AtlasSelfConstructionSafeDeletionPlanner $deletionPlanner = new AtlasSelfConstructionSafeDeletionPlanner,
        private readonly AtlasSelfConstructionSimplificationConsumerImpactAnalyzer $consumerImpact = new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer,
    ) {}

    /**
     * @param  array<string,mixed>  $input  campaign input (candidates + required sections)
     * @return array<string,mixed>
     */
    public function planCampaign(array $input): array
    {
        $tagged = $input;
        $tagged['campaign_id'] = self::CAMPAIGN_ID;
        $tagged['lane_scope'] = AtlasAaeosAcosLaneScope::SLUG;
        $tagged['elite'] = true;

        $candidates = [];
        foreach ((array) ($input['candidates'] ?? []) as $candidate) {
            $candidate = (array) $candidate;
            $candidate['lane_scope'] = AtlasAaeosAcosLaneScope::SLUG;
            // Reject candidates whose targets escape AAEOS/ACOS roots.
            $targets = array_values(array_map('strval', (array) ($candidate['targets'] ?? $candidate['allowed_files'] ?? [])));
            if ($targets !== [] && AtlasAaeosAcosLaneScope::inferFromAllowedFiles($targets) !== AtlasAaeosAcosLaneScope::SLUG) {
                $candidate['risk'] = 'high';
                $candidate['action_type'] = $candidate['action_type'] ?? 'delete';
                $candidate['out_of_lane'] = true;
                $candidate['parity_matrix'] = ['parity_verified' => false];
                $candidate['rollback_receipts'] = ['present' => false];
                $candidate['replay_plan'] = ['ready' => false];
            }
            $candidates[] = $candidate;
        }
        $tagged['candidates'] = $candidates;

        $plan = $candidates === []
            ? $this->campaign->decide($tagged)
            : $this->campaign->planCampaign($tagged);

        return [
            'schema_version' => self::SCHEMA,
            'campaign_id' => self::CAMPAIGN_ID,
            'lane_scope' => AtlasAaeosAcosLaneScope::SLUG,
            'plan' => $plan,
            'elite_rules' => [
                'deletion_requires_safe_planner' => true,
                'unsafe_consumers_fail_closed' => true,
                'loc_only_credit_forbidden' => true,
            ],
        ];
    }

    /**
     * Elite deletion gate: SafeDeletionPlanner + consumer impact must both clear.
     *
     * @param  array<string,mixed>  $cluster
     * @param  array<string,mixed>  $impactInput
     * @return array<string,mixed>
     */
    public function evaluateDeletion(array $cluster, array $impactInput = []): array
    {
        $deletion = $this->deletionPlanner->plan($cluster);
        $impact = $impactInput !== []
            ? $this->consumerImpact->analyze($impactInput)
            : ['unsafe_consumers' => (array) ($cluster['unsafe_consumers'] ?? [])];

        $unsafe = array_values(array_filter(array_map('strval', (array) ($impact['unsafe_consumers'] ?? []))));
        $allowed = ($deletion['action'] ?? '') !== 'blocked' && $unsafe === [];

        return [
            'schema_version' => self::SCHEMA,
            'allowed' => $allowed,
            'deletion' => $deletion,
            'consumer_impact' => $impact,
            'reasons' => $allowed ? [] : array_values(array_filter([
                ($deletion['action'] ?? '') === 'blocked' ? 'deletion_planner_blocked' : null,
                $unsafe !== [] ? 'unsafe_consumers_present' : null,
            ])),
        ];
    }

    /**
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function creditOutcome(array $outcome): array
    {
        $outcome['lane_scope'] = AtlasAaeosAcosLaneScope::SLUG;

        return $this->creditGate->evaluateElite($outcome);
    }
}
