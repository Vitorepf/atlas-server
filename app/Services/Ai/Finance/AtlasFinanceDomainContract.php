<?php

namespace App\Services\Ai\Finance;

class AtlasFinanceDomainContract
{
    public const DOMAIN_ID = 'finance';

    public const ORCHESTRATOR_ID = 'AtlasFinanceOrchestrator';

    public const RUNTIME_ID = 'AtlasFinanceRuntime';

    public const OUTPUT_MODE = 'analysis_review_only';

    public const AUTONOMY = 'low';

    public const FLOW_MARKET_RESEARCH = 'finance.market_research';

    public const FLOW_RISK_REVIEW = 'finance.risk_review';

    public const FLOW_PORTFOLIO_ANALYSIS = 'finance.portfolio_analysis';

    public const FLOW_TRADE_THESIS = 'finance.trade_thesis';

    public const FLOW_MACRO_REVIEW = 'finance.macro_review';

    public const FLOW_EARNINGS_REVIEW = 'finance.earnings_review';

    public const FLOW_NEWS_IMPACT = 'finance.news_impact';

    public const FLOW_COMPLIANCE_REVIEW = 'finance.compliance_review';

    public const FLOW_BACKTEST_PLAN = 'finance.backtest_plan';

    public const FLOW_FORGE = 'finance.forge';

    /**
     * @var array<int,string>
     */
    public const FLOWS = [
        self::FLOW_MARKET_RESEARCH,
        self::FLOW_RISK_REVIEW,
        self::FLOW_PORTFOLIO_ANALYSIS,
        self::FLOW_TRADE_THESIS,
        self::FLOW_MACRO_REVIEW,
        self::FLOW_EARNINGS_REVIEW,
        self::FLOW_NEWS_IMPACT,
        self::FLOW_COMPLIANCE_REVIEW,
        self::FLOW_BACKTEST_PLAN,
        self::FLOW_FORGE,
    ];

    /**
     * @var array<int,string>
     */
    public const GLOBAL_GATES = [
        'finance_compliance_review',
        'source_attribution',
        'risk_disclosure',
        self::OUTPUT_MODE,
    ];

    /**
     * @var array<int,string>
     */
    public const FORBIDDEN_MARKET_ACTIONS = [
        'place_order',
        'modify_order',
        'cancel_order',
        'rebalance_account',
        'transfer_cash',
        'exercise_option',
        'connect_broker_for_execution',
        'publish_investment_advice_as_personal_recommendation',
    ];

    /**
     * @return array<string,array<string,mixed>>
     */
    public function flowDefinitions(): array
    {
        return [
            self::FLOW_MARKET_RESEARCH => $this->flow('Market Research', 'FinanceResearchRuntime', ['market_data_snapshot', 'source_pack', 'assumption_log']),
            self::FLOW_RISK_REVIEW => $this->flow('Risk Review', 'FinanceRiskRuntime', ['risk_factors', 'exposure_snapshot', 'scenario_matrix'], ['risk_limits']),
            self::FLOW_PORTFOLIO_ANALYSIS => $this->flow('Portfolio Analysis', 'FinancePortfolioRuntime', ['holdings_snapshot', 'allocation_summary', 'concentration_review'], ['portfolio_suitability_review']),
            self::FLOW_TRADE_THESIS => $this->flow('Trade Thesis', 'FinanceThesisRuntime', ['instrument_context', 'variant_thesis', 'invalidating_conditions'], ['thesis_countercase']),
            self::FLOW_MACRO_REVIEW => $this->flow('Macro Review', 'FinanceMacroRuntime', ['macro_indicators', 'policy_calendar', 'scenario_matrix']),
            self::FLOW_EARNINGS_REVIEW => $this->flow('Earnings Review', 'FinanceEarningsRuntime', ['earnings_materials', 'consensus_snapshot', 'guidance_delta'], ['issuer_specific_risk']),
            self::FLOW_NEWS_IMPACT => $this->flow('News Impact', 'FinanceNewsRuntime', ['news_source_pack', 'impact_window', 'uncertainty_notes'], ['news_source_quality']),
            self::FLOW_COMPLIANCE_REVIEW => $this->flow('Compliance Review', 'FinanceComplianceRuntime', ['jurisdiction', 'policy_constraints', 'restricted_action_scan'], ['regulatory_scope']),
            self::FLOW_BACKTEST_PLAN => $this->flow('Backtest Plan', 'FinanceBacktestRuntime', ['hypothesis', 'dataset_requirements', 'bias_controls'], ['methodology_review']),
            self::FLOW_FORGE => $this->flow('Forge', 'FinanceForgeRuntime', ['multi_flow_plan', 'review_packet', 'approval_receipt'], ['operator_approval', 'forge_scope_review'], true),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function contextSources(): array
    {
        return [
            'operator_supplied_financial_context',
            'market_data_snapshot',
            'issuer_filings',
            'portfolio_snapshot',
            'risk_policy',
            'compliance_policy',
            'macroeconomic_calendar',
            'news_source_pack',
            'backtest_dataset_manifest',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function memoryPolicy(): array
    {
        return [
            'projection' => self::DOMAIN_ID,
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_research_note', 'reviewed_risk_finding', 'validated_backtest_method'],
                'requires_review_for' => ['portfolio_preference', 'risk_limit', 'compliance_rule', 'instrument_watchlist'],
                'never_store' => ['broker_credentials', 'account_secrets', 'unredacted_personal_financial_data'],
                'never_auto_execute' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function toolPolicy(): array
    {
        return [
            'mode' => 'read_only',
            'market_data_read' => true,
            'broker_api_access' => false,
            'order_entry' => false,
            'external_publish' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainGatePolicy(): array
    {
        return [
            'required' => self::GLOBAL_GATES,
            'release_requires' => ['operator_approval', 'compliance_review'],
            'autonomy_ceiling' => self::OUTPUT_MODE,
            'market_execution_allowed' => false,
        ];
    }

    /**
     * @param  array<int,string>  $requiredEvidence
     * @param  array<int,string>  $extraGates
     * @return array<string,mixed>
     */
    private function flow(string $label, string $runtime, array $requiredEvidence, array $extraGates = [], bool $requiresApproval = false): array
    {
        return [
            'label' => $label,
            'runtime' => $runtime,
            'required_evidence' => $requiredEvidence,
            'required_gates' => array_values(array_unique([...self::GLOBAL_GATES, ...$extraGates])),
            'requires_human_approval' => $requiresApproval,
        ];
    }
}
