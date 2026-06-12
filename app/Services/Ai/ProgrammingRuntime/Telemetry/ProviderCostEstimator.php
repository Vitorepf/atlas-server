<?php

declare(strict_types=1);

namespace App\Services\Ai\ProgrammingRuntime\Telemetry;

/**
 * L3-10 — the cost axis of the N×M antifragility equation, MEASURED.
 *
 * Marco Zero recorded 94/94 provider-execution events with cost = "unknown":
 * the cost field existed on the telemetry ledger but no caller computed it, so
 * the cost axis was structurally blind. This estimator turns REAL execution
 * signals into a measured cost estimate (USD):
 *
 *   1. Token usage (from the provider response) × a per-provider per-1k rate.
 *   2. Runtime fallback for local providers (hermes/minimax/local), where the
 *      tokens may be absent but wall-clock runtime is real: duration × rate.
 *
 * It NEVER fabricates a cost. If neither tokens nor runtime are present, it
 * returns null and the event stays honestly "unknown". Every number traces back
 * to a real signal (provider-reported token count or measured wall-clock).
 *
 * Rates live as a DEFAULT const table here (sovereign, local-first); the host
 * may override via the config key `atlas.ai.cost.rates` (see estimate()).
 */
class ProviderCostEstimator
{
    /**
     * USD per 1,000 tokens, per provider. Defaults are conservative public
     * list prices as of the build date; the operator overrides via config.
     * `in`/`out` separate prompt vs. completion. Local self-hosted engines
     * (hermes/minimax/local) have ~0 token cost — they are billed by runtime.
     *
     * @var array<string, array{in: float, out: float}>
     */
    private const TOKEN_RATES_USD_PER_1K = [
        'claude' => ['in' => 0.003, 'out' => 0.015],
        'claude_cli' => ['in' => 0.003, 'out' => 0.015],
        'sonnet' => ['in' => 0.003, 'out' => 0.015],
        'opus' => ['in' => 0.015, 'out' => 0.075],
        'haiku' => ['in' => 0.0008, 'out' => 0.004],
        'codex' => ['in' => 0.0025, 'out' => 0.01],
        'gpt' => ['in' => 0.0025, 'out' => 0.01],
        'openai' => ['in' => 0.0025, 'out' => 0.01],
        'cursor' => ['in' => 0.003, 'out' => 0.015],
        'gemini' => ['in' => 0.00125, 'out' => 0.005],
        // Local / self-hosted: token cost ~0, billed by runtime instead.
        'hermes' => ['in' => 0.0, 'out' => 0.0],
        'minimax' => ['in' => 0.0, 'out' => 0.0],
        'local' => ['in' => 0.0, 'out' => 0.0],
    ];

    /**
     * USD per MINUTE of wall-clock runtime, the local-provider fallback when a
     * provider does not report tokens. This is a measured-compute proxy (the
     * machine ran for N ms), not a fabricated constant: it scales with the real
     * runtime signal. Default approximates local GPU/CPU amortised cost.
     */
    private const RUNTIME_USD_PER_MINUTE = 0.02;

    /** Providers for which the runtime fallback is allowed (token-free engines). */
    private const RUNTIME_FALLBACK_PROVIDERS = ['hermes', 'minimax', 'local'];

    /** Default rate used when a provider is unrecognised but tokens are present. */
    private const DEFAULT_TOKEN_RATE = ['in' => 0.003, 'out' => 0.015];

    /**
     * Estimate the USD cost of a single provider execution from real signals.
     *
     * Resolution order:
     *   1. An explicit, already-measured cost passed by the caller is trusted.
     *   2. Token usage × per-provider rate (the primary, most precise signal).
     *   3. Runtime × per-minute rate, for local token-free providers only.
     *   4. null — honestly unknown; the caller must NOT fake it.
     *
     * @param  array<string,mixed>  $input  The telemetry input. Recognised keys:
     *   - cost_estimate_usd (already measured; pass-through)
     *   - provider | selected_core (rate-table key)
     *   - tokens_in | input_tokens | prompt_tokens
     *   - tokens_out | output_tokens | completion_tokens
     *   - total_tokens (split 50/50 if in/out absent)
     *   - duration_ms (runtime fallback)
     */
    public function estimate(array $input): ?float
    {
        // 1. An explicit measured cost is authoritative.
        $explicit = $this->positiveFloat($input['cost_estimate_usd'] ?? null);
        if ($explicit !== null) {
            return round($explicit, 6);
        }

        $provider = $this->resolveProvider($input);

        // 2. Token-based cost (primary signal).
        $tokenCost = $this->tokenCost($input, $provider);
        if ($tokenCost !== null) {
            return round($tokenCost, 6);
        }

        // 3. Runtime fallback — ONLY for local, token-free providers.
        $runtimeCost = $this->runtimeCost($input, $provider);
        if ($runtimeCost !== null) {
            return round($runtimeCost, 6);
        }

        // 4. No real signal → honestly unknown.
        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function tokenCost(array $input, ?string $provider): ?float
    {
        $in = $this->nonNegInt($input['tokens_in'] ?? $input['input_tokens'] ?? $input['prompt_tokens'] ?? null);
        $out = $this->nonNegInt($input['tokens_out'] ?? $input['output_tokens'] ?? $input['completion_tokens'] ?? null);

        if ($in === null && $out === null) {
            $total = $this->nonNegInt($input['total_tokens'] ?? null);
            if ($total === null || $total === 0) {
                return null;
            }
            // No directional split available: assume an even split.
            $in = (int) floor($total / 2);
            $out = $total - $in;
        }

        $in ??= 0;
        $out ??= 0;
        if ($in === 0 && $out === 0) {
            return null;
        }

        $rate = $this->rateFor($provider);
        // A token-free local provider (rate 0/0) carries no token cost; let the
        // runtime fallback speak instead of recording a fake $0.00.
        if ($rate['in'] === 0.0 && $rate['out'] === 0.0) {
            return null;
        }

        return ($in / 1000.0) * $rate['in'] + ($out / 1000.0) * $rate['out'];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function runtimeCost(array $input, ?string $provider): ?float
    {
        if ($provider === null || ! in_array($provider, self::RUNTIME_FALLBACK_PROVIDERS, true)) {
            return null;
        }
        $durationMs = $this->nonNegInt($input['duration_ms'] ?? null);
        if ($durationMs === null || $durationMs === 0) {
            return null;
        }

        $perMinute = $this->runtimeRatePerMinute();

        return ($durationMs / 60000.0) * $perMinute;
    }

    /**
     * @return array{in: float, out: float}
     */
    private function rateFor(?string $provider): array
    {
        $rates = $this->configuredTokenRates();
        if ($provider !== null && isset($rates[$provider])) {
            return $rates[$provider];
        }

        return self::DEFAULT_TOKEN_RATE;
    }

    /**
     * @return array<string, array{in: float, out: float}>
     */
    private function configuredTokenRates(): array
    {
        $configured = function_exists('config') ? config('atlas.ai.cost.rates') : null;
        if (! is_array($configured)) {
            return self::TOKEN_RATES_USD_PER_1K;
        }

        $merged = self::TOKEN_RATES_USD_PER_1K;
        foreach ($configured as $key => $rate) {
            if (is_string($key) && is_array($rate) && isset($rate['in'], $rate['out'])
                && is_numeric($rate['in']) && is_numeric($rate['out'])) {
                $merged[strtolower($key)] = ['in' => (float) $rate['in'], 'out' => (float) $rate['out']];
            }
        }

        return $merged;
    }

    private function runtimeRatePerMinute(): float
    {
        $configured = function_exists('config') ? config('atlas.ai.cost.runtime_usd_per_minute') : null;

        return is_numeric($configured) && (float) $configured >= 0
            ? (float) $configured
            : self::RUNTIME_USD_PER_MINUTE;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveProvider(array $input): ?string
    {
        foreach (['provider', 'selected_core', 'model'] as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->normaliseProvider(trim($value));
            }
        }

        return null;
    }

    private function normaliseProvider(string $raw): string
    {
        $raw = strtolower($raw);
        // Match known rate keys by substring so model ids / core labels resolve
        // (e.g. "claude-opus-4", "atlas_dev (codex)", "minimax-m3"). Tier-specific
        // model names are checked FIRST so a precise tier wins over its family —
        // e.g. "claude-opus-4" resolves to "opus" (not the generic "claude").
        $priority = ['opus', 'haiku', 'sonnet', 'minimax', 'hermes'];
        foreach ($priority as $key) {
            if (str_contains($raw, $key)) {
                return $key;
            }
        }
        foreach (array_keys(self::TOKEN_RATES_USD_PER_1K) as $key) {
            if (str_contains($raw, $key)) {
                return $key;
            }
        }

        return $raw;
    }

    private function nonNegInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int < 0 ? null : $int;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
