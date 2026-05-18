<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;

class MarketingDomainManifestSeeder
{
    public function __construct(private readonly DomainManifestRegistryService $registry) {}

    public function seed(): AiDomainManifest
    {
        $existing = $this->registry->findByDomainId(MarketingDomainCanon::DOMAIN_ID);
        if ($existing instanceof AiDomainManifest) {
            return $existing;
        }
        $payload = $this->marketingManifestPayload();

        return $this->registry->register($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingManifestPayload(): array
    {
        foreach (DomainSeedManifests::all() as $candidate) {
            if (($candidate['domain_id'] ?? null) === MarketingDomainCanon::DOMAIN_ID) {
                return $candidate;
            }
        }

        return [
            'domain_id' => MarketingDomainCanon::DOMAIN_ID,
            'name' => 'Marketing / Growth Company Runtime',
            'status' => 'active',
            'maturity_stage' => 2,
            'owner' => 'atlas-marketing',
            'charter' => [
                'mission' => 'Positioning, ICP, campaign planning, copy/creative, funnel and experiments; no publishing or paid spend without approval.',
                'outcomes' => ['positioning_doc', 'campaign_plan', 'copy_pack', 'experiment_brief'],
                'forbidden' => MarketingDomainCanon::FORBIDDEN_ACTIONS,
            ],
            'ontology' => ['icp', 'positioning', 'campaign', 'copy', 'creative', 'funnel', 'analytics_plan', 'experiment'],
            'departments' => ['positioning', 'campaign', 'copy', 'creative', 'funnel', 'analytics', 'growth_lab'],
            'flow_profiles' => ['positioning_draft', 'campaign_plan', 'copy_iteration', 'experiment_brief'],
            'tools_allowed' => ['web.search', 'doc.write'],
            'evidence_schema' => ['source_ref', 'doc', 'artifact'],
            'quality_gates' => ['icp_present', 'message_clear', 'no_unsubstantiated_claim', 'experiment_has_hypothesis_and_decision'],
            'handoff_rules' => ['allowed' => ['strategy', 'research', 'finance'], 'forbidden' => []],
            'delivery_types' => ['positioning_doc', 'campaign_plan', 'experiment_brief'],
            'metrics' => ['message_clarity', 'asset_throughput', 'experiment_throughput'],
            'forbidden_actions' => MarketingDomainCanon::FORBIDDEN_ACTIONS,
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'medium'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['positioning', 'campaigns', 'experiments']],
            'capabilities' => [
                [
                    'capability_id' => 'marketing.positioning_draft',
                    'name' => 'Draft positioning',
                    'description' => 'Draft positioning with ICP, message and proof points.',
                    'input_schema' => ['type' => 'object', 'required' => ['product']],
                    'output_schema' => ['type' => 'object', 'required' => ['positioning']],
                    'allowed_tools' => ['doc.write'],
                    'risk_level' => 'low',
                    'required_gates' => ['icp_present'],
                    'evidence_required' => ['doc'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }
}
