<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Decision;

final class ReceiptProviderAuthorizationDecision
{
    private const SCHEMA_VERSION = 'atlas.decide.receipt_provider_authorization.v1';

    /**
     * @param  array<string,mixed>  $providerSelection
     * @return array{
     *     schema_version: string,
     *     authorized: bool,
     *     code: string,
     *     expected_provider: ?string,
     *     matched_via: ?string,
     *     fallbacks_considered: list<string>,
     * }
     */
    public function authorize(array $providerSelection, string $runtimeProvider, ?string $runtimeStage): array
    {
        $expectedProvider = $this->stringOrNull($providerSelection['primary'] ?? null);
        $runtime = $this->stringOrNull($runtimeProvider);
        $fallbacks = $this->normalizeFallbacks($providerSelection['fallbacks'] ?? []);

        // R1 — fail-closed short-circuit: missing authorized provider or runtime provider.
        if ($expectedProvider === null || $runtime === null) {
            return $this->decision(false, 'provider_mismatch', null, null, $fallbacks);
        }

        // R2 — exact match.
        if ($expectedProvider === $runtime) {
            return $this->decision(true, 'exact_match', $expectedProvider, 'primary', $fallbacks);
        }

        // R3 — wildcard authorization (auto / selected-by-decide).
        if ($expectedProvider === 'auto' || $expectedProvider === 'selected-by-decide') {
            return $this->decision(true, 'wildcard_auto', $expectedProvider, 'wildcard', $fallbacks);
        }

        // R4 — claude_codex family.
        if ($expectedProvider === 'claude_codex' && in_array($runtime, ['claude_codex', 'claude_cli', 'codex_cli'], true)) {
            return $this->decision(true, 'family_match', $expectedProvider, 'family', $fallbacks);
        }

        // R5 — context_scout stage exception for gemini_cli.
        if ($runtimeStage === 'context_scout' && $runtime === 'gemini_cli') {
            return $this->decision(true, 'stage_exception', $expectedProvider, 'stage', $fallbacks);
        }

        // R6 — runtime provider listed among normalized fallbacks.
        if (in_array($runtime, $fallbacks, true)) {
            return $this->decision(true, 'fallback_match', $expectedProvider, 'fallback', $fallbacks);
        }

        // R7 — no authorization rule satisfied.
        return $this->decision(false, 'provider_mismatch', $expectedProvider, null, $fallbacks);
    }

    /**
     * @param  list<string>  $fallbacks
     * @return array{
     *     schema_version: string,
     *     authorized: bool,
     *     code: string,
     *     expected_provider: ?string,
     *     matched_via: ?string,
     *     fallbacks_considered: list<string>,
     * }
     */
    private function decision(bool $authorized, string $code, ?string $expectedProvider, ?string $matchedVia, array $fallbacks): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'authorized' => $authorized,
            'code' => $code,
            'expected_provider' => $expectedProvider,
            'matched_via' => $matchedVia,
            'fallbacks_considered' => $fallbacks,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeFallbacks(mixed $fallbacks): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $value): string => trim((string) $value),
                // Drop non-scalar entries (arrays/objects/null) before stringifying so a
                // malformed element never coerces to the literal "Array" nor emits an
                // "Array to string conversion" warning — mirrors stringOrNull()'s guard.
                array_filter((array) $fallbacks, fn (mixed $value): bool => is_scalar($value)),
            ),
            fn (string $value): bool => $value !== '',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
