<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Option/payload normalization for the Agent Control Plane terminal-loop
 * health digest.
 *
 * Extracted from AgentControlPlaneTerminalLoopHealthDigestService to reduce
 * the god-class. All methods are stateless.
 *
 * INVARIANTS:
 * - DETERMINISTIC: stringList sorts output; hashPayload ksort-recursives before hashing.
 * - PURE.
 */
final class TerminalLoopHealthDigestPayloadNormalizer
{
    /**
     * Returns the string option at $key, trimmed, or $default when the key is
     * absent, the value is not a string, or the trimmed value is empty.
     *
     * @param  array<string, mixed>  $options
     */
    public function stringOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

    /**
     * Normalize a mixed input into a stable unique list of trimmed strings.
     * Flattens nested arrays one level, coerces non-string scalars, trims
     * whitespace, drops blank entries, and returns a deduplicated list
     * sorted by string comparison for determinism.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $v) {
            if (is_array($v)) {
                foreach ($v as $inner) {
                    $normalized[] = trim((string) $inner);
                }
            } else {
                $normalized[] = trim((string) $v);
            }
        }

        $filtered = array_values(array_filter(
            $normalized,
            static fn (string $s): bool => $s !== '',
        ));

        $unique = array_values(array_unique($filtered, SORT_STRING));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /** @var list<string> Keys stripped from hashPayload input. */
    private const EXCLUDED_HASH_KEYS = [
        'digest_id',
        'generated_at',
        'nonce',
        'timestamp',
        'csp_nonce',
        '_token',
        'updated_at',
        'terminal_loop_health_digest_hash',
        'terminal_loop_fleet_launch_plan_hash',
        'terminal_loop_fleet_replenishment_plan_hash',
        'terminal_loop_fleet_resume_rollup_hash',
        'terminal_loop_fleet_evidence_rollup_hash',
        'terminal_loop_fleet_operator_handoff_hash',
        'terminal_loop_fleet_lane_isolation_hash',
        'terminal_loop_cycle_supervisor_hash',
        'terminal_loop_fleet_launch_runbook_hash',
        'terminal_loop_end_to_end_contract_hash',
    ];

    /**
     * Compute a deterministic SHA-256 hash of $payload after removing
     * volatile metadata keys (digest_id, generated_at, nonce, timestamp,
     * hash rollups, etc.) and sorting keys recursively so equivalent
     * payloads with different key ordering produce the same hash.
     *
     * Hash CHANGES when proof-bearing fields (queue health data, lane
     * counts, cycle identifiers, etc.) change.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hashPayload(array $payload): string
    {
        foreach (self::EXCLUDED_HASH_KEYS as $key) {
            unset($payload[$key]);
        }

        return hash(
            'sha256',
            (string) json_encode(
                self::ksortRecursive($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * Recursively sort associative arrays by key. Sequential integer-indexed
     * arrays are left in their original order.
     *
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::ksortRecursive($v);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}