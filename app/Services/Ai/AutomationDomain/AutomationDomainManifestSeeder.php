<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;

/**
 * Seed the canonical Automation Company Runtime manifest into the
 * Domain Runtime registry (Meta 2). Idempotent: if the manifest already
 * exists, returns the existing record. Reuses the canonical payload from
 * `DomainSeedManifests::automation()` so Meta 2 and Meta 8/Automation agree
 * on charter, departments, capabilities and forbidden actions.
 */
class AutomationDomainManifestSeeder
{
    public function __construct(private readonly DomainManifestRegistryService $registry) {}

    public function seed(): AiDomainManifest
    {
        $existing = $this->registry->findByDomainId(AutomationDomainCanon::DOMAIN_ID);
        if ($existing instanceof AiDomainManifest) {
            return $existing;
        }

        return $this->registry->register($this->automationManifestPayload());
    }

    /**
     * @return array<string,mixed>
     */
    private function automationManifestPayload(): array
    {
        foreach (DomainSeedManifests::all() as $candidate) {
            if (($candidate['domain_id'] ?? null) === AutomationDomainCanon::DOMAIN_ID) {
                return $candidate;
            }
        }

        // Defensive fallback if Meta 2 drops `automation` from the canonical
        // list. Keep parity with `DomainSeedManifests::automation()` so the
        // seed never regresses the charter.
        return [
            'domain_id' => AutomationDomainCanon::DOMAIN_ID,
            'name' => 'Automation / Tool Factory',
            'status' => 'active',
            'maturity_stage' => DomainSeedManifests::STAGE_SPECIALIST,
            'owner' => 'atlas-automation',
            'charter' => [
                'mission' => 'Browser/terminal/GitHub/API automation, repo evaluation and tool builder/evolution.',
                'outcomes' => ['automation_pack', 'tool_blueprint', 'repo_evaluation'],
                'forbidden' => ['credential exfiltration', 'destructive ops without approval'],
            ],
            'ontology' => ['tool', 'automation_recipe', 'evaluation_report'],
            'departments' => ['browser', 'terminal', 'tool_builder', 'evaluator'],
            'flow_profiles' => ['automation_build', 'tool_evolution', 'repo_evaluation'],
            'tools_allowed' => ['browser.automate', 'shell.run', 'git.read', 'api.read'],
            'evidence_schema' => ['command', 'artifact', 'receipt', 'source_ref'],
            'quality_gates' => ['scope_authorized', 'evidence_attached', 'rollback_present'],
            'handoff_rules' => ['allowed' => ['software', 'operations', 'cyber'], 'forbidden' => []],
            'delivery_types' => ['automation_pack', 'tool_blueprint'],
            'metrics' => ['automation_success_rate', 'tool_lead_time'],
            'forbidden_actions' => ['credential exfiltration', 'destructive op without approval'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'high'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['tools', 'recipes']],
        ];
    }
}
