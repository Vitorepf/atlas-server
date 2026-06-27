<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeEndgameVerifierService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_endgame_verifier.v1';

    public const MODE = 'read_only_real_provider_smoke_endgame_verifier';

    public const REQUIRED_IDENTITY_FIELDS = [
        'provider_run_id' => 'missing_provider_run_id',
        'task_packet_id' => 'missing_task_packet_id',
        'observed_by' => 'missing_observed_by',
        'approval_reason' => 'missing_approval_reason',
    ];

    public const REQUIRED_HASH_FIELDS = [
        'smoke_hash' => 'invalid_smoke_hash',
        'operator_approval_receipt_hash' => 'invalid_operator_approval_receipt_hash',
        'evidence_ledger_hash' => 'invalid_evidence_ledger_hash',
        'work_product_manifest_hash' => 'invalid_work_product_manifest_hash',
        'cost_event_hash' => 'invalid_cost_event_hash',
        'continuation_summary_hash' => 'invalid_continuation_summary_hash',
        'provider_response_hash' => 'invalid_provider_response_hash',
    ];

    public const REQUIRED_OBSERVATION_FLAGS = [
        'provider_call_observed' => 'provider_call_observed_false',
        'token_spend_observed' => 'token_spend_observed_false',
        'claim_to_completion_observed' => 'claim_to_completion_observed_false',
        'work_product_collected' => 'work_product_collected_false',
        'operator_supplied_evidence' => 'operator_supplied_evidence_false',
        'real_provider_run_observed_by_operator' => 'real_provider_run_observed_by_operator_false',
    ];

    public const FORBIDDEN_FLAGS = [
        'provider_called_by_atlas',
        'token_spent_by_atlas',
        'dispatch_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
        'completion_claim_promoted_without_receipt',
    ];

    private const SYNTHETIC_FRAGMENTS = [
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
    ];

    private const PLACEHOLDER_PREFIXES = ['<', '__'];

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

        foreach (self::REQUIRED_IDENTITY_FIELDS as $field => $code) {
            $value = trim((string) ($smoke[$field] ?? ''));
            if ($value === '' || $this->isPlaceholder($value)) {
                $diagnostics[] = ['code' => $code, 'field' => $field];
            }
        }

        foreach (self::REQUIRED_HASH_FIELDS as $field => $code) {
            $value = (string) ($smoke[$field] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $diagnostics[] = ['code' => $code, 'field' => $field];
            }
        }

        if ($smoke !== []) {
            $submitted = (string) ($smoke['smoke_hash'] ?? '');
            $expected = $this->hashes->realProviderSmokeHash($smoke);
            if ($submitted !== '' && $submitted !== $expected) {
                $diagnostics[] = ['code' => 'smoke_hash_mismatch', 'expected_smoke_hash' => $expected, 'submitted_smoke_hash' => $submitted];
            }
        }

        if ($smoke !== []) {
            foreach (self::REQUIRED_OBSERVATION_FLAGS as $flag => $code) {
                if ((bool) ($smoke[$flag] ?? false) !== true) {
                    $diagnostics[] = ['code' => $code, 'flag' => $flag];
                }
            }
        }

        foreach (self::FORBIDDEN_FLAGS as $flag) {
            if ((bool) ($smoke[$flag] ?? false) === true) {
                $diagnostics[] = ['code' => 'forbidden_flag_true', 'flag' => $flag];
            }
        }

        foreach (['provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason'] as $field) {
            $value = strtolower((string) ($smoke[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach (self::SYNTHETIC_FRAGMENTS as $fragment) {
                if (str_contains($value, $fragment)) {
                    $diagnostics[] = ['code' => 'synthetic_marker_present', 'field' => $field, 'fragment' => $fragment];
                    break;
                }
            }
        }

        $kind = (string) ($smoke['kind'] ?? '');
        if ($smoke !== [] && $kind !== '' && $kind !== 'real_provider_packet_claim_to_completion') {
            $diagnostics[] = ['code' => 'smoke_kind_invalid', 'field' => 'kind', 'value' => $kind];
        }

        $status = ($smoke === [] || $diagnostics !== []) ? 'blocked' : 'passed';
        $canPersist = $status === 'passed' && $smoke !== [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'input_present' => $smoke !== [],
            'diagnostic_count' => count($diagnostics),
            'diagnostics' => $diagnostics,
            'submitted_smoke_hash' => (string) ($smoke['smoke_hash'] ?? ''),
            'expected_smoke_hash' => $smoke === [] ? '' : $this->hashes->realProviderSmokeHash($smoke),
            'smoke_hash_matches_payload' => $smoke !== [] && (string) ($smoke['smoke_hash'] ?? '') === $this->hashes->realProviderSmokeHash($smoke),
            'can_persist' => $canPersist,
            'operator_supplied' => $smoke !== [] && (bool) ($smoke['operator_supplied_evidence'] ?? false) === true,
            'not_operator_real_until_observed' => true,
            'safe_next_command' => $canPersist
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json'
                : 'fix_endgame_verifier_diagnostics_then_rerun',
            'persistence_allowed_here' => false,
            'non_execution_guarantees' => [
                'does_not_call_provider' => true,
                'does_not_spend_tokens' => true,
                'does_not_dispatch' => true,
                'does_not_persist_smoke' => true,
                'does_not_promote_completion' => true,
                'does_not_sign_for_operator' => true,
            ],
        ];
        $payload['verifier_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));
        foreach (self::PLACEHOLDER_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        foreach (self::SYNTHETIC_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['verifier_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
