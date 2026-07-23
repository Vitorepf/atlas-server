<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

/**
 * Foundry AP-C · Frontier generator limit-fallback router (Opus 4.8 -> Codex 5.5).
 *
 * Operator mandate: try the BIGGEST-LEAP generator on Opus 4.8 FIRST; on a
 * provider LIMIT / exhaustion / unavailable signal route AUTOMATICALLY to Codex
 * 5.5. This is the ONLY auto-fallback condition — a generic provider failure or
 * an unparseable/empty response is NOT a limit and is surfaced as a hard block
 * (real-or-blocked; the router never fabricates and never silently downgrades a
 * real failure into a fallback).
 *
 * It is itself a FrontierGeneratorPort, so the orchestrator consumes it with no
 * change to the armored, proposal-only, I1-I9-gated pipeline. The router NEVER
 * writes canon/docs/code, NEVER merges, NEVER executes, NEVER auto-approves: it
 * only selects which real generator produced the proposals and records a
 * deterministic, auditable fallback trail in the returned result.
 *
 * Determinism: the routing decision is a pure function of the primary result's
 * provider_limited flag. No clocks, no randomness, no provider side effects in
 * the router itself; the LIVE provider call lives inside each wrapped generator.
 */
final class FrontierGeneratorLimitFallbackRouterService implements FrontierGeneratorPort
{
    public const ROUTE_PRIMARY = 'primary';

    public const ROUTE_FALLBACK = 'fallback';

    public const ROUTE_BLOCKED = 'blocked_no_fallback';

    public function __construct(
        private readonly FrontierGeneratorPort $primary,
        private readonly FrontierGeneratorPort $fallback,
    ) {}

    public function generate(array $dossier, int $count, array $context = []): array
    {
        $primaryResult = $this->primary->generate($dossier, $count, $context);

        // Only a LIMIT signal triggers fallback. Generated => keep primary;
        // a non-limit block => keep the honest primary block (no fallback).
        if (! $this->isProviderLimited($primaryResult)) {
            return $this->stamp(
                $primaryResult,
                route: ($primaryResult['status'] ?? 'blocked') === 'generated'
                    ? self::ROUTE_PRIMARY
                    : self::ROUTE_BLOCKED,
                primaryResult: $primaryResult,
                fallbackInvoked: false,
            );
        }

        // Primary hit a limit — route to the fallback generator automatically.
        $fallbackResult = $this->fallback->generate($dossier, $count, $context);

        return $this->stamp(
            $fallbackResult,
            route: self::ROUTE_FALLBACK,
            primaryResult: $primaryResult,
            fallbackInvoked: true,
        );
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function isProviderLimited(array $result): bool
    {
        if (($result['status'] ?? null) === 'generated') {
            return false;
        }

        return ($result['provider_limited'] ?? false) === true;
    }

    /**
     * Attach the deterministic, append-only fallback audit so the operator (and
     * the measured-or-reverted path) can see exactly why Codex produced — or why
     * the cycle blocked with no fallback.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $primaryResult
     * @return array<string,mixed>
     */
    private function stamp(array $result, string $route, array $primaryResult, bool $fallbackInvoked): array
    {
        $result['fallback_audit'] = [
            'route' => $route,
            'fallback_invoked' => $fallbackInvoked,
            'primary_generator_label' => (string) ($primaryResult['generator_label'] ?? ''),
            'primary_status' => (string) ($primaryResult['status'] ?? ''),
            'primary_provider_limited' => ($primaryResult['provider_limited'] ?? false) === true,
            'primary_limit_error_code' => (string) ($primaryResult['provider_limit_error_code'] ?? ''),
            'effective_generator_label' => (string) ($result['generator_label'] ?? ''),
        ];

        return $result;
    }
}
