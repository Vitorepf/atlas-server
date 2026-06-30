<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalLoopProof;

use Illuminate\Support\Str;

/**
 * Stateless canonicalization/hashing helpers for the Agent Control Plane
 * terminal-loop operational proof service.
 *
 * Extracted from AgentControlPlaneTerminalLoopOperationalProofService to
 * reduce the god-class. All methods are pure static.
 */
final class TerminalLoopProofCanonicalizer
{
    public static function safeToken(string $value, string $fallback): string
    {
        $token = Str::of($value)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->toString();

        return $token !== '' ? $token : $fallback;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public static function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @param array<string, mixed> $payload */
    public static function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['terminal_loop_operational_proof_hash'], $payload['completion_audit_binding_packet'], $payload['completion_audit_binding_packet_hash']);

        return hash('sha256', (string) json_encode(self::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $payload */
    public static function hasSecretOrPlaceholder(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', (string) $key)) {
                return true;
            }
            if (is_string($value) && self::isPlaceholderValue($value)) {
                return true;
            }
            if (is_array($value) && self::hasSecretOrPlaceholder($value)) {
                return true;
            }
        }

        return false;
    }

    private static function isPlaceholderValue(string $value): bool
    {
        $v = strtolower(trim($value));
        if ($v === '' || str_starts_with($v, '<') || str_starts_with($v, '__')) {
            return true;
        }
        foreach (['placeholder', 'todo', 'fake', 'simulated', 'fixture', 'mock', 'dummy'] as $fragment) {
            if (str_contains($v, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    public static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::ksortRecursive($entry);
            }
        }
        if ($value !== [] && ! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}