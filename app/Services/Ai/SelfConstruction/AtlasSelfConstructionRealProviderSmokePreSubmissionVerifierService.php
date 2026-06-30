<?php

namespace App\Services\Ai\SelfConstruction;


use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_pre_submission_verifier.v1';

    public const MODE = 'read_only_real_provider_smoke_pre_submission_verifier';

    private const PLACEHOLDER_VALUES = [
        '',
        '<operator>',
        '<operator_or_reviewer>',
        '<operator_observed_provider_run_id>',
        '<operator_observed_task_packet_id>',
    ];

    private const SYNTHETIC_MARKER_FRAGMENTS = [
        'synthetic',
        'fixture-only',
        'test_only',
        'test-only',
        'fake',
        'simulated',
        'mock-',
    ];

    public function __construct(
        private readonly AtlasSelfConstructionCompletionEvidenceHashService $hashes = new AtlasSelfConstructionCompletionEvidenceHashService,
    ) {}

    /**
     * @param  array<string, mixed>  $smoke
     * @return array<string, mixed>
     */
    public function verify(array $smoke = []): array
    {
        $diagnostics = [];

        $diagnostics = array_merge($diagnostics, $this->missingFieldDiagnostics($smoke));
        $diagnostics = array_merge($diagnostics, $this->hashDiagnostics($smoke));
        $diagnostics = array_merge($diagnostics, $this->observationDiagnostics($smoke));
        $diagnostics = array_merge($diagnostics, $this->forbiddenFlagDiagnostics($smoke));
        $diagnostics = array_merge($diagnostics, $this->syntheticMarkerDiagnostics($smoke));

        $passed = $diagnostics === [];
        $status = $smoke === [] ? 'blocked' : ($passed ? 'passed' : 'blocked');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'input_present' => $smoke !== [],
            'diagnostics' => $diagnostics,
            'diagnostic_count' => count($diagnostics),
            'can_persist' => $passed && $smoke !== [],
            'not_operator_real_until_observed' => true,
            'safe_next_command' => $passed && $smoke !== []
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json'
                : 'fix_diagnostics_then_rerun_pre_submission_verifier',
            'persistence_allowed_here' => false,
            'non_execution_guarantees' => [
                'does_not_call_provider' => true,
                'does_not_spend_tokens' => true,
                'does_not_dispatch' => true,
                'does_not_persist_smoke' => true,
                'does_not_promote_completion' => true,
            ],
        ];
        $payloadForHash = $payload;
        unset($payloadForHash['verified_at'], $payloadForHash['pre_submission_hash']);
        $payload['pre_submission_hash'] = hash('sha256', (string) json_encode(ReadinessHash::ksortRecursive($payloadForHash), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @return list<array<string, mixed>>
     */
    private function missingFieldDiagnostics(array $smoke): array
    {
        $diagnostics = [];
        $fields = [
            'provider_run_id' => 'missing_provider_run_id',
            'task_packet_id' => 'missing_task_packet_id',
            'observed_by' => 'missing_observed_by',
            'approval_reason' => 'missing_approval_reason',
        ];
        foreach ($fields as $field => $code) {
            $value = trim((string) ($smoke[$field] ?? ''));
            if ($this->isPlaceholderValue($value)) {
                $diagnostics[] = ['code' => $code, 'field' => $field];
            }
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @return list<array<string, mixed>>
     */
    private function hashDiagnostics(array $smoke): array
    {
        $diagnostics = [];
        $hashFields = [
            'smoke_hash' => 'invalid_smoke_hash',
            'operator_approval_receipt_hash' => 'invalid_operator_approval_receipt_hash',
            'evidence_ledger_hash' => 'invalid_evidence_ledger_hash',
            'work_product_manifest_hash' => 'invalid_work_product_manifest_hash',
            'cost_event_hash' => 'invalid_cost_event_hash',
            'continuation_summary_hash' => 'invalid_continuation_summary_hash',
            'provider_response_hash' => 'invalid_provider_response_hash',
        ];
        foreach ($hashFields as $field => $code) {
            $value = (string) ($smoke[$field] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $diagnostics[] = ['code' => $code, 'field' => $field];
            }
        }

        if ($smoke !== []) {
            $submitted = (string) ($smoke['smoke_hash'] ?? '');
            $expected = $this->hashes->realProviderSmokeHash($smoke);
            if ($submitted !== '' && $submitted !== $expected) {
                $diagnostics[] = ['code' => 'smoke_hash_mismatch', 'expected_smoke_hash' => $expected];
            }
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @return list<array<string, mixed>>
     */
    private function observationDiagnostics(array $smoke): array
    {
        if ($smoke === []) {
            return [];
        }
        $diagnostics = [];
        $required = [
            'provider_call_observed' => 'provider_call_observed_false',
            'token_spend_observed' => 'token_spend_observed_false',
            'claim_to_completion_observed' => 'claim_to_completion_observed_false',
            'work_product_collected' => 'work_product_collected_false',
            'operator_supplied_evidence' => 'operator_supplied_evidence_false',
            'real_provider_run_observed_by_operator' => 'real_provider_run_observed_by_operator_false',
        ];
        foreach ($required as $flag => $code) {
            if ((bool) ($smoke[$flag] ?? false) !== true) {
                $diagnostics[] = ['code' => $code, 'flag' => $flag];
            }
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @return list<array<string, mixed>>
     */
    private function forbiddenFlagDiagnostics(array $smoke): array
    {
        $diagnostics = [];
        $forbidden = [
            'provider_called_by_atlas',
            'token_spent_by_atlas',
            'dispatch_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ];
        foreach ($forbidden as $flag) {
            if ((bool) ($smoke[$flag] ?? false) === true) {
                $diagnostics[] = ['code' => 'forbidden_flag_true', 'flag' => $flag];
            }
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @return list<array<string, mixed>>
     */
    private function syntheticMarkerDiagnostics(array $smoke): array
    {
        $diagnostics = [];
        foreach (['provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason'] as $field) {
            $value = strtolower((string) ($smoke[$field] ?? ''));
            foreach (self::SYNTHETIC_MARKER_FRAGMENTS as $fragment) {
                if ($value !== '' && str_contains($value, $fragment)) {
                    $diagnostics[] = ['code' => 'synthetic_marker_present', 'field' => $field, 'fragment' => $fragment];
                    break;
                }
            }
        }

        return $diagnostics;
    }

    private function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || str_starts_with($normalized, '<') || str_starts_with($normalized, '__')) {
            return true;
        }
        if (in_array($normalized, self::PLACEHOLDER_VALUES, true)) {
            return true;
        }

        foreach ([
            'seu_nome',
            'seu nome',
            'operador',
            'motivo real',
            'pelo menos 32 caracteres',
            'substitua',
            'placeholder',
            'todo',
            'synthetic',
            'fixture-only',
            'fixture_only',
            'test_only',
            'test-only',
            'fake',
            'simulated',
            'mock-',
            'dummy',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

}
