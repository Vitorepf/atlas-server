<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_receipt_fixture_lab.v1';

    public const MODE = 'read_only_operator_evidence_receipt_fixture_lab';

    private const HASH_FIELDS_RUNTIME_RECEIPT = [
        'runtime_gap_matrix_hash',
        'runtime_promotion_basis_hash',
        'runtime_promotion_closure_basis_hash',
        'receipt_hash',
    ];

    private const HASH_FIELDS_REAL_PROVIDER_SMOKE = [
        'smoke_hash',
        'operator_approval_receipt_hash',
        'evidence_ledger_hash',
        'work_product_manifest_hash',
        'cost_event_hash',
        'continuation_summary_hash',
        'provider_response_hash',
    ];

    private const HASH_FIELDS_COMPLETION_RECEIPT = [
        'completion_audit_hash',
        'release_dossier_hash',
        'replay_diff_hash',
        'runtime_gap_matrix_hash',
        'runtime_promotion_receipt_hash',
        'real_provider_smoke_hash',
        'certification_status_batch_hash',
        'receipt_hash',
    ];

    private const REQUIRED_FIELDS_RUNTIME_RECEIPT = [
        'receipt_id',
        'signed_by',
        'reason',
        'runtime_gap_matrix_hash',
        'runtime_promotion_basis_hash',
        'runtime_promotion_closure_basis_hash',
        'receipt_hash',
    ];

    private const REQUIRED_FIELDS_REAL_PROVIDER_SMOKE = [
        'kind',
        'status',
        'provider_run_id',
        'task_packet_id',
        'observed_by',
        'approval_reason',
        'smoke_hash',
        'operator_approval_receipt_hash',
        'evidence_ledger_hash',
        'work_product_manifest_hash',
        'cost_event_hash',
        'continuation_summary_hash',
        'provider_response_hash',
    ];

    private const REQUIRED_FIELDS_COMPLETION_RECEIPT = [
        'receipt_id',
        'signed_by',
        'reason',
        'completion_audit_hash',
        'release_dossier_hash',
        'replay_diff_hash',
        'runtime_gap_matrix_hash',
        'runtime_promotion_receipt_hash',
        'real_provider_smoke_hash',
        'certification_status_batch_hash',
        'receipt_hash',
    ];

    private const FORBIDDEN_FLAGS_RUNTIME_RECEIPT = [
        'execution_allowed',
        'dispatch_allowed',
        'provider_call_allowed',
        'token_spend_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
    ];

    private const FORBIDDEN_FLAGS_REAL_PROVIDER_SMOKE = [
        'provider_called_by_atlas',
        'token_spent_by_atlas',
        'dispatch_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
        'completion_claim_promoted_without_receipt',
    ];

    private const FORBIDDEN_FLAGS_COMPLETION_RECEIPT = [
        'execution_allowed',
        'dispatch_allowed',
        'provider_call_allowed',
        'token_spend_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
        'completion_autopromoted',
    ];

    private const PLACEHOLDER_SIGNERS = [
        '',
        '<operator>',
        'operator',
        'human',
        'codex',
        'assistant',
        'system',
        'claude',
        'codex-autosigned',
        'atlas',
    ];

    public function __construct(
        private readonly AtlasSelfConstructionCompletionEvidenceHashService $hashes = new AtlasSelfConstructionCompletionEvidenceHashService,
        private readonly AtlasSelfConstructionRuntimePromotionReceiptService $runtimeReceiptVerifier = new AtlasSelfConstructionRuntimePromotionReceiptService,
        private readonly AtlasSelfConstructionRealProviderSmokeCertificationService $realProviderSmokeVerifier = new AtlasSelfConstructionRealProviderSmokeCertificationService,
        private readonly AtlasSelfConstructionHumanCompletionReceiptVerifierService $completionReceiptVerifier = new AtlasSelfConstructionHumanCompletionReceiptVerifierService,
    ) {}

    /**
     * @param  array{runtime_promotion_receipt?: array<string, mixed>, real_provider_smoke?: array<string, mixed>, completion_receipt?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $runtimeInput = $this->arrayOrNull($options['runtime_promotion_receipt'] ?? null);
        $realProviderInput = $this->arrayOrNull($options['real_provider_smoke'] ?? null);
        $completionInput = $this->arrayOrNull($options['completion_receipt'] ?? null);

        $receiptDiagnostics = [
            $this->diagnoseRuntimePromotionReceipt($runtimeInput),
            $this->diagnoseRealProviderSmoke($realProviderInput),
            $this->diagnoseHumanCompletionReceipt($completionInput),
        ];

        $syntheticCatalog = $this->buildSyntheticFixtureCatalog();

        $antiCheatPolicy = [
            'test_fixtures_are_not_operator_evidence' => true,
            'synthetic_smoke_cannot_close_real_provider_blocker' => true,
            'computed_hash_does_not_imply_operator_approval' => true,
            'verifier_passed_in_test_storage_does_not_equal_real_completion' => true,
            'no_completion_claim_from_fixture_lab' => true,
        ];

        $nonExecutionGuarantees = [
            'does_not_persist' => true,
            'does_not_call_provider' => true,
            'does_not_spend_tokens' => true,
            'does_not_dispatch' => true,
            'does_not_start_process' => true,
            'does_not_enable_runtime' => true,
            'does_not_promote_completion' => true,
            'does_not_sign_for_operator' => true,
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'persistence_allowed_here' => false,
            'receipt_diagnostics' => $receiptDiagnostics,
            'synthetic_fixture_catalog' => $syntheticCatalog,
            'anti_cheat_policy' => $antiCheatPolicy,
            'non_execution_guarantees' => $nonExecutionGuarantees,
        ];
        $payload['fixture_lab_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param mixed $value */
    private function arrayOrNull($value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function diagnoseRuntimePromotionReceipt(?array $receipt): array
    {
        $kind = 'runtime_promotion_receipt';
        if ($receipt === null) {
            return $this->emptyDiagnostic($kind);
        }

        $missing = $this->missingFields($receipt, self::REQUIRED_FIELDS_RUNTIME_RECEIPT);
        $invalidHashes = $this->invalidHashFields($receipt, self::HASH_FIELDS_RUNTIME_RECEIPT);
        $placeholders = $this->placeholderFields($receipt, ['signed_by']);
        $forbiddenTrue = $this->forbiddenFlagsTrue($receipt, self::FORBIDDEN_FLAGS_RUNTIME_RECEIPT);

        $computedHash = $this->hashes->runtimePromotionReceiptHash($receipt);
        $submittedHash = (string) ($receipt['receipt_hash'] ?? '');
        $hashMatches = $submittedHash !== '' && $submittedHash === $computedHash;

        $verifier = $this->runtimeReceiptVerifier->verify($receipt, [], '', '', '', false);
        $verifierStatus = (string) ($verifier['status'] ?? '');
        $verifierViolations = (array) ($verifier['violations'] ?? []);

        $status = $verifierStatus === 'passed' ? 'verifier_passed' : 'invalid';
        $safeNextCommand = $status === 'verifier_passed'
            ? 'verify_against_real_runtime_gap_matrix_then_persist_via_real_verifier_outside_of_lab'
            : 'fix_payload_and_rerun_lab_before_attempting_real_verifier';

        return [
            'fixture_kind' => $kind,
            'input_present' => true,
            'status' => $status,
            'computed_hash' => $computedHash,
            'submitted_hash' => $submittedHash,
            'hash_matches' => $hashMatches,
            'missing_fields' => $missing,
            'invalid_hash_fields' => $invalidHashes,
            'placeholder_fields' => $placeholders,
            'forbidden_flags_true' => $forbiddenTrue,
            'verifier_status' => $verifierStatus,
            'verifier_violation_count' => (int) ($verifier['violation_count'] ?? count($verifierViolations)),
            'verifier_violations' => $verifierViolations,
            'safe_next_command' => $safeNextCommand,
            'persistence_allowed_here' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function diagnoseRealProviderSmoke(?array $smoke): array
    {
        $kind = 'real_provider_smoke';
        if ($smoke === null) {
            return $this->emptyDiagnostic($kind);
        }

        $missing = $this->missingFields($smoke, self::REQUIRED_FIELDS_REAL_PROVIDER_SMOKE);
        $invalidHashes = $this->invalidHashFields($smoke, self::HASH_FIELDS_REAL_PROVIDER_SMOKE);
        $placeholders = $this->placeholderFieldsStartingWithAngle($smoke, [
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
        ]);
        $forbiddenTrue = $this->forbiddenFlagsTrue($smoke, self::FORBIDDEN_FLAGS_REAL_PROVIDER_SMOKE);

        $computedHash = $this->hashes->realProviderSmokeHash($smoke);
        $submittedHash = (string) ($smoke['smoke_hash'] ?? '');
        $hashMatches = $submittedHash !== '' && $submittedHash === $computedHash;

        $verifier = $this->realProviderSmokeVerifier->certify($smoke);
        $verifierStatus = (string) ($verifier['status'] ?? '');
        $verifierViolations = (array) ($verifier['violations'] ?? []);

        $status = $verifierStatus === 'passed' ? 'verifier_passed' : 'invalid';
        $safeNextCommand = $status === 'verifier_passed'
            ? 'do_not_persist_from_lab_run_real_operator_observed_smoke_with_real_provider_outside_of_lab'
            : 'fix_smoke_payload_and_rerun_lab_before_attempting_real_certification';

        return [
            'fixture_kind' => $kind,
            'input_present' => true,
            'status' => $status,
            'computed_hash' => $computedHash,
            'submitted_hash' => $submittedHash,
            'hash_matches' => $hashMatches,
            'missing_fields' => $missing,
            'invalid_hash_fields' => $invalidHashes,
            'placeholder_fields' => $placeholders,
            'forbidden_flags_true' => $forbiddenTrue,
            'verifier_status' => $verifierStatus,
            'verifier_violation_count' => (int) ($verifier['violation_count'] ?? count($verifierViolations)),
            'verifier_violations' => $verifierViolations,
            'safe_next_command' => $safeNextCommand,
            'persistence_allowed_here' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function diagnoseHumanCompletionReceipt(?array $receipt): array
    {
        $kind = 'human_completion_receipt';
        if ($receipt === null) {
            return $this->emptyDiagnostic($kind);
        }

        $missing = $this->missingFields($receipt, self::REQUIRED_FIELDS_COMPLETION_RECEIPT);
        $invalidHashes = $this->invalidHashFields($receipt, self::HASH_FIELDS_COMPLETION_RECEIPT);
        $placeholders = $this->placeholderFields($receipt, ['signed_by']);
        $forbiddenTrue = $this->forbiddenFlagsTrue($receipt, self::FORBIDDEN_FLAGS_COMPLETION_RECEIPT);

        $computedHash = $this->hashes->humanCompletionReceiptHash($receipt);
        $submittedHash = (string) ($receipt['receipt_hash'] ?? '');
        $hashMatches = $submittedHash !== '' && $submittedHash === $computedHash;

        $verifier = $this->completionReceiptVerifier->verify($receipt, []);
        $verifierStatus = (string) ($verifier['status'] ?? '');
        $verifierViolations = (array) ($verifier['violations'] ?? []);

        $status = $verifierStatus === 'passed' ? 'verifier_passed' : 'invalid';
        $safeNextCommand = $status === 'verifier_passed'
            ? 'do_not_persist_from_lab_route_to_final_operator_evidence_closure_corridor_outside_of_lab'
            : 'fix_completion_receipt_payload_and_rerun_lab_before_attempting_real_verifier';

        return [
            'fixture_kind' => $kind,
            'input_present' => true,
            'status' => $status,
            'computed_hash' => $computedHash,
            'submitted_hash' => $submittedHash,
            'hash_matches' => $hashMatches,
            'missing_fields' => $missing,
            'invalid_hash_fields' => $invalidHashes,
            'placeholder_fields' => $placeholders,
            'forbidden_flags_true' => $forbiddenTrue,
            'verifier_status' => $verifierStatus,
            'verifier_violation_count' => (int) ($verifier['violation_count'] ?? count($verifierViolations)),
            'verifier_violations' => $verifierViolations,
            'safe_next_command' => $safeNextCommand,
            'persistence_allowed_here' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyDiagnostic(string $kind): array
    {
        return [
            'fixture_kind' => $kind,
            'input_present' => false,
            'status' => 'no_input',
            'computed_hash' => '',
            'submitted_hash' => '',
            'hash_matches' => false,
            'missing_fields' => [],
            'invalid_hash_fields' => [],
            'placeholder_fields' => [],
            'forbidden_flags_true' => [],
            'verifier_status' => '',
            'verifier_violation_count' => 0,
            'verifier_violations' => [],
            'safe_next_command' => 'supply_'.$kind.'_payload_via_options_to_get_diagnostics',
            'persistence_allowed_here' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function missingFields(array $payload, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function invalidHashFields(array $payload, array $fields): array
    {
        $invalid = [];
        foreach ($fields as $field) {
            $value = (string) ($payload[$field] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $invalid[] = $field;
            }
        }

        return $invalid;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function placeholderFields(array $payload, array $fields): array
    {
        $placeholders = [];
        foreach ($fields as $field) {
            $value = strtolower(trim((string) ($payload[$field] ?? '')));
            if (in_array($value, self::PLACEHOLDER_SIGNERS, true)) {
                $placeholders[] = $field;
            }
        }

        return $placeholders;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function placeholderFieldsStartingWithAngle(array $payload, array $fields): array
    {
        $placeholders = [];
        foreach ($fields as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value === '' || str_starts_with($value, '<')) {
                $placeholders[] = $field;
            }
        }

        return $placeholders;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $flags
     * @return list<string>
     */
    private function forbiddenFlagsTrue(array $payload, array $flags): array
    {
        $true = [];
        foreach ($flags as $flag) {
            if ((bool) ($payload[$flag] ?? false) === true) {
                $true[] = $flag;
            }
        }

        return $true;
    }

    /** @return array<string, mixed> */
    private function buildSyntheticFixtureCatalog(): array
    {
        $hash = str_repeat('a', 64);

        $invalidRuntime = [
            'receipt_id' => '',
            'signed_by' => '<operator>',
            'reason' => 'short',
            'runtime_gap_matrix_hash' => 'not-a-hash',
            'runtime_promotion_basis_hash' => '',
            'runtime_promotion_closure_basis_hash' => '',
            'receipt_hash' => '',
            'promoted_gap_ids' => [],
            'execution_allowed' => true,
        ];

        $validRuntimeTestOnly = [
            'receipt_id' => 'runtime-promotion-test-only',
            'signed_by' => 'Test Operator Fixture',
            'reason' => 'Synthetic fixture used only by the operator evidence fixture lab tests.',
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_basis_hash' => $hash,
            'runtime_promotion_closure_basis_hash' => $hash,
            'promoted_gap_ids' => [],
            'graduation_evidence_hashes' => [],
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ];
        $validRuntimeTestOnly['receipt_hash'] = $this->hashes->runtimePromotionReceiptHash($validRuntimeTestOnly);

        $invalidRealProviderSmoke = [
            'kind' => 'wrong_kind',
            'status' => 'blocked',
            'provider_run_id' => '<provider_run_id>',
            'task_packet_id' => '',
            'observed_by' => '<operator>',
            'approval_reason' => '',
            'smoke_hash' => 'short',
            'operator_approval_receipt_hash' => '',
            'evidence_ledger_hash' => '',
            'work_product_manifest_hash' => '',
            'cost_event_hash' => '',
            'continuation_summary_hash' => '',
            'provider_response_hash' => '',
            'provider_call_observed' => false,
            'token_spend_observed' => false,
            'claim_to_completion_observed' => false,
            'work_product_collected' => false,
            'operator_supplied_evidence' => false,
            'real_provider_run_observed_by_operator' => false,
            'provider_called_by_atlas' => true,
            'token_spent_by_atlas' => true,
            'dispatch_allowed' => true,
            'self_programming_allowed' => true,
        ];

        $invalidCompletionReceipt = [
            'receipt_id' => '',
            'signed_by' => 'codex',
            'reason' => 'auto',
            'completion_audit_hash' => 'not-a-hash',
            'release_dossier_hash' => '',
            'replay_diff_hash' => '',
            'runtime_gap_matrix_hash' => '',
            'runtime_promotion_receipt_hash' => '',
            'real_provider_smoke_hash' => '',
            'certification_status_batch_hash' => '',
            'receipt_hash' => '',
            'os_complete_approved' => false,
            'operator_reviewed_completion_audit' => false,
            'no_autopromotion_acknowledged' => false,
            'completion_autopromoted' => true,
        ];

        return [
            'valid_fixture_is_test_only' => true,
            'not_operator_evidence' => true,
            'cannot_be_used_for_completion_claim' => true,
            'cannot_be_persisted_as_real_provider_smoke' => true,
            'fixtures' => [
                [
                    'fixture_kind' => 'runtime_promotion_receipt',
                    'invalid_example' => $invalidRuntime,
                    'valid_example_test_only' => $validRuntimeTestOnly,
                    'notes' => 'valid_example_test_only is hash-coherent for unit tests only and intentionally has no real graduation evidence.',
                ],
                [
                    'fixture_kind' => 'real_provider_smoke',
                    'invalid_example' => $invalidRealProviderSmoke,
                    'valid_example_test_only' => null,
                    'notes' => 'no synthetic valid smoke exists; a real provider run with operator approval is the only legitimate source.',
                ],
                [
                    'fixture_kind' => 'human_completion_receipt',
                    'invalid_example' => $invalidCompletionReceipt,
                    'valid_example_test_only' => null,
                    'notes' => 'no synthetic valid completion receipt exists; only a real operator signature can close completion.',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['fixture_lab_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
