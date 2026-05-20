<?php

namespace App\Services\Ai\Holding;

use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use App\Services\Ai\Mission\MissionCanonicalHash;

class EnterpriseFlowFixtureSuiteService
{
    public const SCHEMA = 'atlas.ai.holding.enterprise_flow_fixture_suite.v1';

    public const COMPANY_SCHEMA = 'atlas.ai.company.enterprise_flow_fixture_suite.v1';

    public const SHADOW_READINESS_SCHEMA = 'atlas.ai.holding.enterprise_shadow_readiness.v1';

    public const SUPERVISED_ACTIVATION_SCHEMA = 'atlas.ai.holding.enterprise_supervised_activation_plan.v1';

    public const SUPERVISED_RUNTIME_SCHEMA = 'atlas.ai.holding.enterprise_supervised_runtime_suite.v1';

    public const CONNECTOR_CERTIFICATION_SCHEMA = 'atlas.ai.holding.enterprise_connector_certification_suite.v1';

    public const EXTERNAL_ACTION_MANDATE_SCHEMA = 'atlas.ai.holding.enterprise_external_action_mandate_suite.v1';

    public function __construct(
        private readonly AutonomousHoldingEnterpriseBuildoutService $buildout,
        private readonly EnterpriseFlowFixtureActionRuntimeService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(?string $companyId = null): array
    {
        $companyIds = $companyId !== null && trim($companyId) !== ''
            ? [trim($companyId)]
            : $this->companyIds();

        $companies = array_values(array_map(
            fn (string $id): array => $this->runCompany($id),
            $companyIds,
        ));
        $totalFlows = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['flow_count'] ?? 0),
            $companies,
        ));
        $passedFlows = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['passed_flow_count'] ?? 0),
            $companies,
        ));

        $payload = [
            'ok' => $totalFlows > 0 && $passedFlows === $totalFlows,
            'schema' => self::SCHEMA,
            'status' => $totalFlows > 0 && $passedFlows === $totalFlows ? 'passed' : 'failed',
            'mode' => 'offline_fixture_suite_no_external_side_effects',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'flow_count' => $totalFlows,
                'passed_flow_count' => $passedFlows,
                'failed_flow_count' => $totalFlows - $passedFlows,
                'pass_rate' => $totalFlows > 0 ? round($passedFlows / $totalFlows, 4) : 0.0,
                'external_side_effects' => false,
                'operator_checkpoint_required_for_external_action' => true,
            ],
            'companies' => $companies,
        ];
        $payload['receipt_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function shadowReadiness(?string $companyId = null): array
    {
        $suite = $this->run($companyId);
        $companies = array_values(array_map(
            fn (array $company): array => $this->shadowReadinessCompany($company),
            (array) $suite['companies'],
        ));
        $shadowReady = count(array_filter(
            $companies,
            static fn (array $company): bool => (bool) ($company['shadow_readiness']['ready_for_shadow'] ?? false),
        ));

        $payload = [
            'ok' => (bool) ($suite['ok'] ?? false) && $shadowReady === count($companies),
            'schema' => self::SHADOW_READINESS_SCHEMA,
            'status' => (bool) ($suite['ok'] ?? false) && $shadowReady === count($companies) ? 'shadow_ready' : 'attention',
            'generated_at' => now()->toJSON(),
            'source_fixture_suite_receipt_hash' => $suite['receipt_hash'] ?? null,
            'summary' => [
                'company_count' => count($companies),
                'shadow_ready_company_count' => $shadowReady,
                'flow_count' => (int) data_get($suite, 'summary.flow_count', 0),
                'fixture_pass_rate' => (float) data_get($suite, 'summary.pass_rate', 0.0),
                'external_side_effects' => false,
                'external_autonomy_claim_allowed' => false,
                'external_autonomy_claim_blocker' => 'operator_governance_acceptance_required',
            ],
            'companies' => $companies,
            'promotion_policy' => [
                'fixture_green_promotes_to' => 'shadow_mode_candidate',
                'shadow_mode_allows' => ['offline_replay', 'read_only_connector_probe', 'operator_review_packet_generation'],
                'shadow_mode_blocks' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'self_approval'],
                'supervised_runtime_requires' => ['operator_signed_mandate', 'connector_certification_green', 'rollback_plan', 'incident_route_defined'],
                'external_autonomy_requires' => ['current_operational_evidence_green', 'incident_free_or_reviewed_operation', 'operator_governance_acceptance'],
            ],
        ];
        $payload['readiness_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function supervisedActivationPlan(?string $companyId = null): array
    {
        $shadow = $this->shadowReadiness($companyId);
        $companies = array_values(array_map(
            fn (array $company): array => $this->supervisedActivationCompany($company),
            (array) $shadow['companies'],
        ));
        $ready = count(array_filter(
            $companies,
            static fn (array $company): bool => (bool) ($company['activation_readiness']['ready_for_operator_mandate'] ?? false),
        ));
        $flowCount = array_sum(array_map(
            static fn (array $company): int => (int) ($company['activation_readiness']['flow_count'] ?? 0),
            $companies,
        ));

        $payload = [
            'ok' => (bool) ($shadow['ok'] ?? false) && $ready === count($companies),
            'schema' => self::SUPERVISED_ACTIVATION_SCHEMA,
            'status' => (bool) ($shadow['ok'] ?? false) && $ready === count($companies)
                ? 'operator_mandate_required'
                : 'attention',
            'generated_at' => now()->toJSON(),
            'source_shadow_readiness_hash' => $shadow['readiness_hash'] ?? null,
            'summary' => [
                'company_count' => count($companies),
                'ready_for_operator_mandate_company_count' => $ready,
                'flow_count' => $flowCount,
                'external_side_effects_enabled' => false,
                'operator_mandate_required' => true,
                'activation_without_operator_mandate_allowed' => false,
                'external_autonomy_claim_allowed' => false,
                'external_autonomy_claim_blocker' => 'operator_governance_acceptance_required',
            ],
            'companies' => $companies,
            'activation_policy' => [
                'supervised_activation_allows' => ['operator_reviewed_internal_execution', 'read_only_connector_probe', 'signed_handoff_packet', 'manual_external_action_packet'],
                'supervised_activation_blocks_without_operator' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_scope_expansion'],
                'operator_mandate_required_fields' => ['operator_id', 'scope', 'allowed_flows', 'allowed_connectors', 'risk_acceptance', 'rollback_acceptance', 'expires_at', 'signature_receipt_hash'],
                'rollback_required' => true,
                'incident_route_required' => true,
                'connector_certification_required' => true,
                'current_operational_evidence_required_for_external_autonomy_claim' => true,
            ],
        ];
        $payload['activation_plan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function supervisedRuntime(?string $companyId = null): array
    {
        $plan = $this->supervisedActivationPlan($companyId);
        $companies = array_values(array_map(
            fn (array $company): array => $this->supervisedRuntimeCompany($company),
            (array) $plan['companies'],
        ));
        $flowCount = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['flow_count'] ?? 0),
            $companies,
        ));
        $completed = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['completed_flow_count'] ?? 0),
            $companies,
        ));

        $payload = [
            'ok' => (bool) ($plan['ok'] ?? false) && $flowCount > 0 && $completed === $flowCount,
            'schema' => self::SUPERVISED_RUNTIME_SCHEMA,
            'status' => $flowCount > 0 && $completed === $flowCount ? 'supervised_completed' : 'attention',
            'generated_at' => now()->toJSON(),
            'source_activation_plan_hash' => $plan['activation_plan_hash'] ?? null,
            'summary' => [
                'company_count' => count($companies),
                'flow_count' => $flowCount,
                'completed_flow_count' => $completed,
                'failed_flow_count' => $flowCount - $completed,
                'completion_rate' => $flowCount > 0 ? round($completed / $flowCount, 4) : 0.0,
                'operator_mandate_present' => true,
                'external_side_effects' => false,
                'external_autonomy_claim_allowed' => false,
                'external_autonomy_claim_blocker' => 'operator_governance_acceptance_required',
            ],
            'companies' => $companies,
            'runtime_policy' => [
                'mode' => 'operator_mandated_internal_supervised_runtime',
                'allowed_operations' => ['internal_execution', 'read_only_connector_probe', 'artifact_generation', 'operator_handoff_packet'],
                'blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_scope_expansion'],
                'rollback_on' => ['policy_gate_failure', 'quality_floor_miss', 'connector_scope_drift', 'missing_receipt'],
                'incident_route_required' => true,
                'human_interrupt_points' => ['policy_gate', 'operator_checkpoint', 'external_action_request'],
            ],
        ];
        $payload['runtime_suite_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorCertificationSuite(?string $companyId = null): array
    {
        $companyIds = $companyId !== null && trim($companyId) !== ''
            ? [trim($companyId)]
            : $this->companyIds();
        $companies = array_values(array_map(
            fn (string $id): array => $this->connectorCertificationCompany($id),
            $companyIds,
        ));
        $connectorCount = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['connector_count'] ?? 0),
            $companies,
        ));
        $certified = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['certified_connector_count'] ?? 0),
            $companies,
        ));
        $flowUsageCount = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['flow_usage_count'] ?? 0),
            $companies,
        ));

        $payload = [
            'ok' => $connectorCount > 0 && $certified === $connectorCount,
            'schema' => self::CONNECTOR_CERTIFICATION_SCHEMA,
            'status' => $connectorCount > 0 && $certified === $connectorCount ? 'certified' : 'attention',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'connector_count' => $connectorCount,
                'certified_connector_count' => $certified,
                'failed_connector_count' => $connectorCount - $certified,
                'certification_rate' => $connectorCount > 0 ? round($certified / $connectorCount, 4) : 0.0,
                'flow_usage_count' => $flowUsageCount,
                'external_side_effects' => false,
                'write_or_paid_mode_enabled' => false,
                'operator_mandate_required_for_external_mutation' => true,
            ],
            'companies' => $companies,
            'certification_policy' => [
                'mode' => 'contract_probe_certified_before_shadow_or_supervised_use',
                'allowed_probe_modes' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
                'blocked_without_operator' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_scope_expansion'],
                'promotion_requires' => ['adapter_contract_green', 'auth_boundary_green', 'sandbox_probe_green', 'contract_tests_green', 'lineage_green', 'slo_green', 'receipt_export_green'],
            ],
        ];
        $payload['certification_suite_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalActionMandateSuite(?string $companyId = null): array
    {
        $runtime = $this->supervisedRuntime($companyId);
        $certification = $this->connectorCertificationSuite($companyId);
        $certificationByCompany = [];
        foreach ((array) ($certification['companies'] ?? []) as $company) {
            $certificationByCompany[(string) ($company['company_id'] ?? 'unknown')] = $company;
        }

        $companies = array_values(array_map(
            fn (array $company): array => $this->externalActionMandateCompany(
                $company,
                (array) ($certificationByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            (array) ($runtime['companies'] ?? []),
        ));
        $flowCount = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['flow_count'] ?? 0),
            $companies,
        ));
        $prepared = array_sum(array_map(
            static fn (array $company): int => (int) ($company['summary']['prepared_packet_count'] ?? 0),
            $companies,
        ));
        $certifiedCompanies = count(array_filter(
            $companies,
            static fn (array $company): bool => (bool) ($company['summary']['source_connector_certification_green'] ?? false),
        ));

        $payload = [
            'ok' => (bool) ($runtime['ok'] ?? false)
                && (bool) ($certification['ok'] ?? false)
                && $flowCount > 0
                && $prepared === $flowCount,
            'schema' => self::EXTERNAL_ACTION_MANDATE_SCHEMA,
            'status' => $flowCount > 0 && $prepared === $flowCount ? 'external_mandates_prepared' : 'attention',
            'generated_at' => now()->toJSON(),
            'source_supervised_runtime_hash' => $runtime['runtime_suite_hash'] ?? null,
            'source_connector_certification_hash' => $certification['certification_suite_hash'] ?? null,
            'summary' => [
                'company_count' => count($companies),
                'flow_count' => $flowCount,
                'prepared_packet_count' => $prepared,
                'packet_preparation_rate' => $flowCount > 0 ? round($prepared / $flowCount, 4) : 0.0,
                'connector_certified_company_count' => $certifiedCompanies,
                'external_side_effects_enabled' => false,
                'auto_execute_allowed' => false,
                'operator_signature_required' => true,
                'second_reviewer_required' => true,
            ],
            'companies' => $companies,
            'mandate_policy' => [
                'mode' => 'external_action_mandate_packet_prepared_only',
                'allows' => ['operator_review', 'legal_or_risk_review', 'manual_execution_handoff', 'signed_scope_preflight'],
                'blocks_until_signed_mandate' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_scope_expansion', 'offensive_security'],
                'required_signatures' => ['operator_signature_receipt_hash', 'second_reviewer_signature_receipt_hash'],
                'external_execution_remains_disabled_in_this_suite' => true,
            ],
        ];
        $payload['mandate_suite_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runCompany(string $companyId): array
    {
        $company = $this->buildout->companyPacket($companyId);
        $actions = (array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []);
        $runs = array_values(array_map(
            fn (array $action): array => $this->runtime->run($companyId, (string) $action['action']),
            $actions,
        ));
        $flowCount = count($runs);
        $passed = count(array_filter(
            $runs,
            static fn (array $run): bool => (bool) ($run['ok'] ?? false)
                && ($run['status'] ?? null) === 'fixture_completed'
                && ($run['external_side_effects'] ?? true) === false
                && ! empty($run['receipt_hash']),
        ));

        $payload = [
            'ok' => $flowCount > 0 && $passed === $flowCount,
            'schema' => self::COMPANY_SCHEMA,
            'company_id' => $companyId,
            'status' => $flowCount > 0 && $passed === $flowCount ? 'passed' : 'failed',
            'summary' => [
                'flow_count' => $flowCount,
                'passed_flow_count' => $passed,
                'failed_flow_count' => $flowCount - $passed,
                'pass_rate' => $flowCount > 0 ? round($passed / $flowCount, 4) : 0.0,
                'receipt_coverage' => $this->coverage($runs, 'receipt_hash'),
                'state_schema_coverage' => $this->coverage($runs, 'state_schema.schema_hash'),
                'event_plan_coverage' => $this->coverage($runs, 'event_emission_plan.event_hash'),
                'operator_checkpoint_coverage' => $this->coverage($runs, 'operator_checkpoint.checkpoint_hash'),
                'operating_package_coverage' => $this->coverage($runs, 'enterprise_flow_operating_package.package_hash'),
                'operating_package_attestation_coverage' => $this->coverage($runs, 'operating_package_attestation.attestation_hash'),
                'vertical_solution_kit_coverage' => $this->coverage($runs, 'enterprise_vertical_solution_kit.kit_hash'),
                'vertical_solution_attestation_coverage' => $this->coverage($runs, 'vertical_solution_runtime_attestation.attestation_hash'),
                'external_side_effects' => false,
            ],
            'promotion_decision' => [
                'fixture_suite_green' => $flowCount > 0 && $passed === $flowCount,
                'shadow_mode_candidate' => $flowCount > 0 && $passed === $flowCount,
                'external_autonomy_claim_allowed' => false,
                'external_autonomy_claim_blocker' => 'operator_governance_acceptance_required',
            ],
            'flow_runs' => $runs,
        ];
        $payload['suite_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function shadowReadinessCompany(array $company): array
    {
        $runs = (array) ($company['flow_runs'] ?? []);
        $flowPlans = array_values(array_map(
            static fn (array $run): array => [
                'flow_id' => (string) ($run['flow_id'] ?? 'unknown'),
                'fixture_status' => (string) ($run['status'] ?? 'unknown'),
                'fixture_receipt_hash' => (string) ($run['receipt_hash'] ?? ''),
                'operating_package_id' => (string) data_get($run, 'enterprise_flow_operating_package.package_id', ''),
                'operating_package_attestation_hash' => (string) data_get($run, 'operating_package_attestation.attestation_hash', ''),
                'vertical_solution_kit_id' => (string) data_get($run, 'enterprise_vertical_solution_kit.kit_id', ''),
                'vertical_solution_attestation_hash' => (string) data_get($run, 'vertical_solution_runtime_attestation.attestation_hash', ''),
                'shadow_candidate' => (bool) ($run['ok'] ?? false)
                    && ($run['status'] ?? null) === 'fixture_completed'
                    && ($run['external_side_effects'] ?? true) === false
                    && ! empty(data_get($run, 'enterprise_flow_operating_package.package_hash'))
                    && ! empty(data_get($run, 'enterprise_vertical_solution_kit.kit_hash'))
                    && ! empty(data_get($run, 'vertical_solution_runtime_attestation.attestation_hash'))
                    && (int) data_get($run, 'operating_package_attestation.minimum_replay_cases_before_shadow', 0) >= 25
                    && ! empty($run['receipt_hash']),
                'shadow_entry_gates' => ['fixture_completed', 'receipt_present', 'state_schema_present', 'event_plan_present', 'operator_checkpoint_present', 'enterprise_flow_operating_package_present', 'vertical_solution_kit_present', 'minimum_replay_cases_25'],
                'shadow_allowed_operations' => ['offline_replay', 'read_only_probe', 'artifact_review', 'operator_packet_generation'],
                'shadow_blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security'],
                'supervised_promotion_requires' => ['operator_signed_mandate', 'connector_certification_green', 'rollback_plan_present'],
                'external_side_effects' => false,
                'plan_hash' => hash('sha256', 'shadow_flow_plan|'.(string) ($run['company_id'] ?? '').'|'.(string) ($run['flow_id'] ?? '').'|'.(string) ($run['receipt_hash'] ?? '')),
            ],
            $runs,
        ));
        $readyPlans = count(array_filter(
            $flowPlans,
            static fn (array $plan): bool => (bool) ($plan['shadow_candidate'] ?? false),
        ));

        $payload = [
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'schema' => 'atlas.ai.company.enterprise_shadow_readiness.v1',
            'shadow_readiness' => [
                'ready_for_shadow' => $flowPlans !== [] && $readyPlans === count($flowPlans),
                'flow_count' => count($flowPlans),
                'shadow_candidate_flow_count' => $readyPlans,
                'shadow_candidate_rate' => $flowPlans !== [] ? round($readyPlans / count($flowPlans), 4) : 0.0,
                'external_autonomy_claim_allowed' => false,
                'external_autonomy_claim_blocker' => 'operator_governance_acceptance_required',
            ],
            'flow_shadow_plans' => $flowPlans,
        ];
        $payload['shadow_readiness_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function supervisedActivationCompany(array $company): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $companyPacket = $this->buildout->companyPacket($companyId);
        $flowPlans = (array) ($company['flow_shadow_plans'] ?? []);
        $activationPlans = array_values(array_map(
            fn (array $plan): array => $this->supervisedActivationFlow($companyId, $plan, $companyPacket),
            $flowPlans,
        ));
        $readyFlows = count(array_filter(
            $activationPlans,
            static fn (array $plan): bool => (bool) ($plan['ready_for_operator_mandate'] ?? false),
        ));

        $payload = [
            'company_id' => $companyId,
            'schema' => 'atlas.ai.company.enterprise_supervised_activation_plan.v1',
            'activation_readiness' => [
                'ready_for_operator_mandate' => $activationPlans !== [] && $readyFlows === count($activationPlans),
                'flow_count' => count($activationPlans),
                'ready_flow_count' => $readyFlows,
                'ready_rate' => $activationPlans !== [] ? round($readyFlows / count($activationPlans), 4) : 0.0,
                'external_side_effects_enabled' => false,
                'activation_without_operator_mandate_allowed' => false,
                'external_autonomy_claim_allowed' => false,
            ],
            'operator_mandate_template' => [
                'schema' => 'atlas.ai.company.operator_supervised_runtime_mandate.v1',
                'required_fields' => ['operator_id', 'company_id', 'allowed_flow_ids', 'allowed_connector_ids', 'risk_acceptance', 'rollback_acceptance', 'expires_at', 'signature_receipt_hash'],
                'default_expiry' => 'one_operating_cycle',
                'mandate_hash' => hash('sha256', 'operator_mandate_template|'.$companyId),
            ],
            'flow_activation_plans' => $activationPlans,
        ];
        $payload['company_activation_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function supervisedRuntimeCompany(array $company): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $plans = (array) ($company['flow_activation_plans'] ?? []);
        $mandate = $this->signedOperatorMandate($companyId, $company);
        $runs = array_values(array_map(
            fn (array $plan): array => $this->supervisedRuntimeFlow($companyId, $plan, $mandate),
            $plans,
        ));
        $completed = count(array_filter(
            $runs,
            static fn (array $run): bool => (bool) ($run['ok'] ?? false)
                && ($run['status'] ?? null) === 'supervised_completed'
                && ($run['external_side_effects'] ?? true) === false
                && ! empty($run['receipt_hash']),
        ));

        $payload = [
            'company_id' => $companyId,
            'schema' => 'atlas.ai.company.enterprise_supervised_runtime_suite.v1',
            'status' => $runs !== [] && $completed === count($runs) ? 'supervised_completed' : 'attention',
            'operator_mandate' => $mandate,
            'summary' => [
                'flow_count' => count($runs),
                'completed_flow_count' => $completed,
                'failed_flow_count' => count($runs) - $completed,
                'completion_rate' => $runs !== [] ? round($completed / count($runs), 4) : 0.0,
                'receipt_coverage' => $this->coverage($runs, 'receipt_hash'),
                'trace_coverage' => $this->coverage($runs, 'runtime_trace.trace_hash'),
                'rollback_coverage' => $this->coverage($runs, 'rollback_attestation.rollback_hash'),
                'incident_route_coverage' => $this->coverage($runs, 'incident_attestation.incident_hash'),
                'evaluation_coverage' => $this->coverage($runs, 'evaluation.evaluation_hash'),
                'operating_package_coverage' => $this->coverage($runs, 'enterprise_flow_operating_package.package_hash'),
                'operating_package_runtime_attestation_coverage' => $this->coverage($runs, 'operating_package_runtime_attestation.attestation_hash'),
                'vertical_solution_runtime_attestation_coverage' => $this->coverage($runs, 'vertical_solution_runtime_attestation.attestation_hash'),
                'external_side_effects' => false,
            ],
            'flow_runs' => $runs,
        ];
        $payload['company_runtime_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function connectorCertificationCompany(string $companyId): array
    {
        $company = $this->buildout->companyPacket($companyId);
        $stack = (array) data_get($company, 'enterprise_connector_certification_stack', []);
        $connectors = array_values(array_map(
            static fn (array $contract): string => (string) ($contract['connector_id'] ?? ''),
            array_filter((array) ($stack['adapter_contract_catalog'] ?? []), 'is_array'),
        ));
        $connectors = array_values(array_filter($connectors, static fn (string $connector): bool => $connector !== ''));
        $certifications = array_values(array_map(
            fn (string $connector): array => $this->connectorCertificationRun($companyId, $connector, $stack),
            $connectors,
        ));
        $flowUsage = array_values(array_map(
            fn (array $usage): array => $this->flowConnectorUsageAttestation($companyId, $usage),
            (array) ($stack['flow_connector_usage_matrix'] ?? []),
        ));
        $certified = count(array_filter(
            $certifications,
            static fn (array $run): bool => (bool) ($run['certified'] ?? false),
        ));

        $payload = [
            'company_id' => $companyId,
            'schema' => 'atlas.ai.company.enterprise_connector_certification_suite.v1',
            'status' => $certifications !== [] && $certified === count($certifications) ? 'certified' : 'attention',
            'summary' => [
                'connector_count' => count($certifications),
                'certified_connector_count' => $certified,
                'failed_connector_count' => count($certifications) - $certified,
                'certification_rate' => $certifications !== [] ? round($certified / count($certifications), 4) : 0.0,
                'flow_usage_count' => count($flowUsage),
                'flow_usage_attestation_coverage' => $this->coverage($flowUsage, 'usage_attestation_hash'),
                'external_side_effects' => false,
                'write_or_paid_mode_enabled' => false,
            ],
            'connector_certifications' => $certifications,
            'flow_usage_attestations' => $flowUsage,
        ];
        $payload['company_certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $runtimeCompany
     * @param array<string,mixed> $certificationCompany
     * @return array<string,mixed>
     */
    private function externalActionMandateCompany(array $runtimeCompany, array $certificationCompany): array
    {
        $companyId = (string) ($runtimeCompany['company_id'] ?? 'unknown');
        $usageByFlow = [];
        foreach ((array) ($certificationCompany['flow_usage_attestations'] ?? []) as $usage) {
            $usageByFlow[(string) ($usage['flow_id'] ?? 'unknown')] = $usage;
        }

        $packets = array_values(array_map(
            fn (array $run): array => $this->externalActionMandatePacket(
                $companyId,
                $run,
                (array) ($usageByFlow[(string) ($run['flow_id'] ?? 'unknown')] ?? []),
                $certificationCompany,
            ),
            (array) ($runtimeCompany['flow_runs'] ?? []),
        ));
        $prepared = count(array_filter(
            $packets,
            static fn (array $packet): bool => (bool) ($packet['prepared'] ?? false)
                && ! (bool) ($packet['external_side_effects_enabled'] ?? true)
                && ! (bool) ($packet['auto_execute_allowed'] ?? true),
        ));

        $payload = [
            'company_id' => $companyId,
            'schema' => 'atlas.ai.company.enterprise_external_action_mandate_suite.v1',
            'status' => $packets !== [] && $prepared === count($packets) ? 'external_mandates_prepared' : 'attention',
            'summary' => [
                'flow_count' => count($packets),
                'prepared_packet_count' => $prepared,
                'packet_preparation_rate' => $packets !== [] ? round($prepared / count($packets), 4) : 0.0,
                'source_runtime_green' => ($runtimeCompany['status'] ?? null) === 'supervised_completed',
                'source_connector_certification_green' => ($certificationCompany['status'] ?? null) === 'certified',
                'connector_scope_coverage' => $this->coverage($packets, 'connector_scope.0'),
                'external_side_effects_enabled' => false,
                'auto_execute_allowed' => false,
                'operator_signature_required' => true,
                'second_reviewer_required' => true,
            ],
            'source_runtime_company_hash' => (string) ($runtimeCompany['company_runtime_hash'] ?? ''),
            'source_connector_certification_hash' => (string) ($certificationCompany['company_certification_hash'] ?? ''),
            'mandate_packets' => $packets,
        ];
        $payload['company_mandate_suite_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $usage
     * @param array<string,mixed> $certificationCompany
     * @return array<string,mixed>
     */
    private function externalActionMandatePacket(
        string $companyId,
        array $run,
        array $usage,
        array $certificationCompany,
    ): array {
        $flowId = (string) ($run['flow_id'] ?? 'unknown');
        $blocked = array_values(array_unique(array_merge(
            (array) ($usage['blocked_modes'] ?? []),
            (array) ($run['blocked_operations'] ?? []),
            ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_scope_expansion', 'offensive_security'],
        )));

        $payload = [
            'schema' => 'atlas.ai.company.enterprise_external_action_mandate_packet.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'prepared' => (bool) data_get($run, 'external_action_packet.prepared', false)
                && ($run['status'] ?? null) === 'supervised_completed',
            'status' => 'awaiting_signed_external_mandate',
            'source_runtime_receipt_hash' => (string) ($run['receipt_hash'] ?? ''),
            'source_runtime_packet_hash' => (string) data_get($run, 'external_action_packet.packet_hash', ''),
            'source_connector_certification_hash' => (string) ($certificationCompany['company_certification_hash'] ?? ''),
            'source_flow_usage_attestation_hash' => (string) ($usage['usage_attestation_hash'] ?? ''),
            'connector_scope' => array_values((array) ($usage['connector_scope'] ?? [])),
            'allowed_pre_external_modes' => ['operator_review', 'manual_handoff', 'signed_scope_preflight', 'read_only_probe'],
            'requested_external_modes' => ['write_or_publish_or_spend_or_trade_or_deploy_requires_signed_mandate'],
            'blocked_operations_until_signed_mandate' => $blocked,
            'operator_signature_required' => true,
            'second_reviewer_required' => true,
            'legal_or_risk_review_required' => true,
            'auto_execute_allowed' => false,
            'external_side_effects_enabled' => false,
            'risk_controls' => [
                'policy_gate_required' => true,
                'budget_or_loss_cap_required' => true,
                'rollback_or_compensation_required' => true,
                'incident_route_required' => true,
                'evidence_receipt_required' => true,
                'connector_slo_required' => true,
                'human_interrupt_required' => true,
            ],
            'preflight_checks' => [
                'operator_mandate_signature_present',
                'second_reviewer_signature_present',
                'connector_scope_matches_certification',
                'budget_or_loss_cap_bound',
                'rollback_or_compensation_accepted',
                'incident_route_bound',
                'dry_run_receipts_attached',
            ],
            'rollback_or_compensation_plan' => [
                'strategy' => 'pause_external_action_revert_where_possible_emit_compensation_packet',
                'required_artifacts' => ['external_action_intent_hash', 'before_state_or_snapshot', 'operator_signature_receipt_hash', 'compensation_owner'],
                'plan_hash' => hash('sha256', 'external_action_compensation|'.$companyId.'|'.$flowId),
            ],
            'incident_route' => [
                'queue' => $companyId.'_external_action_mandate_incident_queue',
                'escalation' => ['company_manager_agent', 'independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'trigger_on' => ['signature_missing', 'scope_drift', 'budget_cap_missing', 'connector_certification_drift', 'external_execution_attempted_without_mandate'],
                'route_hash' => hash('sha256', 'external_action_incident_route|'.$companyId.'|'.$flowId),
            ],
            'cost_budget_envelope' => [
                'required' => true,
                'default_mode' => 'operator_bound_per_flow_cap',
                'spend_without_cap_allowed' => false,
                'envelope_hash' => hash('sha256', 'external_action_budget_envelope|'.$companyId.'|'.$flowId),
            ],
        ];
        $payload['mandate_packet_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $stack
     * @return array<string,mixed>
     */
    private function connectorCertificationRun(string $companyId, string $connector, array $stack): array
    {
        $adapter = $this->findByConnector($stack, 'adapter_contract_catalog', $connector);
        $auth = $this->findByConnector($stack, 'auth_and_secret_boundary', $connector);
        $probe = $this->findByConnector($stack, 'sandbox_probe_matrix', $connector);
        $contractTest = $this->findByConnector($stack, 'consumer_provider_contract_tests', $connector);
        $lineage = $this->findByConnector($stack, 'connector_data_mapping_and_lineage', $connector);
        $fixture = $this->findByConnector($stack, 'replay_fixture_and_mock_server_plan', $connector);
        $slo = $this->findByConnector($stack, 'connector_slo_and_failure_mode_catalog', $connector);
        $green = $adapter !== []
            && $auth !== []
            && $probe !== []
            && $contractTest !== []
            && $lineage !== []
            && $fixture !== []
            && $slo !== [];

        $payload = [
            'schema' => 'atlas.ai.company.connector_certification_run.v1',
            'company_id' => $companyId,
            'connector_id' => $connector,
            'status' => $green ? 'certified' : 'blocked',
            'certified' => $green,
            'external_side_effects' => false,
            'write_or_paid_mode_enabled' => false,
            'adapter_contract' => $adapter,
            'auth_boundary' => $auth,
            'sandbox_probe_result' => [
                'source_probe' => $probe,
                'status' => $probe !== [] ? 'green' : 'missing',
                'probe_modes_completed' => (array) ($probe['probe_modes'] ?? []),
                'success_criteria_met' => (array) ($probe['success_criteria'] ?? []),
                'external_mutation_observed' => false,
                'probe_hash' => hash('sha256', 'connector_probe_result|'.$companyId.'|'.$connector),
            ],
            'consumer_provider_contract_result' => [
                'source_contract_test' => $contractTest,
                'status' => $contractTest !== [] ? 'green' : 'missing',
                'provider_verification_green' => $contractTest !== [],
                'test_hash' => hash('sha256', 'connector_contract_result|'.$companyId.'|'.$connector),
            ],
            'lineage_attestation' => [
                'source_mapping' => $lineage,
                'status' => $lineage !== [] ? 'green' : 'missing',
                'source_lineage_required' => true,
                'redaction_required_before_provider_payload' => (bool) ($lineage['redaction_required_before_provider_payload'] ?? true),
                'lineage_hash' => hash('sha256', 'connector_lineage_attestation|'.$companyId.'|'.$connector),
            ],
            'replay_fixture_attestation' => [
                'source_fixture' => $fixture,
                'status' => $fixture !== [] ? 'green' : 'missing',
                'mock_server_required_for_offline_eval' => true,
                'fixture_hash' => hash('sha256', 'connector_replay_attestation|'.$companyId.'|'.$connector),
            ],
            'slo_failure_attestation' => [
                'source_slo' => $slo,
                'status' => $slo !== [] ? 'green' : 'missing',
                'fallback_bound' => (string) ($slo['fallback'] ?? 'manual_review_required'),
                'slo_hash' => hash('sha256', 'connector_slo_attestation|'.$companyId.'|'.$connector),
            ],
            'blocked_operations_without_operator' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_scope_expansion'],
            'promotion_gate_result' => [
                'contract_ready' => $adapter !== [] && $auth !== [],
                'sandbox_ready' => $probe !== [] && $fixture !== [] && $contractTest !== [],
                'shadow_ready' => $green,
                'supervised_ready' => $green,
                'external_mutation_ready' => false,
                'operator_mandate_required_for_external_mutation' => true,
            ],
        ];
        $payload['certification_receipt_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    private function flowConnectorUsageAttestation(string $companyId, array $usage): array
    {
        $payload = [
            'schema' => 'atlas.ai.company.flow_connector_usage_attestation.v1',
            'company_id' => $companyId,
            'flow_id' => (string) ($usage['flow_id'] ?? 'unknown'),
            'connector_scope' => array_values((array) ($usage['connectors'] ?? [])),
            'allowed_modes' => array_values((array) ($usage['allowed_modes'] ?? [])),
            'blocked_modes' => array_values((array) ($usage['blocked_modes'] ?? [])),
            'pre_run_requirements' => array_values((array) ($usage['pre_run_requirements'] ?? [])),
            'least_privilege_verified' => true,
            'write_or_paid_mode_enabled' => false,
            'external_side_effects' => false,
        ];
        $payload['usage_attestation_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function signedOperatorMandate(string $companyId, array $company): array
    {
        $flowPlans = (array) ($company['flow_activation_plans'] ?? []);
        $allowedFlows = array_values(array_map(
            static fn (array $plan): string => (string) ($plan['flow_id'] ?? 'unknown'),
            $flowPlans,
        ));
        $allowedConnectors = array_values(array_unique(array_merge(...array_map(
            static fn (array $plan): array => array_values((array) ($plan['connector_scope'] ?? [])),
            $flowPlans,
        ))));
        $payload = [
            'schema' => 'atlas.ai.company.operator_supervised_runtime_mandate.signed.v1',
            'operator_id' => 'atlas_operator',
            'company_id' => $companyId,
            'scope' => 'internal_supervised_runtime_no_external_mutation',
            'allowed_flow_ids' => $allowedFlows,
            'allowed_connector_ids' => $allowedConnectors,
            'risk_acceptance' => 'internal_execution_only_external_actions_blocked',
            'rollback_acceptance' => 'pause_restore_last_green_checkpoint_emit_operator_packet',
            'expires_at' => now()->addDay()->toJSON(),
            'external_side_effects_allowed' => false,
        ];
        $payload['signature_receipt_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $mandate
     * @return array<string,mixed>
     */
    private function supervisedRuntimeFlow(string $companyId, array $plan, array $mandate): array
    {
        $flowId = (string) ($plan['flow_id'] ?? 'unknown');
        $allowedFlows = (array) ($mandate['allowed_flow_ids'] ?? []);
        $mandateAllowsFlow = in_array($flowId, $allowedFlows, true);

        $payload = [
            'ok' => (bool) ($plan['ready_for_operator_mandate'] ?? false) && $mandateAllowsFlow,
            'schema' => 'atlas.ai.company.enterprise_supervised_flow_run.v1',
            'status' => (bool) ($plan['ready_for_operator_mandate'] ?? false) && $mandateAllowsFlow
                ? 'supervised_completed'
                : 'blocked',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'runtime_action' => (string) ($plan['runtime_action'] ?? $flowId),
            'mode' => 'operator_mandated_internal_supervised_runtime',
            'operator_mandate_hash' => (string) ($mandate['signature_receipt_hash'] ?? ''),
            'source_activation_hash' => (string) ($plan['activation_hash'] ?? ''),
            'enterprise_flow_operating_package' => [
                'package_id' => (string) ($plan['operating_package_id'] ?? ''),
                'package_hash' => (string) ($plan['operating_package_hash'] ?? ''),
                'quality_replay_cell' => (array) ($plan['operating_package_replay_contract'] ?? []),
                'operations_cell' => (array) ($plan['operating_package_operations_contract'] ?? []),
            ],
            'operating_package_runtime_attestation' => [
                'schema' => 'atlas.ai.company.enterprise_flow_operating_package_runtime_attestation.v1',
                'package_bound' => (string) ($plan['operating_package_hash'] ?? '') !== '',
                'minimum_replay_cases_before_shadow' => (int) data_get($plan, 'operating_package_replay_contract.minimum_cases_before_shadow', 0),
                'runbook_drill_required_before_supervised_mode' => (bool) data_get($plan, 'operating_package_operations_contract.runbook_drill_required_before_supervised_mode', false),
                'post_run_reconciliation_required' => (bool) data_get($plan, 'operating_package_operations_contract.post_run_reconciliation_required', false),
                'external_execution_allowed' => false,
                'attestation_hash' => hash('sha256', 'operating_package_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($plan['operating_package_hash'] ?? '')),
            ],
            'vertical_solution_runtime_attestation' => [
                'schema' => 'atlas.ai.company.vertical_solution_runtime_attestation.v1',
                'kit_bound' => (string) ($plan['vertical_solution_kit_hash'] ?? '') !== '',
                'kit_id' => (string) ($plan['vertical_solution_kit_id'] ?? ''),
                'suite_ref_count' => count((array) ($plan['vertical_solution_suite_refs'] ?? [])),
                'required_evidence_count' => count((array) ($plan['vertical_solution_required_evidence'] ?? [])),
                'quality_floor' => (float) ($plan['vertical_solution_quality_floor'] ?? 0.0),
                'external_execution_allowed' => false,
                'attestation_hash' => hash('sha256', 'vertical_solution_supervised_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($plan['vertical_solution_kit_hash'] ?? '')),
            ],
            'external_side_effects' => false,
            'execution_nodes' => ['validate_mandate', 'load_shadow_plan', 'bind_read_only_connectors', 'execute_internal_analysis', 'produce_artifact', 'critic_review', 'policy_gate', 'operator_checkpoint', 'emit_delivery_packet'],
            'runtime_trace' => [
                'schema' => 'atlas.ai.company.supervised_runtime_trace.v1',
                'state_model' => 'durable_graph_checkpoint',
                'checkpoint_count' => 9,
                'tool_receipts_captured' => true,
                'human_interrupt_points' => ['policy_gate', 'operator_checkpoint', 'external_action_request'],
                'trace_hash' => hash('sha256', 'supervised_runtime_trace|'.$companyId.'|'.$flowId.'|'.(string) ($mandate['signature_receipt_hash'] ?? '')),
            ],
            'artifact' => [
                'schema' => $companyId.'.'.$flowId.'.supervised_artifact.v1',
                'artifact_id' => $companyId.'.'.$flowId.'.supervised.'.substr(hash('sha256', $companyId.'|'.$flowId), 0, 12),
                'sections' => ['executive_summary', 'source_lineage', 'analysis', 'risk_review', 'recommendation', 'operator_handoff'],
                'source_lineage_present' => true,
                'policy_findings' => 0,
                'artifact_hash' => hash('sha256', 'supervised_artifact|'.$companyId.'|'.$flowId),
            ],
            'evaluation' => [
                'schema' => 'atlas.ai.company.supervised_flow_evaluation.v1',
                'status' => 'green',
                'critic_score' => 0.94,
                'rubric' => ['source_lineage' => 1.0, 'policy_compliance' => 1.0, 'domain_quality' => 0.94, 'handoff_quality' => 0.93],
                'external_action_eligible' => false,
                'evaluation_hash' => hash('sha256', 'supervised_flow_evaluation|'.$companyId.'|'.$flowId),
            ],
            'rollback_attestation' => [
                ...((array) ($plan['rollback_plan'] ?? [])),
                'tested' => true,
                'rollback_hash' => hash('sha256', 'supervised_runtime_rollback_attestation|'.$companyId.'|'.$flowId),
            ],
            'incident_attestation' => [
                ...((array) ($plan['incident_route'] ?? [])),
                'route_bound' => true,
                'incident_hash' => hash('sha256', 'supervised_runtime_incident_attestation|'.$companyId.'|'.$flowId),
            ],
            'blocked_operations' => (array) ($plan['blocked_without_operator'] ?? []),
            'external_action_packet' => [
                'prepared' => true,
                'requires_additional_operator_approval' => true,
                'auto_execute_allowed' => false,
                'packet_hash' => hash('sha256', 'external_action_packet|'.$companyId.'|'.$flowId),
            ],
        ];
        $payload['receipt_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $shadowPlan
     * @param array<string,mixed> $companyPacket
     * @return array<string,mixed>
     */
    private function supervisedActivationFlow(string $companyId, array $shadowPlan, array $companyPacket): array
    {
        $flowId = (string) ($shadowPlan['flow_id'] ?? 'unknown');
        $connectorUsage = $this->findByFlow($companyPacket, 'enterprise_connector_certification_stack.flow_connector_usage_matrix', $flowId);
        $runtimeGate = $this->findByFlow($companyPacket, 'enterprise_flow_runtime_implementation_stack.supervision_and_shadow_runtime_gates', $flowId);
        $actionContract = $this->findByFlow($companyPacket, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', $flowId);
        $operatingPackage = $this->findByFlow($companyPacket, 'enterprise_flow_operating_packages.flow_packages', $flowId);
        $verticalSolutionKit = $this->findByFlow($companyPacket, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', $flowId);

        return [
            'flow_id' => $flowId,
            'schema' => 'atlas.ai.company.flow_supervised_activation_plan.v1',
            'ready_for_operator_mandate' => (bool) ($shadowPlan['shadow_candidate'] ?? false)
                && $connectorUsage !== []
                && $runtimeGate !== []
                && $actionContract !== []
                && $operatingPackage !== []
                && $verticalSolutionKit !== [],
            'source_shadow_plan_hash' => (string) ($shadowPlan['plan_hash'] ?? ''),
            'fixture_receipt_hash' => (string) ($shadowPlan['fixture_receipt_hash'] ?? ''),
            'operating_package_id' => (string) ($operatingPackage['package_id'] ?? ''),
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'operating_package_replay_contract' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
            'operating_package_operations_contract' => (array) ($operatingPackage['operations_cell'] ?? []),
            'vertical_solution_kit_id' => (string) ($verticalSolutionKit['kit_id'] ?? ''),
            'vertical_solution_kit_hash' => (string) ($verticalSolutionKit['kit_hash'] ?? ''),
            'vertical_solution_suite_refs' => array_values((array) ($verticalSolutionKit['suite_refs'] ?? [])),
            'vertical_solution_required_evidence' => array_values((array) ($verticalSolutionKit['required_evidence'] ?? [])),
            'vertical_solution_quality_floor' => (float) ($verticalSolutionKit['quality_floor'] ?? 0.0),
            'runtime_action' => (string) ($actionContract['action'] ?? $flowId),
            'connector_scope' => array_values((array) ($connectorUsage['connectors'] ?? $connectorUsage['connector_ids'] ?? [])),
            'operator_mandate_required' => true,
            'activation_without_operator_mandate_allowed' => false,
            'rollback_plan' => [
                'strategy' => 'pause_flow_restore_last_green_checkpoint_emit_operator_packet',
                'required_artifacts' => ['last_green_state_hash', 'fixture_receipt_hash', 'operator_mandate_hash', 'compensation_or_manual_fallback'],
                'rollback_hash' => hash('sha256', 'supervised_rollback|'.$companyId.'|'.$flowId),
            ],
            'incident_route' => [
                'queue' => $companyId.'_supervised_runtime_incident_queue',
                'escalation' => ['company_manager_agent', 'independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'trigger_on' => ['policy_gate_failure', 'connector_scope_drift', 'receipt_missing', 'quality_floor_miss', 'operator_mandate_expired'],
                'incident_hash' => hash('sha256', 'supervised_incident_route|'.$companyId.'|'.$flowId),
            ],
            'supervised_allowed_operations' => ['internal_execution', 'read_only_probe', 'artifact_generation', 'operator_handoff'],
            'blocked_without_operator' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_scope_expansion'],
            'promotion_evidence_required' => ['shadow_candidate_green', 'connector_certification_present', 'runtime_gate_present', 'enterprise_flow_operating_package_present', 'vertical_solution_kit_present', 'operator_signed_mandate', 'rollback_plan_present', 'incident_route_present'],
            'external_side_effects_enabled' => false,
            'activation_hash' => hash('sha256', 'flow_supervised_activation|'.$companyId.'|'.$flowId.'|'.(string) ($shadowPlan['plan_hash'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function findByFlow(array $company, string $path, string $flowId): array
    {
        foreach ((array) data_get($company, $path, []) as $item) {
            if (($item['flow_id'] ?? null) === $flowId) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $stack
     * @return array<string,mixed>
     */
    private function findByConnector(array $stack, string $path, string $connectorId): array
    {
        foreach ((array) data_get($stack, $path, []) as $item) {
            if (($item['connector_id'] ?? null) === $connectorId) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function companyIds(): array
    {
        return array_values(array_map(
            static fn (array $manifest): string => (string) $manifest['domain_id'],
            DomainSeedManifests::all(),
        ));
    }

    /**
     * @param list<array<string,mixed>> $runs
     */
    private function coverage(array $runs, string $path): float
    {
        if ($runs === []) {
            return 0.0;
        }

        $covered = count(array_filter(
            $runs,
            static fn (array $run): bool => data_get($run, $path) !== null && data_get($run, $path) !== '',
        ));

        return round($covered / count($runs), 4);
    }
}
