<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

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
            'acceptance' => $this->list($input['acceptance_criteria'] ?? $input['acceptance'] ?? []),
            'context' => $this->list($input['context_refs'] ?? $input['context'] ?? []),
            'tests' => $this->list($input['tests'] ?? $input['suggested_tests'] ?? []),
            'contracts' => $this->list($input['contracts'] ?? $input['contract_refs'] ?? []),
            'docs' => $this->list($input['docs'] ?? $input['canonical_docs'] ?? []),
            'review' => $this->list($input['review'] ?? $input['review_refs'] ?? []),
            'evidence' => $this->list($input['evidence'] ?? $input['evidence_refs'] ?? []),
            'ux' => $this->list($input['ux_expectations'] ?? $input['prototype_refs'] ?? []),
        ];

        $blockers = $this->list($doctrine['blockers'] ?? []);
        $warnings = [];
        $next = [];
        $drivers = $this->list($doctrine['selected_primary_drivers'] ?? []);

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
        if (in_array((string) ($doctrine['risk_level'] ?? 'medium'), ['high', 'critical'], true) && $provided['review'] === []) {
            $warnings[] = 'risk_review_not_attached';
            $next[] = 'attach_risk_or_senior_review_before_sensitive_execution';
        }
        if (in_array('security_driven', $drivers, true) && $provided['review'] === []) {
            $blockers[] = 'missing_senior_review_for_sensitive_change';
            $next[] = 'attach_senior_security_or_runtime_review';
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

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'allowed_to_execute' => $status !== 'blocked',
            'doctrine_hash' => $doctrine['certification_hash'] ?? null,
            'selected_drivers' => $drivers,
            'required_gates' => $this->list($doctrine['required_gates'] ?? []),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'required_next_actions' => array_values(array_unique($next)),
            'doctrine' => $doctrine,
            'provided' => $provided,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
            ],
        ];
        $payload['hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== '')) : [];
    }
}
