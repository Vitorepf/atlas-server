<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_evidence_ledger_preflight.v1';

    public const MODE = 'read_only_real_provider_smoke_evidence_ledger_preflight';

    private const REQUIRED_HASHES = [
        'evidence_ledger_hash',
        'cost_event_hash',
        'work_product_manifest_hash',
        'continuation_summary_hash',
        'provider_response_hash',
        'operator_approval_receipt_hash',
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
    ];

    private const FORBIDDEN_FLAGS = [
        'provider_called_by_atlas',
        'token_spent_by_atlas',
        'dispatch_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
        'completion_claim_promoted_without_receipt',
    ];

    /**
     * @param  array<string, mixed>  $smoke
     * @return array<string, mixed>
     */
    public function preflight(array $smoke = []): array
    {
        $missingHashes = [];
        $invalidHashes = [];
        $placeholderHashes = [];
        foreach (self::REQUIRED_HASHES as $field) {
            $value = (string) ($smoke[$field] ?? '');
            if ($value === '') {
                $missingHashes[] = $field;

                continue;
            }
            if (str_starts_with($value, '<') || str_starts_with($value, '__')) {
                $placeholderHashes[] = $field;

                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $invalidHashes[] = $field;
            }
        }

        $syntheticMarkers = [];
        foreach (['provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason'] as $field) {
            $value = strtolower((string) ($smoke[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach (self::SYNTHETIC_FRAGMENTS as $fragment) {
                if (str_contains($value, $fragment)) {
                    $syntheticMarkers[] = ['field' => $field, 'fragment' => $fragment];
                    break;
                }
            }
        }

        $forbiddenFlagsTrue = [];
        foreach (self::FORBIDDEN_FLAGS as $flag) {
            if ((bool) ($smoke[$flag] ?? false) === true) {
                $forbiddenFlagsTrue[] = $flag;
            }
        }

        $blockerCount = count($missingHashes) + count($invalidHashes) + count($placeholderHashes) + count($syntheticMarkers) + count($forbiddenFlagsTrue);
        $status = $smoke === [] || $blockerCount > 0 ? 'blocked' : 'evidence_ledger_complete';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'input_present' => $smoke !== [],
            'required_hash_fields' => self::REQUIRED_HASHES,
            'missing_hash_fields' => $missingHashes,
            'placeholder_hash_fields' => $placeholderHashes,
            'invalid_hash_fields' => $invalidHashes,
            'synthetic_markers' => $syntheticMarkers,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'blocker_count' => $blockerCount,
            'all_required_hashes_present' => $missingHashes === [] && $placeholderHashes === [] && $invalidHashes === [],
            'can_call_certifier' => $status === 'evidence_ledger_complete',
            'persistence_allowed_here' => false,
            'safe_next_command' => $status === 'evidence_ledger_complete'
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-endgame-verifier-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json'
                : 'collect_missing_evidence_then_rerun_preflight',
            'non_execution_guarantees' => [
                'does_not_call_provider' => true,
                'does_not_spend_tokens' => true,
                'does_not_dispatch' => true,
                'does_not_persist_smoke' => true,
                'does_not_promote_completion' => true,
            ],
        ];
        $payload['preflight_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['preflight_hash']);

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
