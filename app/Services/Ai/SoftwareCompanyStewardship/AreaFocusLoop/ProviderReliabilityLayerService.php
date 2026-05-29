<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-16 — Provider Reliability & Circuit Breakers (AP-809).
 *
 * The 24h loop spends real money on real providers. Over a long horizon a
 * provider/lane will time out, throttle, rot in quality, or breach budget. This
 * service is the reliability read model that answers ONE question per provider:
 *
 *   > Is this provider/lane healthy enough to keep spending calls on it, and if
 *   > not, what is the auditable fallback / degraded posture?
 *
 * It is read-only / deterministic / input-seam driven. It NEVER calls a provider,
 * NEVER touches the network, NEVER runs the loop, NEVER merges, NEVER deletes a
 * branch/worktree, NEVER mutates code. It only DIAGNOSES from seam fixtures.
 *
 * Rules (AP-809):
 *   - a TRANSIENT failure does NOT permanently quarantine a provider (it may retry);
 *   - REPEATED (permanent) failures OPEN the circuit breaker for that provider;
 *   - a fallback route requires an AUDITABLE decision — we record a
 *     `fallback_decision` with an explicit reason, never a silent swap;
 *   - DEGRADED mode lowers risk / concurrency rather than stopping outright;
 *   - a BUDGET breach pauses the loop (budget_breach=true, status never `ok`).
 *
 * Honesty rules (operator does not accept false claims):
 *   - a transient burst is NEVER reported as a permanent fault / quarantine;
 *   - an open circuit is NEVER dressed as `ok`;
 *   - a fallback is NEVER applied without a recorded auditable decision;
 *   - a budget breach is NEVER hidden behind a healthy status.
 *
 * Contract: AP-809; AP-810 build contract slice LHL-16.
 */
final class ProviderReliabilityLayerService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_provider_reliability.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_CIRCUIT_OPEN = 'circuit_open';

    /** Recommended per-provider operating mode (the loop reads this to throttle). */
    public const MODE_NORMAL = 'normal';

    public const MODE_DEGRADED = 'degraded';

    public const MODE_CIRCUIT_OPEN = 'circuit_open';

    /** Circuit breaker states. A transient burst stays `closed`; permanent faults `open`. */
    public const CIRCUIT_CLOSED = 'closed';

    public const CIRCUIT_HALF_OPEN = 'half_open';

    public const CIRCUIT_OPEN = 'open';

    /** Repeated permanent failures at/above this count OPEN the circuit breaker. */
    public const PERMANENT_FAILURE_OPEN_THRESHOLD = 3;

    /** Timeout rate (0..1) at/above which a provider is at least DEGRADED. */
    public const DEGRADED_TIMEOUT_RATE = 0.20;

    /** Model-quality score (0..1) below which a lane is DEGRADED (quality rot). */
    public const DEGRADED_QUALITY_FLOOR = 0.60;

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes an
     * empty fleet (no providers) and reports `ok` with no breach — it never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry the whole reliability
        // snapshot; fold it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        $blockers = [];
        $warnings = [];

        $providerRows = $this->providerRows($input);

        /** @var list<array<string,mixed>> $providers */
        $providers = [];
        $anyCircuitOpen = false;
        $anyDegraded = false;

        foreach ($providerRows as $row) {
            $assessed = $this->assessProvider($row, $warnings);
            $providers[] = $assessed;

            if ($assessed['circuit_state'] === self::CIRCUIT_OPEN) {
                $anyCircuitOpen = true;
                $blockers[] = 'provider_circuit_open:'.$assessed['id'];
            } elseif ($assessed['recommended_mode'] === self::MODE_DEGRADED) {
                $anyDegraded = true;
                $warnings[] = 'provider_degraded:'.$assessed['id'];
            }
        }

        // Budget breach pauses the loop. Honest: a breach NEVER reads as `ok`.
        $budgetBreach = $this->budgetBreached($input, $providers);
        if ($budgetBreach) {
            $blockers[] = 'provider_budget_breach_pauses_loop';
        }

        // A fallback route requires an AUDITABLE decision (explicit reason). A
        // requested-but-unrecorded fallback is refused, never silently applied.
        $fallbackDecision = $this->fallbackDecision($input, $providers, $blockers, $warnings);

        // Status precedence: circuit_open (or unrecorded fallback / budget breach)
        // > degraded > ok. blocked is NEVER dressed as ok.
        if ($anyCircuitOpen || $budgetBreach || ($fallbackDecision !== null && $fallbackDecision['recorded'] === false)) {
            $status = self::STATUS_CIRCUIT_OPEN;
        } elseif ($anyDegraded || ($fallbackDecision !== null && $fallbackDecision['recorded'] === true)) {
            $status = self::STATUS_DEGRADED;
        } else {
            $status = self::STATUS_OK;
        }

        $loopAction = match (true) {
            $budgetBreach => 'pause_loop_budget_breach',
            $anyCircuitOpen => 'route_to_fallback_or_pause',
            $status === self::STATUS_DEGRADED => 'lower_risk_and_concurrency',
            default => 'continue',
        };

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-809',
            'slice_id' => 'LHL-16',
            'status' => $status,
            'reliability_id' => 'prl_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $this->providerFingerprint($providers),
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'providers' => $providers,
            'fallback_decision' => $fallbackDecision,
            'budget_breach' => $budgetBreach,
            'circuit_open_count' => count(array_filter(
                $providers,
                static fn (array $p): bool => $p['circuit_state'] === self::CIRCUIT_OPEN,
            )),
            'degraded_count' => count(array_filter(
                $providers,
                static fn (array $p): bool => $p['recommended_mode'] === self::MODE_DEGRADED,
            )),
            'loop_action' => $loopAction,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $status === self::STATUS_OK ? 'continue' : 'reliability_'.$status,
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- per-provider

    /**
     * Assess one provider/lane row into the canonical provider shape. Pure function
     * of the seam row — no probes.
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function assessProvider(array $row, array &$warnings): array
    {
        $id = trim((string) ($row['id'] ?? ($row['provider'] ?? ($row['lane'] ?? '')))) ?: 'unknown_provider';
        $lane = trim((string) ($row['lane'] ?? ''));

        $transient = max(0, (int) ($row['transient_failures'] ?? ($row['transient'] ?? 0)));
        $permanent = max(0, (int) ($row['permanent_failures'] ?? ($row['permanent'] ?? 0)));
        $timeoutRate = $this->clampRate($row['timeout_rate'] ?? 0.0);
        $costPerCall = max(0.0, (float) ($row['cost_per_call'] ?? 0.0));
        $approxTokenUsage = max(0, (int) ($row['approx_token_usage'] ?? 0));
        $modelQuality = $this->clampRate($row['model_quality_by_lane'] ?? ($row['model_quality'] ?? 1.0), 1.0);
        $rateLimited = (bool) ($row['rate_limited'] ?? false)
            || (int) ($row['rate_limit_remaining'] ?? 1) <= 0
            || (bool) ($row['rate_limits'] ?? false);
        $fallbackAvailable = (bool) ($row['fallback_availability'] ?? ($row['fallback_available'] ?? false));

        // Circuit state. An explicit override wins (e.g. half_open probe state from the
        // breaker), otherwise REPEATED permanent failures open it. A transient burst —
        // however large — NEVER opens the circuit on its own.
        $explicitCircuit = $this->normalizeCircuit($row['circuit_breaker_state'] ?? ($row['circuit_state'] ?? null));
        $openThreshold = max(1, (int) ($row['permanent_failure_open_threshold'] ?? self::PERMANENT_FAILURE_OPEN_THRESHOLD));

        if ($explicitCircuit !== null) {
            $circuitState = $explicitCircuit;
        } elseif ($permanent >= $openThreshold) {
            $circuitState = self::CIRCUIT_OPEN;
        } else {
            $circuitState = self::CIRCUIT_CLOSED;
        }

        if ($transient > 0 && $permanent < $openThreshold && $circuitState === self::CIRCUIT_CLOSED) {
            // Document that the transient burst is tolerated (no quarantine).
            $warnings[] = 'provider_transient_burst_tolerated:'.$id;
        }

        $degraded = $circuitState !== self::CIRCUIT_OPEN && (
            $timeoutRate >= self::DEGRADED_TIMEOUT_RATE
            || $modelQuality < self::DEGRADED_QUALITY_FLOOR
            || $rateLimited
        );

        $recommendedMode = match (true) {
            $circuitState === self::CIRCUIT_OPEN => self::MODE_CIRCUIT_OPEN,
            $degraded => self::MODE_DEGRADED,
            default => self::MODE_NORMAL,
        };

        $reasons = [];
        if ($circuitState === self::CIRCUIT_OPEN) {
            $reasons[] = $explicitCircuit === self::CIRCUIT_OPEN
                ? 'circuit_breaker_open'
                : 'repeated_permanent_failures';
        }
        if ($degraded && $timeoutRate >= self::DEGRADED_TIMEOUT_RATE) {
            $reasons[] = 'timeout_rate_high';
        }
        if ($degraded && $modelQuality < self::DEGRADED_QUALITY_FLOOR) {
            $reasons[] = 'model_quality_degraded';
        }
        if ($degraded && $rateLimited) {
            $reasons[] = 'rate_limited';
        }

        return [
            'id' => $id,
            'lane' => $lane,
            'circuit_state' => $circuitState,
            'transient' => $transient,
            'permanent' => $permanent,
            'timeout_rate' => round($timeoutRate, 4),
            'cost_per_call' => round($costPerCall, 6),
            'approx_token_usage' => $approxTokenUsage,
            'model_quality_by_lane' => round($modelQuality, 4),
            'rate_limited' => $rateLimited,
            'fallback_availability' => $fallbackAvailable,
            'recommended_mode' => $recommendedMode,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    // ---------------------------------------------------------------- budget

    /**
     * A budget breach pauses the loop. Breach is declared by an explicit seam flag,
     * a non-positive remaining budget, or projected spend over the cap.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $providers
     */
    private function budgetBreached(array $input, array $providers): bool
    {
        if ((bool) ($input['budget_breach'] ?? false) || (bool) ($input['budget_exhausted'] ?? false)) {
            return true;
        }

        $budget = $input['budget'] ?? null;
        if (is_array($budget)) {
            $remaining = $budget['remaining_usd'] ?? ($budget['remaining'] ?? null);
            if ($remaining !== null && (float) $remaining <= 0.0) {
                return true;
            }
            $spent = $budget['spent_usd'] ?? ($budget['spent'] ?? null);
            $cap = $budget['cap_usd'] ?? ($budget['cap'] ?? ($budget['limit_usd'] ?? null));
            if ($spent !== null && $cap !== null && (float) $cap > 0.0 && (float) $spent > (float) $cap) {
                return true;
            }
        }

        $remainingTop = $input['budget_remaining_usd'] ?? null;
        if ($remainingTop !== null && (float) $remainingTop <= 0.0) {
            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------- fallback

    /**
     * A fallback route requires an AUDITABLE decision. We return a structured
     * `fallback_decision` recording whether an explicit reason was provided. A
     * requested-but-unrecorded fallback is `recorded=false` and BLOCKS — never a
     * silent swap.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $providers
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>|null
     */
    private function fallbackDecision(array $input, array $providers, array &$blockers, array &$warnings): ?array
    {
        $raw = $input['fallback_decision'] ?? ($input['fallback'] ?? null);

        // Is a fallback even being requested/needed?
        $requested = is_array($raw) && $raw !== [];
        $circuitOpenIds = array_values(array_map(
            static fn (array $p): string => (string) $p['id'],
            array_filter($providers, static fn (array $p): bool => $p['circuit_state'] === self::CIRCUIT_OPEN),
        ));

        if (! $requested && $circuitOpenIds === []) {
            return null;
        }

        $rawArr = is_array($raw) ? $raw : [];
        $from = trim((string) ($rawArr['from'] ?? ($rawArr['from_provider'] ?? (($circuitOpenIds[0] ?? '')))));
        $to = trim((string) ($rawArr['to'] ?? ($rawArr['to_provider'] ?? ($rawArr['fallback_provider'] ?? ''))));
        $reason = trim((string) ($rawArr['reason'] ?? ($rawArr['rationale'] ?? '')));
        $decidedBy = trim((string) ($rawArr['decided_by'] ?? ($rawArr['actor'] ?? '')));

        // Auditable = an explicit non-empty reason AND a concrete target route.
        $recorded = $reason !== '' && $to !== '';

        if (! $recorded) {
            $blockers[] = 'fallback_requires_auditable_decision';
        } else {
            $warnings[] = 'provider_fallback_route_recorded';
        }

        return [
            'requested' => $requested || $circuitOpenIds !== [],
            'recorded' => $recorded,
            'from' => $from,
            'to' => $to !== '' ? $to : null,
            'reason' => $reason !== '' ? $reason : null,
            'decided_by' => $decidedBy !== '' ? $decidedBy : null,
            'triggering_providers' => $circuitOpenIds,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Normalize the provider seam into a list of rows. Accepts `providers`, `lanes`,
     * or a single `provider` map; an absent seam yields an empty fleet (no crash).
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function providerRows(array $input): array
    {
        $candidates = $input['providers'] ?? ($input['lanes'] ?? ($input['provider_stats'] ?? null));

        $rows = [];
        if (is_array($candidates)) {
            foreach ($candidates as $key => $value) {
                if (! is_array($value)) {
                    continue;
                }
                // Allow a map keyed by provider id.
                if (! array_key_exists('id', $value) && ! array_key_exists('provider', $value) && is_string($key)) {
                    $value['id'] = $key;
                }
                $rows[] = $value;
            }
        }

        if ($rows === [] && is_array($input['provider'] ?? null) && $input['provider'] !== []) {
            $rows[] = $input['provider'];
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $providers
     */
    private function providerFingerprint(array $providers): string
    {
        $parts = [];
        foreach ($providers as $p) {
            $parts[] = implode(':', [
                (string) ($p['id'] ?? ''),
                (string) ($p['circuit_state'] ?? ''),
                (string) ($p['recommended_mode'] ?? ''),
                (string) ($p['transient'] ?? 0),
                (string) ($p['permanent'] ?? 0),
            ]);
        }
        sort($parts);

        return implode('|', $parts);
    }

    private function normalizeCircuit(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'open', 'circuit_open', 'tripped' => self::CIRCUIT_OPEN,
            'half_open', 'half-open', 'probing' => self::CIRCUIT_HALF_OPEN,
            'closed', 'ok', 'healthy' => self::CIRCUIT_CLOSED,
            default => null,
        };
    }

    private function clampRate(mixed $value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $f = (float) $value;
        if ($f < 0.0) {
            return 0.0;
        }
        if ($f > 1.0) {
            return 1.0;
        }

        return $f;
    }

    /**
     * A wiring-phase `fixture` may be the reliability snapshot; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
