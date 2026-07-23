<?php

namespace App\Services\Ai\Holding\EnterpriseFlowFixture;

use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasEnvelope;

/**
 * *RuntimeStatus read-models extracted from EnterpriseFlowFixtureActionRuntimeService
 * (GOD-DEBULK split). Bodies verbatim; facade-owned + cross-reader calls
 * (buildoutReport/run/clearRuntimeRecordCache/runtimeStatusFor and the 4 cross-called
 * readers) route through $this->svc.
 */
class EnterpriseFlowFixtureBusinessStatusReaders
{
    public function __construct(
        private readonly EnterpriseFlowFixtureActionRuntimeService $svc,
        private readonly EnterpriseFlowFixtureRuntimeRecords $records,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function workforceCapacityRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_workforce_capacity_runtime_status.v1',
            'status_complete' => 'complete_workforce_capacity_runtime_coverage_external_staffing_changes_blocked',
            'status_missing' => 'missing_workforce_capacity_runtime_coverage',
            'hash_key' => 'workforce_capacity_runtime_status_hash',
            'completed_key' => 'completed_workforce_capacity_flow_count',
            'missing_key' => 'missing_workforce_capacity_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'workforce_capacity_runtime_bound',
                'workforce_stack_bound',
                'workforce_org_model_bound',
                'workforce_agent_capacity_plan_bound',
                'workforce_flow_staffing_bound',
                'workforce_training_enablement_bound',
                'workforce_succession_continuity_bound',
                'workforce_capacity_observability_bound',
                'workforce_capacity_external_changes_blocked',
                ['hash', 'workforce_capacity_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'workforce_stack_bound_count' => 'workforce_stack_bound',
                'org_model_bound_count' => 'workforce_org_model_bound',
                'agent_capacity_plan_bound_count' => 'workforce_agent_capacity_plan_bound',
                'flow_staffing_bound_count' => 'workforce_flow_staffing_bound',
                'training_enablement_bound_count' => 'workforce_training_enablement_bound',
                'succession_continuity_bound_count' => 'workforce_succession_continuity_bound',
                'capacity_observability_bound_count' => 'workforce_capacity_observability_bound',
                'external_changes_blocked_count' => 'workforce_capacity_external_changes_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'single_agent_bottleneck_allowed' => false,
                'external_unreviewed_staffing_change_allowed' => false,
                'workforce_runtime_requires_capacity_plan_flow_staffing_training_succession_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function portfolioDependencyRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_portfolio_dependency_runtime_status.v1',
            'status_complete' => 'complete_portfolio_dependency_runtime_coverage_external_dependency_actions_blocked',
            'status_missing' => 'missing_portfolio_dependency_runtime_coverage',
            'hash_key' => 'portfolio_dependency_runtime_status_hash',
            'completed_key' => 'completed_portfolio_dependency_flow_count',
            'missing_key' => 'missing_portfolio_dependency_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'portfolio_dependency_runtime_bound',
                'portfolio_dependency_stack_bound',
                'portfolio_dependency_role_bound',
                'portfolio_dependency_intake_contract_bound',
                'portfolio_dependency_upstream_map_bound',
                'portfolio_dependency_integration_map_bound',
                'portfolio_dependency_flow_routing_bound',
                'portfolio_dependency_escalation_conflict_bound',
                'portfolio_dependency_reporting_bound',
                'portfolio_dependency_external_actions_blocked',
                ['hash', 'portfolio_dependency_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'dependency_stack_bound_count' => 'portfolio_dependency_stack_bound',
                'role_bound_count' => 'portfolio_dependency_role_bound',
                'intake_contract_bound_count' => 'portfolio_dependency_intake_contract_bound',
                'upstream_map_bound_count' => 'portfolio_dependency_upstream_map_bound',
                'integration_map_bound_count' => 'portfolio_dependency_integration_map_bound',
                'flow_routing_bound_count' => 'portfolio_dependency_flow_routing_bound',
                'escalation_conflict_bound_count' => 'portfolio_dependency_escalation_conflict_bound',
                'reporting_bound_count' => 'portfolio_dependency_reporting_bound',
                'external_actions_blocked_count' => 'portfolio_dependency_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'external_spend_publish_write_trade_or_transfer_allowed' => false,
                'cross_company_dependency_without_typed_handoff_allowed' => false,
                'unresolved_conflict_external_side_effect_allowed' => false,
                'portfolio_dependency_runtime_requires_role_intake_maps_flow_routing_escalation_and_reporting' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDossierRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_operational_dossier_runtime_status.v1',
            'status_complete' => 'complete_operational_dossier_runtime_coverage_external_blocked',
            'status_missing' => 'missing_operational_dossier_runtime_coverage',
            'hash_key' => 'operational_dossier_runtime_status_hash',
            'completed_key' => 'completed_operational_dossier_flow_count',
            'missing_key' => 'missing_operational_dossier_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'operational_dossier_runtime_bound',
                'operational_dossier_bound',
                'dossier_evidence_spine_bound',
                'dossier_control_plane_bound',
                'dossier_decision_packet_bound',
                'dossier_promotion_path_bound',
                'dossier_scorecard_bound',
                'dossier_external_blocked',
                ['hash', 'operational_dossier_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'dossier_bound_count' => 'operational_dossier_bound',
                'evidence_spine_bound_count' => 'dossier_evidence_spine_bound',
                'control_plane_bound_count' => 'dossier_control_plane_bound',
                'decision_packet_bound_count' => 'dossier_decision_packet_bound',
                'promotion_path_bound_count' => 'dossier_promotion_path_bound',
                'scorecard_bound_count' => 'dossier_scorecard_bound',
                'external_blocked_count' => 'dossier_external_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_delivery_allowed' => false,
                'real_world_autonomy_claim_allowed' => false,
                'operational_dossier_requires_evidence_controls_decision_packet_promotion_path_and_scorecard' => true,
                'operator_mandate_required_for_any_external_promotion' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalOutcomeRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->svc->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $cell): string => (string) ($cell['flow_id'] ?? ''),
                (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []),
            ));
            $companyRecords = $this->records->runtimeRecordsForCompany($currentCompanyId);
            $outcomeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (string) ($record['operational_outcome_ledger_hash'] ?? '') !== ''
                    && (bool) ($record['operational_outcome_ledger_bound'] ?? false)
                    && (bool) ($record['operational_outcome_value_proxy_bound'] ?? false)
                    && (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false)
                    && (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false)
                    && (bool) ($record['operational_outcome_next_cycle_bound'] ?? false)
                    && (int) ($record['operational_outcome_evidence_ref_count'] ?? 0) >= 4
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $outcomeRecords,
            ))));

            $row = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_operational_outcome_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_operational_outcome_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'operational_outcome_ledger_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_ledger_bound'] ?? false))),
                'value_proxy_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_value_proxy_bound'] ?? false))),
                'acceptance_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false))),
                'risk_scorecard_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false))),
                'next_cycle_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_next_cycle_bound'] ?? false))),
                'evidence_ref_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['operational_outcome_evidence_ref_count'] ?? 0), $companyRecords)),
            ];
            $row['operational_outcome_runtime_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_operational_outcome_flow_count'], $companies));

        return AtlasEnvelope::seal([
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_operational_outcome_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_operational_outcome_runtime_coverage_external_blocked'
                : 'missing_operational_outcome_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_operational_outcome_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'operational_outcome_ledger_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operational_outcome_ledger_bound_count'], $companies)),
                'value_proxy_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['value_proxy_bound_count'], $companies)),
                'acceptance_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_contract_bound_count'], $companies)),
                'risk_scorecard_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['risk_scorecard_bound_count'], $companies)),
                'next_cycle_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['next_cycle_bound_count'], $companies)),
                'evidence_ref_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evidence_ref_count'], $companies)),
                'external_value_claim_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_value_claim_allowed'] ?? false))),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operational_outcome_runtime_requires_ledger_value_proxy_acceptance_risk_next_cycle_and_evidence_refs_per_flow' => true,
                'real_world_outcome_claim_requires_external_evidence_and_operator_acceptance' => true,
            ],
        ], 'operational_outcome_runtime_status_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function autonomyPromotionRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_autonomy_promotion_runtime_status.v1',
            'status_complete' => 'complete_autonomy_promotion_runtime_coverage_limited_external_autonomy_blocked',
            'status_missing' => 'missing_autonomy_promotion_runtime_coverage',
            'hash_key' => 'autonomy_promotion_runtime_status_hash',
            'completed_key' => 'completed_autonomy_promotion_flow_count',
            'missing_key' => 'missing_autonomy_promotion_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'autonomy_promotion_runtime_bound',
                'autonomy_ladder_bound',
                'autonomy_fixture_level_bound',
                'autonomy_shadow_level_bound',
                'autonomy_supervised_internal_level_bound',
                'autonomy_supervised_external_packet_bound',
                'autonomy_limited_external_autonomy_blocked',
                'autonomy_evidence_spine_bound',
                'autonomy_rollback_reconciliation_bound',
                ['hash', 'autonomy_promotion_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'autonomy_ladder_bound_count' => 'autonomy_ladder_bound',
                'supervised_external_packet_bound_count' => 'autonomy_supervised_external_packet_bound',
                'limited_external_autonomy_blocked_count' => 'autonomy_limited_external_autonomy_blocked',
                'evidence_spine_bound_count' => 'autonomy_evidence_spine_bound',
                'rollback_reconciliation_bound_count' => 'autonomy_rollback_reconciliation_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'limited_external_autonomy_allowed' => false,
                'autonomy_claim_without_live_evidence_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_limited_external_autonomy' => true,
                'second_reviewer_required_for_limited_external_autonomy' => true,
                'autonomy_promotion_requires_fixture_shadow_supervised_packet_evidence_rollback_and_reconciliation' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function holdingOutcomeScorecardStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->svc->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $expectedFlowCount = count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []));
            $companyRecords = $this->records->runtimeRecordsForCompany($currentCompanyId);
            $outcomeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['operational_outcome_ledger_bound'] ?? false)
                    && (string) ($record['operational_outcome_ledger_hash'] ?? '') !== ''
                    && (bool) ($record['operational_outcome_value_proxy_bound'] ?? false)
                    && (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false)
                    && (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false)
                    && (bool) ($record['operational_outcome_next_cycle_bound'] ?? false)
                    && (bool) ($record['external_value_claim_allowed'] ?? true) === false
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlowCount = count(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $outcomeRecords,
            ))));
            $kpiCount = array_sum(array_map(static fn (array $record): int => (int) ($record['operational_outcome_kpi_count'] ?? 0), $outcomeRecords));
            $acceptanceContractCount = count(array_filter($outcomeRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false)));
            $riskScorecardCount = count(array_filter($outcomeRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false)));
            $nextCycleCount = count(array_filter($outcomeRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_next_cycle_bound'] ?? false)));
            $coverage = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

            $row = [
                'schema' => 'atlas.ai.company.holding_outcome_scorecard.v1',
                'company_id' => $currentCompanyId,
                'expected_flow_count' => $expectedFlowCount,
                'completed_outcome_flow_count' => $completedFlowCount,
                'operational_outcome_ledger_count' => count($outcomeRecords),
                'measured_kpi_count' => $kpiCount,
                'acceptance_contract_count' => $acceptanceContractCount,
                'risk_scorecard_count' => $riskScorecardCount,
                'next_cycle_count' => $nextCycleCount,
                'coverage_rate' => $coverage,
                'score' => round($coverage * 10, 2),
                'external_value_claim_allowed' => false,
                'external_side_effects' => false,
                'ready' => $expectedFlowCount > 0
                    && $expectedFlowCount === $completedFlowCount
                    && $kpiCount >= $expectedFlowCount
                    && $acceptanceContractCount >= $expectedFlowCount
                    && $riskScorecardCount >= $expectedFlowCount
                    && $nextCycleCount >= $expectedFlowCount,
                'missing_flows' => array_values(array_diff(
                    array_values(array_map(
                        static fn (array $cell): string => (string) ($cell['flow_id'] ?? ''),
                        (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []),
                    )),
                    array_values(array_map(static fn (array $record): string => (string) ($record['flow_id'] ?? ''), $outcomeRecords)),
                )),
            ];
            $row['scorecard_hash'] = MissionCanonicalHash::sha256($row);

            $companies[] = $row;
            array_push($records, ...$companyRecords);
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['ready'] ?? false)));
        $companyCount = count($companies);
        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_outcome_flow_count'], $companies));

        $payload = [
            'ok' => $companyCount > 0 && $readyCompanyCount === $companyCount,
            'schema' => 'atlas.ai.holding.enterprise_holding_outcome_scorecard_status.v1',
            'status' => $companyCount > 0 && $readyCompanyCount === $companyCount
                ? 'holding_outcome_scorecard_ready_external_claim_blocked'
                : 'holding_outcome_scorecard_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'ready_company_count' => $readyCompanyCount,
                'expected_flow_count' => $expectedFlowCount,
                'completed_outcome_flow_count' => $completedFlowCount,
                'operational_outcome_ledger_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operational_outcome_ledger_count'], $companies)),
                'measured_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) $company['measured_kpi_count'], $companies)),
                'acceptance_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_contract_count'], $companies)),
                'risk_scorecard_count' => array_sum(array_map(static fn (array $company): int => (int) $company['risk_scorecard_count'], $companies)),
                'next_cycle_count' => array_sum(array_map(static fn (array $company): int => (int) $company['next_cycle_count'], $companies)),
                'average_score' => $companyCount > 0 ? round(array_sum(array_map(static fn (array $company): float => (float) $company['score'], $companies)) / $companyCount, 2) : 0.0,
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
                'external_value_claim_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_value_claim_allowed'] ?? false))),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
            ],
            'companies' => $companies,
            'policy' => [
                'external_execution_allowed' => false,
                'external_value_claim_allowed' => false,
                'scorecard_source' => 'internal_operational_outcome_ledgers_with_acceptance_risk_and_next_cycle_evidence',
                'real_business_value_claim_requires_external_evidence_operator_acceptance_and_signed_scope' => true,
            ],
        ];
        $payload['holding_outcome_scorecard_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function portfolioDecisionPacketStatus(?string $companyId = null): array
    {
        $scorecards = $this->svc->holdingOutcomeScorecardStatus($companyId);
        $companyPackets = array_values(array_map(
            static function (array $company): array {
                $score = (float) ($company['score'] ?? 0.0);
                $expected = (int) ($company['expected_flow_count'] ?? 0);
                $completed = (int) ($company['completed_outcome_flow_count'] ?? 0);
                $measuredKpis = (int) ($company['measured_kpi_count'] ?? 0);

                $packet = [
                    'schema' => 'atlas.ai.company.portfolio_decision_packet.v1',
                    'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                    'scorecard_hash' => (string) ($company['scorecard_hash'] ?? ''),
                    'score' => $score,
                    'decision_recommendation' => $score >= 9.0 ? 'scale_internal_supervised_capacity' : 'repair_before_scale',
                    'allocation_class' => $score >= 9.0 ? 'increase_internal_priority' : 'hold_and_repair',
                    'expected_flow_count' => $expected,
                    'completed_outcome_flow_count' => $completed,
                    'measured_kpi_count' => $measuredKpis,
                    'required_operator_decisions' => [
                        'approve_or_reject_internal_capacity_shift',
                        'approve_shadow_or_supervised_external_probe_scope',
                        'confirm_no_real_capital_commitment_without_signed_packet',
                    ],
                    'blocked_without_signed_packet' => ['real_capital_commitment', 'external_spend', 'customer_commitment', 'vendor_procurement', 'public_claim'],
                    'external_execution_allowed' => false,
                    'real_capital_action_allowed' => false,
                    'ready' => (bool) ($company['ready'] ?? false)
                        && $score >= 9.0
                        && $expected > 0
                        && $expected === $completed
                        && $measuredKpis >= $expected
                        && (bool) ($company['external_value_claim_allowed'] ?? true) === false
                        && (bool) ($company['external_side_effects'] ?? true) === false,
                ];
                $packet['decision_packet_hash'] = MissionCanonicalHash::sha256($packet);

                return $packet;
            },
            (array) ($scorecards['companies'] ?? []),
        ));

        $readyPacketCount = count(array_filter($companyPackets, static fn (array $packet): bool => (bool) ($packet['ready'] ?? false)));
        $companyCount = count($companyPackets);

        $payload = [
            'ok' => (bool) ($scorecards['ok'] ?? false) && $companyCount > 0 && $readyPacketCount === $companyCount,
            'schema' => 'atlas.ai.holding.enterprise_portfolio_decision_packet_status.v1',
            'status' => (bool) ($scorecards['ok'] ?? false) && $companyCount > 0 && $readyPacketCount === $companyCount
                ? 'portfolio_decision_packet_ready_external_capital_blocked'
                : 'portfolio_decision_packet_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'ready_decision_packet_count' => $readyPacketCount,
                'scale_internal_supervised_capacity_count' => count(array_filter(
                    $companyPackets,
                    static fn (array $packet): bool => ($packet['decision_recommendation'] ?? null) === 'scale_internal_supervised_capacity',
                )),
                'blocked_real_capital_action_count' => count(array_filter(
                    $companyPackets,
                    static fn (array $packet): bool => (bool) ($packet['real_capital_action_allowed'] ?? true) === false,
                )),
                'average_score' => (float) data_get($scorecards, 'summary.average_score', 0.0),
                'external_value_claim_count' => (int) data_get($scorecards, 'summary.external_value_claim_count', 0),
                'external_side_effect_count' => (int) data_get($scorecards, 'summary.external_side_effect_count', 0),
            ],
            'scorecard_status_hash' => (string) ($scorecards['holding_outcome_scorecard_status_hash'] ?? ''),
            'companies' => $companyPackets,
            'policy' => [
                'external_execution_allowed' => false,
                'real_capital_action_allowed' => false,
                'operator_signed_packet_required_for_external_spend_or_capital' => true,
                'decision_packet_source' => 'holding_outcome_scorecard_status',
            ],
        ];
        $payload['portfolio_decision_packet_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companyBoardOperatingReviewStatus(?string $companyId = null): array
    {
        $scorecards = $this->svc->holdingOutcomeScorecardStatus($companyId);
        $portfolio = $this->svc->portfolioDecisionPacketStatus($companyId);
        $businessPackets = $this->svc->businessOperatingPacketRuntimeStatus($companyId);
        $commandCenter = $this->svc->commandCenterControlTowerRuntimeStatus($companyId);

        $portfolioByCompany = [];
        foreach ((array) ($portfolio['companies'] ?? []) as $company) {
            $portfolioByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $businessByCompany = [];
        foreach ((array) ($businessPackets['companies'] ?? []) as $company) {
            $businessByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $commandCenterByCompany = [];
        foreach ((array) ($commandCenter['companies'] ?? []) as $company) {
            $commandCenterByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }

        $companies = array_values(array_map(
            static function (array $scorecard) use ($portfolioByCompany, $businessByCompany, $commandCenterByCompany): array {
                $companyId = (string) ($scorecard['company_id'] ?? 'unknown');
                $portfolioPacket = (array) ($portfolioByCompany[$companyId] ?? []);
                $businessPacket = (array) ($businessByCompany[$companyId] ?? []);
                $commandCenterPacket = (array) ($commandCenterByCompany[$companyId] ?? []);
                $expectedFlows = (int) ($scorecard['expected_flow_count'] ?? 0);
                $completedFlows = (int) ($scorecard['completed_outcome_flow_count'] ?? 0);
                $acceptanceContractCount = (int) ($scorecard['acceptance_contract_count'] ?? 0);
                $riskScorecardCount = (int) ($scorecard['risk_scorecard_count'] ?? 0);
                $nextCycleCount = (int) ($scorecard['next_cycle_count'] ?? 0);
                $requiredOperatorDecisions = array_values((array) ($portfolioPacket['required_operator_decisions'] ?? []));
                $businessReady = $expectedFlows > 0
                    && (int) ($businessPacket['completed_business_operating_packet_flow_count'] ?? 0) === $expectedFlows;
                $commandReady = $expectedFlows > 0
                    && (int) ($commandCenterPacket['completed_command_center_control_tower_flow_count'] ?? 0) === $expectedFlows;
                $improvementBacklogReady = $expectedFlows > 0
                    && $acceptanceContractCount >= $expectedFlows
                    && $riskScorecardCount >= $expectedFlows
                    && $nextCycleCount >= $expectedFlows
                    && count($requiredOperatorDecisions) >= 3;

                $row = [
                    'schema' => 'atlas.ai.company.board_operating_review_packet.v1',
                    'company_id' => $companyId,
                    'scorecard_hash' => (string) ($scorecard['scorecard_hash'] ?? ''),
                    'portfolio_decision_packet_hash' => (string) ($portfolioPacket['decision_packet_hash'] ?? ''),
                    'score' => (float) ($scorecard['score'] ?? 0.0),
                    'expected_flow_count' => $expectedFlows,
                    'completed_outcome_flow_count' => $completedFlows,
                    'business_operating_packet_flow_count' => (int) ($businessPacket['completed_business_operating_packet_flow_count'] ?? 0),
                    'command_center_flow_count' => (int) ($commandCenterPacket['completed_command_center_control_tower_flow_count'] ?? 0),
                    'measured_kpi_count' => (int) ($scorecard['measured_kpi_count'] ?? 0),
                    'acceptance_contract_count' => $acceptanceContractCount,
                    'risk_scorecard_count' => $riskScorecardCount,
                    'next_cycle_count' => $nextCycleCount,
                    'decision_recommendation' => (string) ($portfolioPacket['decision_recommendation'] ?? 'repair_before_scale'),
                    'review_sections' => [
                        'operational_outcomes',
                        'business_operating_packets',
                        'command_center_control_tower',
                        'portfolio_decision',
                        'continuous_improvement_backlog',
                        'risk_and_external_commitment_blocks',
                        'next_operator_decisions',
                    ],
                    'required_operator_decisions' => $requiredOperatorDecisions,
                    'required_operator_decision_count' => count($requiredOperatorDecisions),
                    'continuous_improvement_backlog' => [
                        'acceptance_followup_count' => $acceptanceContractCount,
                        'risk_remediation_count' => $riskScorecardCount,
                        'next_cycle_action_count' => $nextCycleCount,
                        'board_decision_action_count' => count($requiredOperatorDecisions),
                        'bound_to_outcome_scorecard' => $improvementBacklogReady,
                        'external_commitments_allowed' => false,
                    ],
                    'board_review_gates' => [
                        'scorecard_ready' => (bool) ($scorecard['ready'] ?? false),
                        'portfolio_decision_ready' => (bool) ($portfolioPacket['ready'] ?? false),
                        'business_operating_packet_ready' => $businessReady,
                        'command_center_ready' => $commandReady,
                        'continuous_improvement_backlog_ready' => $improvementBacklogReady,
                        'external_value_claim_blocked' => (bool) ($scorecard['external_value_claim_allowed'] ?? true) === false,
                        'external_side_effects_blocked' => (bool) ($scorecard['external_side_effects'] ?? true) === false,
                    ],
                    'external_commitment_controls' => [
                        'external_execution_allowed' => false,
                        'external_customer_commitment_allowed' => false,
                        'external_billing_allowed' => false,
                        'real_capital_action_allowed' => false,
                        'public_claim_allowed' => false,
                    ],
                    'ready' => (bool) ($scorecard['ready'] ?? false)
                        && (bool) ($portfolioPacket['ready'] ?? false)
                        && $businessReady
                        && $commandReady
                        && $improvementBacklogReady
                        && (bool) ($scorecard['external_value_claim_allowed'] ?? true) === false
                        && (bool) ($scorecard['external_side_effects'] ?? true) === false,
                ];
                $row['board_operating_review_hash'] = MissionCanonicalHash::sha256($row);

                return $row;
            },
            (array) ($scorecards['companies'] ?? []),
        ));

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['ready'] ?? false)));
        $companyCount = count($companies);

        $payload = [
            'ok' => $companyCount > 0 && $readyCompanyCount === $companyCount,
            'schema' => 'atlas.ai.holding.enterprise_company_board_operating_review_status.v1',
            'status' => $companyCount > 0 && $readyCompanyCount === $companyCount
                ? 'company_board_operating_reviews_ready_external_commitments_blocked'
                : 'company_board_operating_reviews_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'ready_company_count' => $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies)),
                'completed_outcome_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['completed_outcome_flow_count'], $companies)),
                'business_operating_packet_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_operating_packet_flow_count'], $companies)),
                'command_center_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_flow_count'], $companies)),
                'measured_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) $company['measured_kpi_count'], $companies)),
                'acceptance_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_contract_count'], $companies)),
                'risk_remediation_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'continuous_improvement_backlog.risk_remediation_count', 0), $companies)),
                'next_cycle_action_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'continuous_improvement_backlog.next_cycle_action_count', 0), $companies)),
                'board_decision_action_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'continuous_improvement_backlog.board_decision_action_count', 0), $companies)),
                'scale_internal_supervised_capacity_count' => count(array_filter(
                    $companies,
                    static fn (array $company): bool => ($company['decision_recommendation'] ?? null) === 'scale_internal_supervised_capacity',
                )),
                'external_commitment_allowed_count' => 0,
                'external_side_effect_count' => 0,
            ],
            'source_hashes' => [
                'holding_outcome_scorecard_status_hash' => $scorecards['holding_outcome_scorecard_status_hash'] ?? null,
                'portfolio_decision_packet_status_hash' => $portfolio['portfolio_decision_packet_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPackets['business_operating_packet_runtime_status_hash'] ?? null,
                'command_center_control_tower_runtime_status_hash' => $commandCenter['command_center_control_tower_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'real_capital_action_allowed' => false,
                'board_review_is_internal_governance_not_external_authority' => true,
                'operator_signed_mandate_required_for_any_external_commitment' => true,
            ],
        ];
        $payload['company_board_operating_review_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function crossCompanyHandoffRuntimeStatus(?string $companyId = null): array
    {
        $report = $this->svc->buildoutReport();
        $contracts = array_values(array_filter(
            (array) data_get($report, 'cross_company_fabric.handoff_contracts', []),
            static fn (array $contract): bool => $companyId === null
                || (string) ($contract['source_company'] ?? '') === $companyId
                || (string) ($contract['target_company'] ?? '') === $companyId,
        ));
        $companyIds = array_values(array_map(
            static fn (array $company): string => (string) ($company['company_id'] ?? 'unknown'),
            (array) ($report['companies'] ?? []),
        ));
        $runtimeRecordsByCompany = [];
        foreach ($companyIds as $currentCompanyId) {
            $runtimeRecordsByCompany[$currentCompanyId] = $this->records->runtimeRecordsForCompany($currentCompanyId);
        }

        $packets = [];
        foreach ($contracts as $contract) {
            $sourceCompany = (string) ($contract['source_company'] ?? 'unknown');
            $targetCompany = (string) ($contract['target_company'] ?? 'unknown');
            $sourceRecords = (array) ($runtimeRecordsByCompany[$sourceCompany] ?? []);
            $targetRecords = (array) ($runtimeRecordsByCompany[$targetCompany] ?? []);
            $sourceRuntimeReady = $sourceRecords !== [] && count(array_filter(
                $sourceRecords,
                static fn (array $record): bool => (string) ($record['receipt_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            )) === count($sourceRecords);
            $targetAcceptanceBound = in_array($targetCompany, $companyIds, true)
                && $targetRecords !== []
                && count(array_filter(
                    $targetRecords,
                    static fn (array $record): bool => (string) ($record['receipt_hash'] ?? '') !== ''
                        && (bool) ($record['external_side_effects'] ?? true) === false,
                )) === count($targetRecords);

            $packet = [
                'schema' => 'atlas.ai.company.cross_company_handoff_runtime_packet.v1',
                'source_company' => $sourceCompany,
                'target_company' => $targetCompany,
                'contract' => (string) ($contract['contract'] ?? $sourceCompany.'->'.$targetCompany),
                'contract_hash' => (string) ($contract['contract_hash'] ?? ''),
                'source_runtime_record_count' => count($sourceRecords),
                'target_runtime_record_count' => count($targetRecords),
                'source_runtime_ready' => $sourceRuntimeReady,
                'target_acceptance_bound' => $targetAcceptanceBound,
                'typed_context_bound' => (bool) ($contract['typed_context_required'] ?? false),
                'evidence_refs_bound' => (bool) ($contract['evidence_refs_required'] ?? false)
                    && count(array_filter($sourceRecords, static fn (array $record): bool => (string) ($record['receipt_hash'] ?? '') !== '')) > 0,
                'acceptance_required' => (bool) ($contract['acceptance_required'] ?? true),
                'acceptance_status' => $targetAcceptanceBound ? 'accepted_internal_handoff_packet' : 'missing_target_runtime_acceptance',
                'source_receipt_refs' => array_values(array_slice(array_filter(array_map(
                    static fn (array $record): string => (string) ($record['receipt_hash'] ?? ''),
                    $sourceRecords,
                )), 0, 5)),
                'target_receipt_refs' => array_values(array_slice(array_filter(array_map(
                    static fn (array $record): string => (string) ($record['receipt_hash'] ?? ''),
                    $targetRecords,
                )), 0, 5)),
                'external_side_effects' => false,
                'external_execution_allowed' => false,
            ];
            $packet['ready'] = $sourceRuntimeReady
                && $targetAcceptanceBound
                && (bool) $packet['typed_context_bound']
                && (bool) $packet['evidence_refs_bound']
                && (bool) ($contract['external_side_effects'] ?? true) === false;
            $packet['handoff_runtime_packet_hash'] = MissionCanonicalHash::sha256($packet);

            $packets[] = $packet;
        }

        $readyPacketCount = count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['ready'] ?? false)));
        $contractCount = count($contracts);

        $payload = [
            'ok' => $contractCount > 0 && $readyPacketCount === $contractCount,
            'schema' => 'atlas.ai.holding.enterprise_cross_company_handoff_runtime_status.v1',
            'status' => $contractCount > 0 && $readyPacketCount === $contractCount
                ? 'cross_company_handoff_runtime_ready_external_blocked'
                : 'cross_company_handoff_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'handoff_contract_count' => $contractCount,
                'ready_handoff_runtime_packet_count' => $readyPacketCount,
                'source_runtime_ready_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['source_runtime_ready'] ?? false))),
                'target_acceptance_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['target_acceptance_bound'] ?? false))),
                'typed_context_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['typed_context_bound'] ?? false))),
                'evidence_refs_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['evidence_refs_bound'] ?? false))),
                'external_side_effect_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['external_side_effects'] ?? true))),
                'coverage_rate' => $contractCount > 0 ? round($readyPacketCount / $contractCount, 4) : 0.0,
            ],
            'handoff_runtime_packets' => $packets,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'handoff_requires_source_runtime_target_acceptance_typed_context_and_evidence_refs' => true,
                'manual_or_external_handoff_requires_operator_signed_scope' => true,
            ],
        ];
        $payload['cross_company_handoff_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function customerAccountRevenueRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_customer_account_revenue_runtime_status.v1',
            'status_complete' => 'complete_customer_account_revenue_runtime_coverage_external_revenue_blocked',
            'status_missing' => 'missing_customer_account_revenue_runtime_coverage',
            'hash_key' => 'customer_account_revenue_runtime_status_hash',
            'completed_key' => 'completed_customer_account_revenue_flow_count',
            'missing_key' => 'missing_customer_account_revenue_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'customer_account_revenue_runtime_bound',
                'customer_market_runtime_bound',
                'offer_packaging_bound',
                'journey_lifecycle_bound',
                'customer_success_scorecard_bound',
                'commercial_service_catalog_bound',
                'business_kpi_bound',
                'account_contract_delivery_bound',
                'account_segment_playbook_bound',
                'contract_entitlement_bound',
                'onboarding_success_plan_bound',
                'service_review_renewal_bound',
                'account_health_risk_bound',
                'billing_revenue_model_bound',
                'account_observability_bound',
                ['hash', 'customer_account_revenue_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'customer_market_runtime_bound_count' => 'customer_market_runtime_bound',
                'offer_packaging_bound_count' => 'offer_packaging_bound',
                'journey_lifecycle_bound_count' => 'journey_lifecycle_bound',
                'customer_success_scorecard_bound_count' => 'customer_success_scorecard_bound',
                'commercial_service_catalog_bound_count' => 'commercial_service_catalog_bound',
                'business_kpi_bound_count' => 'business_kpi_bound',
                'account_contract_delivery_bound_count' => 'account_contract_delivery_bound',
                'account_segment_playbook_bound_count' => 'account_segment_playbook_bound',
                'contract_entitlement_bound_count' => 'contract_entitlement_bound',
                'onboarding_success_plan_bound_count' => 'onboarding_success_plan_bound',
                'service_review_renewal_bound_count' => 'service_review_renewal_bound',
                'account_health_risk_bound_count' => 'account_health_risk_bound',
                'billing_revenue_model_bound_count' => 'billing_revenue_model_bound',
                'account_observability_bound_count' => 'account_observability_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'revenue_claim_allowed' => false,
                'real_revenue_claim_requires_external_evidence_operator_acceptance_and_signed_scope' => true,
                'customer_account_revenue_runtime_requires_icp_offer_journey_account_contract_success_renewal_billing_controls' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function productizedServiceRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_productized_service_runtime_status.v1',
            'status_complete' => 'complete_productized_service_runtime_coverage_external_commitment_billing_blocked',
            'status_missing' => 'missing_productized_service_runtime_coverage',
            'hash_key' => 'productized_service_runtime_status_hash',
            'completed_key' => 'completed_productized_service_flow_count',
            'missing_key' => 'missing_productized_service_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'productized_service_runtime_bound',
                'productized_service_stack_bound',
                'productized_domain_product_line_bound',
                'productized_service_offer_bound',
                'productized_delivery_blueprint_bound',
                'productized_intake_contract_bound',
                'productized_sla_success_contract_bound',
                'productized_pricing_packaging_bound',
                'productized_gtm_motion_bound',
                'productized_proof_template_bound',
                'productized_observability_bound',
                'productized_external_commitment_billing_blocked',
                ['hash', 'productized_service_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'service_offer_bound_count' => 'productized_service_offer_bound',
                'delivery_blueprint_bound_count' => 'productized_delivery_blueprint_bound',
                'intake_contract_bound_count' => 'productized_intake_contract_bound',
                'sla_success_contract_bound_count' => 'productized_sla_success_contract_bound',
                'pricing_packaging_bound_count' => 'productized_pricing_packaging_bound',
                'gtm_motion_bound_count' => 'productized_gtm_motion_bound',
                'proof_template_bound_count' => 'productized_proof_template_bound',
                'observability_bound_count' => 'productized_observability_bound',
                'external_commitment_billing_blocked_count' => 'productized_external_commitment_billing_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'public_gtm_or_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'productized_service_runtime_requires_offer_delivery_intake_sla_pricing_gtm_proof_and_observability' => true,
                'operator_mandate_required_for_public_offer_customer_commitment_or_billing' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function salesCrmPipelineRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_sales_crm_pipeline_runtime_status.v1',
            'status_complete' => 'complete_sales_crm_pipeline_runtime_coverage_external_commitments_blocked',
            'status_missing' => 'missing_sales_crm_pipeline_runtime_coverage',
            'hash_key' => 'sales_crm_pipeline_runtime_status_hash',
            'completed_key' => 'completed_sales_crm_pipeline_flow_count',
            'missing_key' => 'missing_sales_crm_pipeline_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'sales_crm_pipeline_runtime_bound',
                'sales_crm_stack_bound',
                'sales_source_catalog_bound',
                'sales_crm_object_model_bound',
                'sales_segment_play_bound',
                'sales_opportunity_route_bound',
                'sales_proposal_scope_bound',
                'sales_mutual_action_plan_bound',
                'sales_account_research_workbench_bound',
                'sales_deal_room_packet_bound',
                'sales_pipeline_forecast_review_bound',
                'sales_map_risk_review_bound',
                'sales_renewal_expansion_signal_bound',
                'sales_delivery_handoff_bound',
                'sales_pipeline_observability_bound',
                'sales_external_commitments_blocked',
                ['hash', 'sales_crm_pipeline_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'sales_source_catalog_bound',
                'crm_object_model_bound_count' => 'sales_crm_object_model_bound',
                'segment_play_bound_count' => 'sales_segment_play_bound',
                'opportunity_route_bound_count' => 'sales_opportunity_route_bound',
                'proposal_scope_bound_count' => 'sales_proposal_scope_bound',
                'mutual_action_plan_bound_count' => 'sales_mutual_action_plan_bound',
                'account_research_workbench_bound_count' => 'sales_account_research_workbench_bound',
                'deal_room_packet_bound_count' => 'sales_deal_room_packet_bound',
                'pipeline_forecast_review_bound_count' => 'sales_pipeline_forecast_review_bound',
                'map_risk_review_bound_count' => 'sales_map_risk_review_bound',
                'renewal_expansion_signal_bound_count' => 'sales_renewal_expansion_signal_bound',
                'sales_delivery_handoff_bound_count' => 'sales_delivery_handoff_bound',
                'pipeline_observability_bound_count' => 'sales_pipeline_observability_bound',
                'external_commitments_blocked_count' => 'sales_external_commitments_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_outreach_contract_signature_or_customer_commitment_allowed' => false,
                'public_claim_or_paid_campaign_allowed' => false,
                'sales_crm_runtime_requires_crm_opportunity_proposal_map_renewal_handoff_and_observability' => true,
                'operator_mandate_required_for_external_sales_message_contract_or_commitment' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function customerSupportServiceDeskRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_customer_support_service_desk_runtime_status.v1',
            'status_complete' => 'complete_customer_support_service_desk_runtime_coverage_external_support_blocked',
            'status_missing' => 'missing_customer_support_service_desk_runtime_coverage',
            'hash_key' => 'customer_support_service_desk_runtime_status_hash',
            'completed_key' => 'completed_customer_support_service_desk_flow_count',
            'missing_key' => 'missing_customer_support_service_desk_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'customer_support_service_desk_runtime_bound',
                'support_service_desk_stack_bound',
                'support_source_catalog_bound',
                'support_service_desk_object_model_bound',
                'support_segment_playbook_bound',
                'support_lane_bound',
                'support_ticket_sla_contract_bound',
                'support_knowledge_base_template_bound',
                'support_escalation_incident_runbook_bound',
                'support_resolution_rca_bound',
                'support_case_resolution_workbench_bound',
                'support_customer_health_escalation_bound',
                'support_knowledge_quality_review_bound',
                'support_automation_deflection_test_bound',
                'support_feedback_learning_loop_bound',
                'support_observability_bound',
                'support_external_customer_actions_blocked',
                ['hash', 'customer_support_service_desk_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'support_source_catalog_bound',
                'service_desk_object_model_bound_count' => 'support_service_desk_object_model_bound',
                'segment_playbook_bound_count' => 'support_segment_playbook_bound',
                'support_lane_bound_count' => 'support_lane_bound',
                'ticket_sla_contract_bound_count' => 'support_ticket_sla_contract_bound',
                'knowledge_base_template_bound_count' => 'support_knowledge_base_template_bound',
                'escalation_incident_runbook_bound_count' => 'support_escalation_incident_runbook_bound',
                'resolution_rca_bound_count' => 'support_resolution_rca_bound',
                'case_resolution_workbench_bound_count' => 'support_case_resolution_workbench_bound',
                'customer_health_escalation_bound_count' => 'support_customer_health_escalation_bound',
                'knowledge_quality_review_bound_count' => 'support_knowledge_quality_review_bound',
                'automation_deflection_test_bound_count' => 'support_automation_deflection_test_bound',
                'feedback_learning_loop_bound_count' => 'support_feedback_learning_loop_bound',
                'support_observability_bound_count' => 'support_observability_bound',
                'external_customer_actions_blocked_count' => 'support_external_customer_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_message_or_support_commitment_allowed' => false,
                'regulated_support_advice_allowed_without_review' => false,
                'support_runtime_requires_ticket_sla_kb_escalation_rca_feedback_and_observability' => true,
                'operator_mandate_required_for_external_customer_support_or_public_kb' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function marketingGrowthEngineRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_marketing_growth_engine_runtime_status.v1',
            'status_complete' => 'complete_marketing_growth_engine_runtime_coverage_external_publish_blocked',
            'status_missing' => 'missing_marketing_growth_engine_runtime_coverage',
            'hash_key' => 'marketing_growth_engine_runtime_status_hash',
            'completed_key' => 'completed_marketing_growth_engine_flow_count',
            'missing_key' => 'missing_marketing_growth_engine_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'marketing_growth_engine_runtime_bound',
                'marketing_growth_stack_bound',
                'marketing_source_catalog_bound',
                'marketing_growth_operating_model_bound',
                'marketing_audience_segment_bound',
                'marketing_campaign_blueprint_bound',
                'marketing_content_asset_factory_bound',
                'marketing_experiment_bound',
                'marketing_growth_intelligence_workbench_bound',
                'marketing_attribution_experiment_model_bound',
                'marketing_channel_budget_guardrail_bound',
                'marketing_public_claim_evidence_packet_bound',
                'marketing_channel_distribution_bound',
                'marketing_brand_compliance_review_bound',
                'marketing_growth_crm_handoff_bound',
                'marketing_observability_bound',
                'marketing_external_publish_actions_blocked',
                ['hash', 'marketing_growth_engine_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'marketing_source_catalog_bound',
                'growth_operating_model_bound_count' => 'marketing_growth_operating_model_bound',
                'audience_segment_bound_count' => 'marketing_audience_segment_bound',
                'campaign_blueprint_bound_count' => 'marketing_campaign_blueprint_bound',
                'content_asset_factory_bound_count' => 'marketing_content_asset_factory_bound',
                'experiment_bound_count' => 'marketing_experiment_bound',
                'growth_intelligence_workbench_bound_count' => 'marketing_growth_intelligence_workbench_bound',
                'attribution_experiment_model_bound_count' => 'marketing_attribution_experiment_model_bound',
                'channel_budget_guardrail_bound_count' => 'marketing_channel_budget_guardrail_bound',
                'public_claim_evidence_packet_bound_count' => 'marketing_public_claim_evidence_packet_bound',
                'channel_distribution_bound_count' => 'marketing_channel_distribution_bound',
                'brand_compliance_review_bound_count' => 'marketing_brand_compliance_review_bound',
                'growth_crm_handoff_bound_count' => 'marketing_growth_crm_handoff_bound',
                'marketing_observability_bound_count' => 'marketing_observability_bound',
                'external_publish_actions_blocked_count' => 'marketing_external_publish_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_publish_paid_campaign_or_outreach_allowed' => false,
                'public_claim_allowed_without_source_and_operator_review' => false,
                'marketing_runtime_requires_campaign_content_experiment_channel_brand_review_crm_handoff_and_observability' => true,
                'operator_mandate_required_for_external_publish_paid_campaign_or_outreach' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function financeTreasuryBillingRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_finance_treasury_billing_runtime_status.v1',
            'status_complete' => 'complete_finance_treasury_billing_runtime_coverage_external_finance_blocked',
            'status_missing' => 'missing_finance_treasury_billing_runtime_coverage',
            'hash_key' => 'finance_treasury_billing_runtime_status_hash',
            'completed_key' => 'completed_finance_treasury_billing_flow_count',
            'missing_key' => 'missing_finance_treasury_billing_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'finance_treasury_billing_runtime_bound',
                'finance_treasury_stack_bound',
                'finance_source_catalog_bound',
                'finance_financial_data_interface_bound',
                'finance_provider_connector_matrix_bound',
                'finance_cfo_operating_model_bound',
                'finance_financial_research_workbench_bound',
                'finance_budget_envelope_bound',
                'finance_forecast_model_bound',
                'finance_model_risk_control_bound',
                'finance_investment_committee_packet_bound',
                'finance_pnl_line_item_bound',
                'finance_billing_ledger_bound',
                'finance_treasury_risk_bound',
                'finance_close_audit_bound',
                'finance_observability_bound',
                'finance_external_financial_actions_blocked',
                ['hash', 'finance_treasury_billing_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'finance_source_catalog_bound',
                'financial_data_interface_bound_count' => 'finance_financial_data_interface_bound',
                'provider_connector_matrix_bound_count' => 'finance_provider_connector_matrix_bound',
                'cfo_operating_model_bound_count' => 'finance_cfo_operating_model_bound',
                'financial_research_workbench_bound_count' => 'finance_financial_research_workbench_bound',
                'budget_envelope_bound_count' => 'finance_budget_envelope_bound',
                'forecast_model_bound_count' => 'finance_forecast_model_bound',
                'model_risk_control_bound_count' => 'finance_model_risk_control_bound',
                'investment_committee_packet_bound_count' => 'finance_investment_committee_packet_bound',
                'pnl_line_item_bound_count' => 'finance_pnl_line_item_bound',
                'billing_ledger_bound_count' => 'finance_billing_ledger_bound',
                'treasury_risk_bound_count' => 'finance_treasury_risk_bound',
                'close_audit_bound_count' => 'finance_close_audit_bound',
                'observability_bound_count' => 'finance_observability_bound',
                'external_financial_actions_blocked_count' => 'finance_external_financial_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_invoice_payment_collection_capital_transfer_or_trade_allowed' => false,
                'real_revenue_cash_or_aum_claim_allowed' => false,
                'source_linked_financial_claim_required' => true,
                'model_risk_review_required_for_investment_or_capital_recommendation' => true,
                'finance_treasury_runtime_requires_budget_forecast_pnl_billing_treasury_close_and_observability' => true,
                'finance_treasury_runtime_requires_source_linked_data_interface_connectors_research_workbench_model_risk_and_committee_packets' => true,
                'operator_mandate_required_for_external_billing_capital_vendor_spend_or_trade' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function governanceRiskOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_governance_risk_operations_runtime_status.v1',
            'status_complete' => 'complete_governance_risk_operations_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_governance_risk_operations_runtime_coverage',
            'hash_key' => 'governance_risk_operations_runtime_status_hash',
            'completed_key' => 'completed_governance_risk_operations_flow_count',
            'missing_key' => 'missing_governance_risk_operations_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'governance_risk_operations_runtime_bound',
                'governance_vendor_procurement_bound',
                'governance_resilience_continuity_bound',
                'governance_analytics_decision_bound',
                'governance_knowledge_learning_bound',
                'governance_identity_sovereignty_bound',
                'governance_grc_evidence_bound',
                'governance_external_actions_blocked',
                ['hash', 'governance_risk_operations_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'vendor_procurement_bound_count' => 'governance_vendor_procurement_bound',
                'resilience_continuity_bound_count' => 'governance_resilience_continuity_bound',
                'analytics_decision_bound_count' => 'governance_analytics_decision_bound',
                'knowledge_learning_bound_count' => 'governance_knowledge_learning_bound',
                'identity_sovereignty_bound_count' => 'governance_identity_sovereignty_bound',
                'grc_evidence_bound_count' => 'governance_grc_evidence_bound',
                'external_actions_blocked_count' => 'governance_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'vendor_purchase_contract_signature_secret_share_or_write_scope_allowed' => false,
                'incident_external_notification_without_operator_allowed' => false,
                'canonical_memory_write_without_review_allowed' => false,
                'secret_material_or_unscoped_memory_export_allowed' => false,
                'governance_runtime_requires_procurement_resilience_analytics_learning_identity_and_grc_evidence' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function unitEconomicsCapacityRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_unit_economics_capacity_runtime_status.v1',
            'status_complete' => 'complete_unit_economics_capacity_runtime_coverage_external_capital_blocked',
            'status_missing' => 'missing_unit_economics_capacity_runtime_coverage',
            'hash_key' => 'unit_economics_capacity_runtime_status_hash',
            'completed_key' => 'completed_unit_economics_capacity_flow_count',
            'missing_key' => 'missing_unit_economics_capacity_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'unit_economics_capacity_bound',
                'flow_cost_center_bound',
                'flow_unit_economics_bound',
                'capacity_simulation_bound',
                'pricing_ladder_bound',
                'agent_capacity_cost_model_bound',
                'connector_cost_limit_model_bound',
                ['hash', 'unit_economics_capacity_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'flow_cost_center_bound_count' => 'flow_cost_center_bound',
                'flow_unit_economics_bound_count' => 'flow_unit_economics_bound',
                'capacity_simulation_bound_count' => 'capacity_simulation_bound',
                'pricing_ladder_bound_count' => 'pricing_ladder_bound',
                'agent_capacity_cost_model_bound_count' => 'agent_capacity_cost_model_bound',
                'connector_cost_limit_model_bound_count' => 'connector_cost_limit_model_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'real_capital_action_allowed' => false,
                'unit_economics_runtime_requires_cost_center_unit_model_capacity_pricing_agent_and_connector_cost_controls' => true,
                'observed_revenue_or_savings_claim_requires_external_evidence_and_operator_acceptance' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function businessOperatingPacketRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_business_operating_packet_runtime_status.v1',
            'status_complete' => 'complete_business_operating_packet_runtime_coverage_external_commitments_blocked',
            'status_missing' => 'missing_business_operating_packet_runtime_coverage',
            'hash_key' => 'business_operating_packet_runtime_status_hash',
            'completed_key' => 'completed_business_operating_packet_flow_count',
            'missing_key' => 'missing_business_operating_packet_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'business_operating_packet_bound',
                'business_operating_packet_business_model_bound',
                'business_operating_packet_kpi_contract_bound',
                'business_operating_packet_delivery_lane_bound',
                'business_operating_packet_economics_bound',
                'business_operating_packet_account_operations_bound',
                'business_operating_packet_external_commitments_blocked',
                ['hash', 'business_operating_packet_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'business_model_bound_count' => 'business_operating_packet_business_model_bound',
                'kpi_contract_bound_count' => 'business_operating_packet_kpi_contract_bound',
                'delivery_lane_bound_count' => 'business_operating_packet_delivery_lane_bound',
                'economics_bound_count' => 'business_operating_packet_economics_bound',
                'account_operations_bound_count' => 'business_operating_packet_account_operations_bound',
                'external_commitments_blocked_count' => 'business_operating_packet_external_commitments_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'real_capital_action_allowed' => false,
                'business_operating_packet_requires_business_model_kpi_sla_economics_account_ops_and_commitment_blocks' => true,
                'operator_mandate_required_for_external_customer_vendor_billing_capital_or_public_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function deliveryRiskRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_delivery_risk_runtime_status.v1',
            'status_complete' => 'complete_delivery_risk_runtime_coverage_external_claim_blocked',
            'status_missing' => 'missing_delivery_risk_runtime_coverage',
            'hash_key' => 'delivery_risk_runtime_status_hash',
            'completed_key' => 'completed_delivery_risk_flow_count',
            'missing_key' => 'missing_delivery_risk_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'delivery_risk_runtime_bound',
                'delivery_assurance_runtime_bound',
                'delivery_sla_bound',
                'strategic_intelligence_bound',
                'rival_alternative_map_bound',
                'grc_runtime_bound',
                'audit_evidence_bound',
                'policy_exception_blocked',
                ['hash', 'delivery_risk_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'delivery_assurance_runtime_bound_count' => 'delivery_assurance_runtime_bound',
                'delivery_sla_bound_count' => 'delivery_sla_bound',
                'strategic_intelligence_bound_count' => 'strategic_intelligence_bound',
                'rival_alternative_map_bound_count' => 'rival_alternative_map_bound',
                'grc_runtime_bound_count' => 'grc_runtime_bound',
                'audit_evidence_bound_count' => 'audit_evidence_bound',
                'policy_exception_blocked_count' => 'policy_exception_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'customer_visible_claim_allowed' => false,
                'policy_exception_auto_approval_allowed' => false,
                'delivery_risk_runtime_requires_sla_acceptance_rival_map_audit_evidence_and_grc_controls' => true,
                'operator_mandate_required_for_external_delivery_or_policy_exception' => true,
            ],
        ], $companyId);
    }
}
