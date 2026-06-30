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

    /**
     * Normalize operator-supplied evidence as a visibility-only audit snapshot.
     * Stamps visibility_only=true and steady_state_proof=false so downstream consumers
     * cannot treat operator attestations as native completion proof.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public static function canonicalize(array $evidence): array
    {
        unset($evidence['raw_prompt'], $evidence['provider_trace'], $evidence['raw_secret'], $evidence['secret']);
        $evidence['visibility_only'] = true;
        $evidence['steady_state_proof'] = false;

        return $evidence;
    }

    /** @param array<string, mixed> $payload */
    public static function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['submission_readiness_hash']);
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
