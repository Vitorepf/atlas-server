<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;

class ResearchDomainManifestSeeder
{
    public function __construct(private readonly DomainManifestRegistryService $registry) {}

    /**
     * Seed the canonical Research Company Runtime manifest into the Domain
     * Runtime registry (Meta 2). Idempotent: if the manifest already exists,
     * returns the existing record. Reuses the canonical payload from
     * DomainSeedManifests::research() so Meta 2 and Meta 8A agree.
     */
    public function seed(): AiDomainManifest
    {
        $existing = $this->registry->findByDomainId(ResearchDomainCanon::DOMAIN_ID);
        if ($existing instanceof AiDomainManifest) {
            return $existing;
        }
        $payload = $this->researchManifestPayload();

        return $this->registry->register($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function researchManifestPayload(): array
    {
        $candidates = DomainSeedManifests::all();
        foreach ($candidates as $candidate) {
            if (($candidate['domain_id'] ?? null) === ResearchDomainCanon::DOMAIN_ID) {
                return $candidate;
            }
        }

        // Defensive fallback if Meta 2 list ever drops research — keep a copy.
        return [
            'domain_id' => ResearchDomainCanon::DOMAIN_ID,
            'name' => 'Research Company Runtime',
            'status' => 'active',
            'maturity_stage' => 2,
            'owner' => 'atlas-research',
            'charter' => [
                'mission' => 'Pesquisa profunda com fontes primarias, contradiction check e sintese auditavel.',
                'outcomes' => ['research_brief', 'evidence_pack'],
                'forbidden' => ['claim sem source_ref', 'fonte unica para risco alto'],
            ],
            'ontology' => ['research_question', 'source_ref', 'claim', 'evidence_pack'],
            'departments' => ['discovery', 'verification', 'synthesis'],
            'flow_profiles' => ['research_brief', 'opportunity_scan', 'contradiction_check'],
            'tools_allowed' => ['web.search', 'web.fetch', 'pdf.parse', 'doc.summarize'],
            'evidence_schema' => ['source_ref', 'doc', 'screenshot'],
            'quality_gates' => ['sources_diverse', 'contradictions_resolved_or_declared', 'claims_attributed'],
            'handoff_rules' => ['allowed' => ['software', 'strategy', 'finance', 'marketing'], 'forbidden' => []],
            'delivery_types' => ['research_brief', 'opportunity_radar'],
            'metrics' => ['source_diversity', 'claim_attribution_rate'],
            'forbidden_actions' => ['inference sem citation'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'low'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['sources', 'briefs']],
            'capabilities' => [
                [
                    'capability_id' => 'research.brief',
                    'name' => 'Produce research brief',
                    'description' => 'Produce a research brief from primary sources with attribution.',
                    'input_schema' => ['type' => 'object', 'required' => ['question']],
                    'output_schema' => ['type' => 'object', 'required' => ['brief', 'sources']],
                    'allowed_tools' => ['web.search', 'web.fetch'],
                    'risk_level' => 'low',
                    'required_gates' => ['sources_diverse', 'claims_attributed'],
                    'evidence_required' => ['source_ref'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }
}
