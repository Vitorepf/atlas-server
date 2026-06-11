<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;

class AtlasExecutionDoctrineGateService
{
    public const SCHEMA_VERSION = 'atlas.aedpds.execution_gate.v1';

    public function __construct(
        private readonly AtlasExecutionDoctrineRuntimeService $runtime = new AtlasExecutionDoctrineRuntimeService,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $doctrine = isset($input['doctrine']) && is_array($input['doctrine'])
            ? $input['doctrine']
            : $this->runtime->select($input);

        $provided = [
            'acceptance' => AiStringListNormalizer::trimmedScalarValues($input['acceptance_criteria'] ?? $input['acceptance'] ?? []),
            'context' => AiStringListNormalizer::trimmedScalarValues($input['context_refs'] ?? $input['context'] ?? []),
            'tests' => AiStringListNormalizer::trimmedScalarValues($input['tests'] ?? $input['suggested_tests'] ?? []),
            'contracts' => AiStringListNormalizer::trimmedScalarValues($input['contracts'] ?? $input['contract_refs'] ?? []),
            'docs' => AiStringListNormalizer::trimmedScalarValues($input['docs'] ?? $input['canonical_docs'] ?? []),
            'review' => AiStringListNormalizer::trimmedScalarValues($input['review'] ?? $input['review_refs'] ?? []),
            'evidence' => AiStringListNormalizer::trimmedScalarValues($input['evidence'] ?? $input['evidence_refs'] ?? []),
            'ux' => AiStringListNormalizer::trimmedScalarValues($input['ux_expectations'] ?? $input['prototype_refs'] ?? []),
        ];

        $blockers = AiStringListNormalizer::trimmedScalarValues($doctrine['blockers'] ?? []);
        $warnings = [];
        $next = [];
        $drivers = AiStringListNormalizer::trimmedScalarValues($doctrine['selected_primary_drivers'] ?? []);
        $secondaryDrivers = AiStringListNormalizer::trimmedScalarValues($doctrine['selected_secondary_drivers'] ?? []);
        $allDrivers = array_values(array_unique(array_merge($drivers, $secondaryDrivers)));

        if ($provided['review'] !== []) {
            $blockers = array_values(array_diff($blockers, ['sensitive_change_requires_senior_review']));
        }

        if (($doctrine['task_type'] ?? null) === null || (string) ($doctrine['task_type'] ?? '') === '') {
            $blockers[] = 'missing_task_type';
            $next[] = 'classify_task_type';
        }
        if ($drivers === []) {
            $blockers[] = 'missing_selected_driver';
            $next[] = 'run_aedpds_selector';
        }
        if (AiStringListNormalizer::trimmedScalarValues($doctrine['required_context'] ?? []) !== [] && $provided['context'] === []) {
            $blockers[] = 'missing_minimum_context_ref';
            $next[] = 'attach_owner_doc_or_relevant_context_ref';
        }
        if (in_array('atdd', $drivers, true) && $provided['acceptance'] === []) {
            $blockers[] = 'missing_acceptance_criteria';
            $next[] = 'define_acceptance_criteria';
        }
        if (in_array('tdd', $drivers, true) && $provided['tests'] === []) {
            $blockers[] = 'missing_test_plan';
            $next[] = 'define_focused_tests_or_verification_command';
        }
        if ((in_array('cdd', $drivers, true) || in_array('api_first', $drivers, true)) && $provided['contracts'] === []) {
            $blockers[] = 'missing_contract_or_schema';
            $next[] = 'attach_api_payload_or_integration_contract';
        }
        if (in_array('ux_driven', $drivers, true) && $provided['ux'] === []) {
            $blockers[] = 'missing_ux_expectation_or_prototype';
            $next[] = 'attach_ux_expectation_or_prototype';
        }
        if ((in_array('documentation_driven', $drivers, true) || in_array('sdd', $drivers, true)) && $provided['docs'] === []) {
            $blockers[] = 'missing_owner_doc_or_docs_health';
            $next[] = 'attach_canonical_doc_or_docs_health_result';
        }
        if (in_array('readme_driven', $allDrivers, true) && $provided['docs'] === []) {
            $blockers[] = 'missing_readme_or_usage_contract';
            $next[] = 'attach_readme_or_usage_contract';
        }
        if (in_array('model_driven', $drivers, true) && ($provided['contracts'] === [] || $provided['context'] === [])) {
            $blockers[] = 'missing_model_or_state_machine_contract';
            $next[] = 'attach_model_or_state_machine_contract';
        }
        if (in_array('reliability_observability_driven', $drivers, true) && $provided['evidence'] === []) {
            $blockers[] = 'missing_observability_readiness_evidence';
            $next[] = 'attach_logs_traces_receipts_or_readiness_signal';
        }
        if (in_array((string) ($doctrine['risk_level'] ?? 'medium'), ['high', 'critical'], true) && $provided['review'] === []) {
            $warnings[] = 'risk_review_not_attached';
            $next[] = 'attach_risk_or_senior_review_before_sensitive_execution';
        }
        $sensitiveSecurityChange = (bool) data_get($doctrine, 'signals.sensitive_security_change', false)
            || in_array('sensitive_change_requires_senior_review', $blockers, true);
        if (in_array('security_driven', $drivers, true) && $provided['review'] === []) {
            if ($sensitiveSecurityChange) {
                $blockers[] = 'missing_senior_review_for_sensitive_change';
                $next[] = 'attach_senior_security_or_runtime_review';
            } else {
                $warnings[] = 'security_review_not_attached';
                $next[] = 'attach_senior_security_or_runtime_review_if_scope_touches_auth_state';
            }
        }
        if (in_array('sensitive_change_requires_senior_review', $blockers, true)) {
            $next[] = 'attach_senior_security_or_runtime_review';
        }
        if ($provided['evidence'] === []) {
            $warnings[] = 'evidence_output_not_attached_yet';
            $next[] = 'record_evidence_refs_after_execution';
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $status = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'warning' : 'passed');
        $effectiveDoctrine = $doctrine;
        $effectiveDoctrine['blockers'] = $blockers;
        $effectiveDoctrine['warnings'] = $warnings;
        $effectiveDoctrine['allowed_to_execute'] = $status !== 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'allowed_to_execute' => $status !== 'blocked',
            'doctrine_hash' => $doctrine['certification_hash'] ?? null,
            'selected_drivers' => $drivers,
            'required_gates' => AiStringListNormalizer::trimmedScalarValues($doctrine['required_gates'] ?? []),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'required_next_actions' => array_values(array_unique($next)),
            'doctrine' => $effectiveDoctrine,
            'provided' => $provided,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
            ],
        ];
        $payload['hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

}
