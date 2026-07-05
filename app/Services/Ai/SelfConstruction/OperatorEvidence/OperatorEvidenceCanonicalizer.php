<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorEvidence;

/**
 * Stateless canonicalization/hash utilities for the Atlas Self-Construction
 * operator evidence submission readiness service.
 *
 * Extracted from AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService
 * to reduce the god-class. All methods are pure — no instance state.
 */
final class OperatorEvidenceCanonicalizer
{
    /** @return list<string> */
    public static function placeholderFieldsFromCommand(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => trim($value), $matches[0] ?? [])));
    }

    public static function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        $path = preg_replace('#^storage/app/private/#', '', $path) ?? $path;
        $path = preg_replace('#^storage/app/#', '', $path) ?? $path;

        return trim($path, '/');
    }

    /** @return array<string, mixed> */
    public static function emptyVerification(string $reason): array
    {
        return [
            'status' => 'not_supplied',
            'reason' => $reason,
            'violations' => [],
            'violation_count' => 0,
        ];
    }

    /** @var list<string> Keys removed from evidence before provider-bound output (volatile or secret-bearing). */
    private const VOLATILE_KEYS = [
        'raw_prompt',
        'provider_trace',
        'raw_secret',
        'secret',
        'csp_nonce',
        '_token',
        'auth_token',
        'bearer',
        'authorization',
        'password',
        'api_key',
        'api_secret',
        'access_key',
        'private_key',
        'session_id',
    ];

    /** @var list<string> Keys stripped from stableHash input (they change every run). */
    private const HASH_VOLATILE_KEYS = [
        'generated_at',
        'submission_readiness_hash',
        'csp_nonce',
        '_token',
        'updated_at',
        'expires_at',
        'timestamp',
        'nonce',
    ];

    /** @var non-empty-string Regex matching secret-like values to redact. */
    private const SECRET_VALUE_PATTERN = '~(sk-[\w-]{8,}|[A-Za-z0-9+/=]{40,}|bearer\s+\S+|(?:^|\s)-----BEGIN\s+(RSA\s+)?PRIVATE\s+KEY-----)~i';

    /**
     * Normalize operator-supplied evidence as a visibility-only audit snapshot.
     * Stamps visibility_only=true and steady_state_proof=false so downstream consumers
     * cannot treat operator attestations as native completion proof.
     *
     * Recursively removes known secret-bearing keys at any nesting depth,
     * redacts secret-like values, and drops volatile metadata fields.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public static function canonicalize(array $evidence): array
    {
        $evidence = self::removeSecretKeys($evidence);
        $evidence = self::redactSecretValues($evidence);
        $evidence['visibility_only'] = true;
        $evidence['steady_state_proof'] = false;

        return $evidence;
    }

    /**
     * Recursively remove any top-level or nested keys that match the VOLATILE_KEYS list.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function removeSecretKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::VOLATILE_KEYS, true)) {
                unset($data[$key]);

                continue;
            }
            if (is_array($value)) {
                $data[$key] = self::removeSecretKeys($value);
            }
        }

        return $data;
    }

    /**
     * Recursively scan string values for secret-like patterns and replace them with [REDACTED].
     * Scalar non-string values are passed through unchanged.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function redactSecretValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && preg_match(self::SECRET_VALUE_PATTERN, $value) === 1) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactSecretValues($value);
            }
        }

        return $data;
    }

    /**
     * Compute a deterministic SHA-256 hash of payload, ignoring volatile metadata fields
     * (generated_at, nonce, timestamps, submission_readiness_hash) and diagnostic
     * violation arrays so hash stays stable across re-runs.
     *
     * Hash CHANGES when proof-bearing fields such as receipt id, command result,
     * schema, gate outcome, or evidence ref change — exactly what the consumer
     * needs to detect meaningful drift.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function stableHash(array $payload): string
    {
        foreach (self::HASH_VOLATILE_KEYS as $key) {
            unset($payload[$key]);
        }
        unset($payload['diagnostics']['runtime_promotion_receipt']['violations']);
        unset($payload['diagnostics']['real_provider_smoke']['violations']);
        unset($payload['diagnostics']['human_completion_receipt']['violations']);

        return hash('sha256', (string) json_encode(self::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    public static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
