<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Services\Ai\Mission\MissionCanonicalHash;

class FinanceEnterpriseAnalysisService
{
    public const SCHEMA = 'atlas.ai.finance.enterprise_analysis_solution.v1';

    /**
     * Build the institutional finance operating packet inspired by enterprise
     * financial analysis platforms: source-linked data, governed connectors,
     * audit trails, compliance automation, portfolio monitoring and no market
     * execution authority.
     *
     * @return array<string,mixed>
     */
    public function packet(string $asset = 'AAPL'): array
    {
        $asset = strtoupper(trim($asset) !== '' ? trim($asset) : 'AAPL');

        $packet = [
            'schema' => self::SCHEMA,
            'domain_id' => FinanceDomainCanon::DOMAIN_ID,
            'asset' => $asset,
            'mode' => 'institutional_review_only',
            'source_inspiration' => [
                'vendor' => 'Anthropic Claude for Financial Services',
                'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'adopted_patterns' => [
                    'unified_financial_data_interface',
                    'source_linked_claim_verification',
                    'financial_modeling_audit_trail',
                    'portfolio_monitoring',
                    'compliance_automation',
                    'data_room_due_diligence',
                ],
            ],
            'connectors' => $this->connectors(),
            'flows' => $this->flows($asset),
            'agent_desk' => $this->agentDesk(),
            'risk_and_compliance' => $this->riskAndCompliance(),
            'work_products' => $this->workProducts($asset),
            'metrics' => $this->metrics(),
            'operating_cadences' => $this->operatingCadences(),
            'invariants' => [
                'live_trading_blocked_default' => FinanceDomainCanon::liveTradingBlocked(),
                'broker_execution_allowed' => false,
                'auto_rebalance_allowed' => false,
                'external_money_movement_allowed' => false,
                'operator_review_required_before_action' => true,
                'every_claim_requires_source_link' => true,
                'every_model_requires_audit_trail' => true,
            ],
        ];
        $packet['readiness'] = $this->readiness($packet);
        $packet['receipt_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function connectors(): array
    {
        return [
            $this->connector('market_data', 'read_adapter', ['prices', 'fundamentals', 'consensus_estimates'], 'FactSet / S&P Capital IQ / Morningstar class'),
            $this->connector('filings_and_kpis', 'read_adapter', ['public_filings', 'disclosures', 'company_kpis'], 'Daloopa / SEC filings class'),
            $this->connector('private_market_intelligence', 'read_adapter', ['private_company_profiles', 'fundraising', 'comparables'], 'PitchBook class'),
            $this->connector('enterprise_lakehouse', 'governed_internal_adapter', ['structured_data', 'semi_structured_data', 'model_outputs'], 'Snowflake / Databricks class'),
            $this->connector('data_room_documents', 'secure_document_adapter', ['vdr_docs', 'contracts', 'board_materials'], 'Box / virtual data room class'),
            $this->connector('compliance_obligations', 'governance_adapter', ['policy_obligations', 'regulatory_mapping', 'approval_gates'], 'Regulatory Pathfinder class'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function connector(string $id, string $kind, array $dataTypes, string $referenceClass): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'reference_class' => $referenceClass,
            'data_types' => $dataTypes,
            'side_effect_profile' => 'read_only_or_governed_internal',
            'external_side_effects' => false,
            'source_links_required' => true,
            'contract_hash' => hash('sha256', $id.'|'.$kind.'|'.implode(',', $dataTypes)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function flows(string $asset): array
    {
        return [
            $this->flow('market_research_brief', 'senior_research_analyst', ['market_data', 'filings_and_kpis'], "{$asset} research brief with cited filings and market data"),
            $this->flow('financial_model_audit', 'modeling_analyst', ['filings_and_kpis', 'enterprise_lakehouse'], "{$asset} valuation model with assumptions, sensitivity table and audit trail"),
            $this->flow('portfolio_deep_dive', 'portfolio_manager', ['market_data', 'private_market_intelligence'], "{$asset} exposure, concentration, drift and scenario impact review"),
            $this->flow('data_room_due_diligence', 'diligence_lead', ['data_room_documents', 'private_market_intelligence'], "{$asset} VDR checklist, red flags and source-linked diligence memo"),
            $this->flow('compliance_obligation_mapping', 'compliance_officer', ['compliance_obligations', 'data_room_documents'], "{$asset} compliance gap analysis and approval packet"),
            $this->flow('investment_committee_memo', 'investment_committee_chief_of_staff', ['market_data', 'filings_and_kpis', 'compliance_obligations'], "{$asset} IC memo with decision options and blocked execution actions"),
        ];
    }

    /**
     * @param list<string> $connectors
     * @return array<string,mixed>
     */
    private function flow(string $id, string $agent, array $connectors, string $output): array
    {
        return [
            'id' => $id,
            'agent_role' => $agent,
            'connectors' => $connectors,
            'output' => $output,
            'required_gates' => [
                'source_links_verified',
                'assumptions_listed',
                'risk_disclosed',
                'compliance_reviewed',
                'no_live_execution',
            ],
            'audit_trail_required' => true,
            'external_side_effects' => false,
            'flow_hash' => hash('sha256', $id.'|'.$agent.'|'.implode(',', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function agentDesk(): array
    {
        return [
            ['role' => 'senior_research_analyst', 'owns' => ['market_research_brief', 'claim_verification']],
            ['role' => 'modeling_analyst', 'owns' => ['financial_model_audit', 'sensitivity_analysis']],
            ['role' => 'portfolio_manager', 'owns' => ['portfolio_deep_dive', 'scenario_monitoring']],
            ['role' => 'diligence_lead', 'owns' => ['data_room_due_diligence', 'red_flag_register']],
            ['role' => 'compliance_officer', 'owns' => ['compliance_obligation_mapping', 'approval_packet']],
            ['role' => 'investment_committee_chief_of_staff', 'owns' => ['investment_committee_memo', 'decision_log']],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function riskAndCompliance(): array
    {
        return [
            'forbidden_actions' => FinanceDomainCanon::FORBIDDEN_ACTIONS,
            'kill_switch_keywords' => FinanceDomainCanon::LIVE_TRADE_KEYWORDS,
            'approval_required_for' => [
                'portfolio_change_recommendation',
                'capital_allocation',
                'broker_connection',
                'client_or_third_party_distribution',
            ],
            'review_packets' => [
                'investment_committee_review',
                'compliance_gap_review',
                'model_risk_review',
                'source_quality_review',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function workProducts(string $asset): array
    {
        return [
            "{$asset}.source_linked_research_brief",
            "{$asset}.audited_valuation_model",
            "{$asset}.portfolio_monitoring_pack",
            "{$asset}.data_room_due_diligence_memo",
            "{$asset}.compliance_obligation_map",
            "{$asset}.investment_committee_memo",
        ];
    }

    /**
     * @return list<string>
     */
    private function metrics(): array
    {
        return [
            'source_link_coverage',
            'model_assumption_coverage',
            'portfolio_drift_signal_count',
            'compliance_gap_count',
            'data_room_red_flag_count',
            'live_execution_block_count',
            'investment_committee_packet_completeness',
        ];
    }

    /**
     * @return list<string>
     */
    private function operatingCadences(): array
    {
        return [
            'daily_market_event_monitor',
            'weekly_portfolio_deep_dive',
            'monthly_model_and_compliance_review',
            'quarterly_investment_committee_pack',
        ];
    }

    /**
     * @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    private function readiness(array $packet): array
    {
        $connectorCount = count((array) $packet['connectors']);
        $flowCount = count((array) $packet['flows']);
        $agentCount = count((array) $packet['agent_desk']);
        $workProductCount = count((array) $packet['work_products']);
        $metricCount = count((array) $packet['metrics']);

        return [
            'ok' => $connectorCount >= 6
                && $flowCount >= 6
                && $agentCount >= 6
                && $workProductCount >= 6
                && $metricCount >= 7
                && ($packet['invariants']['live_trading_blocked_default'] ?? false) === true,
            'connector_count' => $connectorCount,
            'flow_count' => $flowCount,
            'agent_count' => $agentCount,
            'work_product_count' => $workProductCount,
            'metric_count' => $metricCount,
            'source_linked' => true,
            'audit_trail_required' => true,
        ];
    }
}
