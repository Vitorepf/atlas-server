<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainCapabilityCatalogService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;

class FinanceDomainManifestSeeder
{
    public function __construct(
        private readonly DomainManifestRegistryService $registry,
        private readonly DomainCapabilityCatalogService $catalog,
    ) {}

    /**
     * Idempotent: if `finance` manifest already exists (e.g. seeded by Meta 2
     * DomainSeedManifests with a single research_note capability), extend it
     * with the full Company Runtime capability set without re-registering.
     */
    public function seed(): AiDomainManifest
    {
        $existing = $this->registry->findByDomainId(FinanceDomainCanon::DOMAIN_ID);
        if ($existing) {
            $this->ensureCompanyCapabilities($existing);

            return $existing->refresh();
        }

        return $this->registry->register([
            'domain_id' => FinanceDomainCanon::DOMAIN_ID,
            'name' => FinanceDomainCanon::DOMAIN_NAME,
            'status' => 'active',
            'maturity_stage' => DomainSeedManifests::STAGE_SPECIALIST,
            'owner' => 'atlas-finance',
            'charter' => $this->charter(),
            'ontology' => ['asset', 'portfolio', 'valuation_model', 'risk_metric', 'compliance_flag', 'paper_trade'],
            'departments' => ['research_desk', 'valuation', 'portfolio', 'risk', 'compliance', 'reporting', 'paper_trading_lab'],
            'flow_profiles' => [
                'finance.research_note',
                'finance.valuation_review',
                'finance.portfolio_review',
                'finance.risk_review',
                'finance.compliance_review',
                'finance.paper_trade_simulation',
                'finance.investment_brief',
            ],
            'tools_allowed' => FinanceDomainCanon::TOOLS_ALLOWED,
            'policy_profile' => [
                'autonomy' => 'suggest',
                'risk' => 'high',
                'requires_approval_for' => FinanceDomainCanon::FORBIDDEN_ACTIONS,
                'live_trading_allowed' => false,
            ],
            'memory_scope' => ['retain_days' => 1825, 'kinds' => ['notes', 'models', 'paper_trades', 'compliance_flags']],
            'evidence_schema' => FinanceDomainCanon::EVIDENCE_SCHEMA,
            'quality_gates' => FinanceDomainCanon::QUALITY_GATES,
            'handoff_rules' => FinanceDomainCanon::HANDOFF_RULES,
            'delivery_types' => FinanceDomainCanon::DELIVERY_TYPES,
            'metrics' => FinanceDomainCanon::METRICS,
            'forbidden_actions' => FinanceDomainCanon::FORBIDDEN_ACTIONS,
            'capabilities' => $this->capabilityDefinitions(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function charter(): array
    {
        return [
            'mission' => 'Research desk + valuation + portfolio review + risk + compliance + reporting + paper trading simulado para o operador. Live trading bloqueado por default.',
            'audience' => 'Operador, Atlas Strategy, Atlas Research, Atlas Operations.',
            'outcomes' => ['research_note', 'valuation_model', 'portfolio_view', 'risk_report', 'compliance_review', 'paper_trade_simulation_report', 'investment_brief'],
            'frontier' => 'Analise, simulacao e relatorio sob Mission Foundation, Domain Runtime, Policy, Evidence, Tool Runtime. NUNCA broker execution, transferencia, rebalanceamento ou ordem real.',
            'forbidden' => FinanceDomainCanon::FORBIDDEN_ACTIONS,
            'live_trading_allowed' => false,
            'live_trading_note' => 'Live trading is hard-blocked. Even with policy allow, FinanceRuntimeService::executeBroker* paths do not exist by design.',
        ];
    }

    private function ensureCompanyCapabilities(AiDomainManifest $manifest): void
    {
        foreach ($this->capabilityDefinitions() as $definition) {
            $capabilityId = $definition['capability_id'];
            $alreadyPresent = $manifest->capabilities()->where('capability_id', $capabilityId)->exists();
            if ($alreadyPresent) {
                continue;
            }
            $this->catalog->register($manifest, $definition);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function capabilityDefinitions(): array
    {
        $base = [
            'input_schema' => ['type' => 'object', 'required' => ['mission_id']],
            'output_schema' => ['type' => 'object', 'required' => ['outcome']],
        ];

        return [
            $base + [
                'capability_id' => 'finance.research_desk',
                'name' => 'Finance research desk',
                'description' => 'Produce research notes with primary source attribution and contradictions checked.',
                'allowed_tools' => ['docs.search', 'browser.readonly', 'api.readonly'],
                'risk_level' => 'low',
                'required_gates' => ['sources_attributed'],
                'evidence_required' => ['source_ref', 'doc'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'finance.valuation',
                'name' => 'Finance valuation review',
                'description' => 'DCF / multiples / scenario valuation with assumptions and risk disclosure. Review-only.',
                'allowed_tools' => ['docs.search', 'api.readonly', 'filesystem.read'],
                'risk_level' => 'medium',
                'required_gates' => ['assumptions_listed', 'risk_disclosed'],
                'evidence_required' => ['data_artifact', 'doc'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'finance.portfolio_review',
                'name' => 'Finance portfolio review',
                'description' => 'Review-only portfolio inspection: weights, concentration, exposures, drift vs target.',
                'allowed_tools' => ['filesystem.read', 'docs.search'],
                'risk_level' => 'medium',
                'required_gates' => ['assumptions_listed', 'risk_disclosed'],
                'evidence_required' => ['data_artifact', 'doc'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'finance.risk_review',
                'name' => 'Finance risk review',
                'description' => 'Volatility, drawdown, beta, VaR, factor exposure, scenario stress test.',
                'allowed_tools' => ['filesystem.read', 'docs.search', 'api.readonly'],
                'risk_level' => 'medium',
                'required_gates' => ['risk_disclosed', 'assumptions_listed'],
                'evidence_required' => ['data_artifact', 'doc'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'finance.compliance',
                'name' => 'Finance compliance review',
                'description' => 'Compliance scan: PEP, sanctions, suitability, KYC flags, conflicts of interest. Flags only.',
                'allowed_tools' => ['docs.search', 'api.readonly'],
                'risk_level' => 'high',
                'required_gates' => ['compliance_reviewed', 'risk_disclosed'],
                'evidence_required' => ['doc', 'source_ref'],
                'maturity_level' => DomainSeedManifests::STAGE_DEPARTMENT,
            ],
            $base + [
                'capability_id' => 'finance.reporting',
                'name' => 'Finance reporting',
                'description' => 'Aggregate research/valuation/portfolio/risk/compliance into a versioned investment brief.',
                'allowed_tools' => ['artifact.write_local', 'filesystem.read', 'docs.search'],
                'risk_level' => 'medium',
                'required_gates' => ['evidence_attached'],
                'evidence_required' => ['doc', 'artifact'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'finance.paper_trading_simulation',
                'name' => 'Finance paper trading simulation',
                'description' => 'Synthetic paper trades against a fixed price snapshot. NO broker connection. NO live order.',
                'allowed_tools' => ['filesystem.read', 'artifact.write_local'],
                'risk_level' => 'medium',
                'required_gates' => ['no_live_execution', 'assumptions_listed', 'risk_disclosed'],
                'evidence_required' => ['data_artifact', 'doc'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
        ];
    }
}
