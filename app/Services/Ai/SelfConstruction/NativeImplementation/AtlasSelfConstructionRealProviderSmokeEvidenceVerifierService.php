<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_evidence_verifier.v1';

    public const MODE = 'read_only_real_provider_smoke_evidence_verifier';

    public const FORGERY_CATEGORY_PLACEHOLDER = 'placeholder';

    public const FORGERY_CATEGORY_MISSING_OPERATOR_ACK = 'missing_operator_ack';

    public const FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG = 'forbidden_atlas_runtime_flag';

    public const FORGERY_CATEGORY_HASH_MISMATCH = 'hash_mismatch';

    public const FORGERY_CATEGORY_CERTIFICATION_FAILURE = 'certification_failure';

    /** Highest risk first: a forbidden runtime flag is a forgery attempt, not honest gap. */
    private const FORGERY_CATEGORY_RISK_ORDER = [
        self::FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG,
        self::FORGERY_CATEGORY_HASH_MISMATCH,
        self::FORGERY_CATEGORY_CERTIFICATION_FAILURE,
        self::FORGERY_CATEGORY_MISSING_OPERATOR_ACK,
        self::FORGERY_CATEGORY_PLACEHOLDER,
    ];

    private const FORGERY_CATEGORY_BY_VIOLATION_CODE = [
        'atlas_runtime_flag_forbidden_in_operator_smoke' => self::FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG,
        'forbidden_runtime_flag_true' => self::FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG,
        'smoke_hash_mismatch' => self::FORGERY_CATEGORY_HASH_MISMATCH,
        'required_hash_invalid_or_missing' => self::FORGERY_CATEGORY_HASH_MISMATCH,
        'operator_supplied_evidence_ack_missing' => self::FORGERY_CATEGORY_MISSING_OPERATOR_ACK,
        'real_provider_operator_observation_ack_missing' => self::FORGERY_CATEGORY_MISSING_OPERATOR_ACK,
        'required_operator_real_smoke_ack_missing' => self::FORGERY_CATEGORY_MISSING_OPERATOR_ACK,
        'operator_smoke_placeholder_or_missing' => self::FORGERY_CATEGORY_PLACEHOLDER,
        'required_real_smoke_field_missing' => self::FORGERY_CATEGORY_PLACEHOLDER,
        'required_real_smoke_field_placeholder' => self::FORGERY_CATEGORY_PLACEHOLDER,
        'required_real_smoke_observation_missing' => self::FORGERY_CATEGORY_PLACEHOLDER,
        'smoke_kind_invalid_or_missing' => self::FORGERY_CATEGORY_CERTIFICATION_FAILURE,
        'smoke_status_not_passed' => self::FORGERY_CATEGORY_CERTIFICATION_FAILURE,
    ];

    /** @return array<string, mixed> */
    public function verify(array $payload): array
    {
        $certification = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($payload);
        $violations = (array) data_get($certification, 'violations', []);

        foreach (['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed'] as $flag) {
            if ((bool) ($payload[$flag] ?? false) === true) {
                $violations[] = ['code' => 'atlas_runtime_flag_forbidden_in_operator_smoke', 'flag' => $flag];
            }
        }
        foreach (['provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value === '' || $this->isPlaceholderOperatorSmokeValue($value)) {
                $violations[] = ['code' => 'operator_smoke_placeholder_or_missing', 'field' => $field];
            }
        }
        if (! (bool) ($payload['operator_supplied_evidence'] ?? false)) {
            $violations[] = ['code' => 'operator_supplied_evidence_ack_missing'];
        }
        if (! (bool) ($payload['real_provider_run_observed_by_operator'] ?? false)) {
            $violations[] = ['code' => 'real_provider_operator_observation_ack_missing'];
        }

        $status = $violations === [] ? 'verified_operator_supplied_real_provider_smoke_evidence' : 'blocked';
        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'certification_status' => (string) data_get($certification, 'status'),
            'smoke_hash' => (string) ($payload['smoke_hash'] ?? ''),
            'expected_smoke_hash' => (string) data_get($certification, 'expected_smoke_hash'),
            'smoke_hash_matches_payload' => (bool) data_get($certification, 'smoke_hash_matches_payload', false),
            'violation_count' => count($violations),
            'violations' => $violations,
            'forgery_classifier' => $this->forgeryClassifier($violations),
            'completion_criterion_green' => $status === 'verified_operator_supplied_real_provider_smoke_evidence',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $result['verification_hash'] = $this->stableHash($result);

        return $result;
    }

    /**
     * Groups raw violations into forgery categories so honest missing
     * evidence (placeholder/missing ack) is distinguishable from active
     * forgery attempts (forbidden runtime flags, hash mismatches).
     *
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, mixed>
     */
    private function forgeryClassifier(array $violations): array
    {
        $counts = array_fill_keys(self::FORGERY_CATEGORY_RISK_ORDER, 0);

        foreach ($violations as $violation) {
            $code = (string) ($violation['code'] ?? '');
            $category = self::FORGERY_CATEGORY_BY_VIOLATION_CODE[$code] ?? self::FORGERY_CATEGORY_CERTIFICATION_FAILURE;
            $counts[$category]++;
        }

        $highestRiskCategory = null;
        foreach (self::FORGERY_CATEGORY_RISK_ORDER as $category) {
            if ($counts[$category] > 0) {
                $highestRiskCategory = $category;
                break;
            }
        }

        return [
            'category_counts' => $counts,
            'highest_risk_category' => $highestRiskCategory,
            'risk_order' => self::FORGERY_CATEGORY_RISK_ORDER,
        ];
    }

    private function isPlaceholderOperatorSmokeValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || str_starts_with($normalized, '<') || str_starts_with($normalized, '__')) {
            return true;
        }

        foreach ([
            'synthetic',
            'fixture-only',
            'fixture_only',
            'test_only',
            'test-only',
            'fake',
            'simulated',
            'mock-',
            'dummy',
            'placeholder',
            'seu_nome',
            'seu nome',
            'operador',
            'motivo real',
            'pelo menos 32 caracteres',
            'substitua',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['verification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
