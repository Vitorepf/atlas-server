<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * §4 · PROVIDER CIRCUIT-BREAKER — the operational-ring guard that makes an unattended soak SAFE: when the
 * authoring provider is DOWN across its whole fallback chain (auth 401 / timeout / quota-exhausted), the
 * grinds stop producing candidates but the supervisor would keep spawning them — burning CPU (and the Mac's
 * battery) for hours on guaranteed no-wins, silently. This breaker counts CONSECUTIVE provider-down grinds and
 * OPENS after a threshold, so the supervisor can PAUSE the campaign and alert instead of grinding into a void.
 *
 * THE DISCRIMINATION (the whole point): a provider-DOWN grind is NOT the same as a hard-task no-win. A live
 * provider that just can't solve a hard target still EXPLORES scenarios / emits proposals — that is healthy,
 * it resets the streak. Only a grind that produced NOTHING (no winner, zero scenarios, zero proposals, and not
 * a legitimate backpressure/skip) counts as a provider-down tick. So the breaker trips on outages, never on
 * the loop legitimately failing to win a tough target.
 *
 * File-backed per-campaign (no migration), fail-SAFE (a storage error never breaks a grind — it just doesn't
 * count). Flag-gated at the consumption site (default-OFF ⇒ byte-identical: record-only, never pauses).
 */
final class AtlasLoopProviderCircuitBreaker
{
    private function path(string $campaignId): string
    {
        return 'atlas/loop/circuit-breaker/'.preg_replace('/[^A-Za-z0-9_\-]/', '_', $campaignId).'.json';
    }

    /**
     * Record one grind outcome. A healthy (provider-responded) outcome RESETS the streak to 0; a provider-down
     * outcome increments it. Returns the new consecutive-failure streak. Best-effort (never throws).
     *
     * @param  array<string,mixed>  $outcome  the grinder's terminal result
     */
    public function record(string $campaignId, array $outcome): int
    {
        $campaignId = trim($campaignId);
        if ($campaignId === '') {
            return 0;
        }
        $healthy = self::outcomeIsProviderHealthy($outcome);
        try {
            $streak = $healthy ? 0 : ($this->streak($campaignId) + 1);
            Storage::disk('local')->put($this->path($campaignId), (string) json_encode([
                'schema_version' => 'atlas.loop.circuit_breaker.v1',
                'consecutive_provider_failures' => $streak,
                'last_outcome_healthy' => $healthy,
            ]));

            return $streak;
        } catch (Throwable) {
            return 0; // fail-safe: a breaker write must never break a grind
        }
    }

    /** The current consecutive provider-failure streak (0 when absent/unreadable). */
    public function streak(string $campaignId): int
    {
        try {
            $path = $this->path(trim($campaignId));
            if (! Storage::disk('local')->exists($path)) {
                return 0;
            }
            $decoded = json_decode((string) Storage::disk('local')->get($path), true);

            return max(0, (int) ($decoded['consecutive_provider_failures'] ?? 0));
        } catch (Throwable) {
            return 0;
        }
    }

    /** Is the breaker OPEN — has the provider been down for >= threshold consecutive grinds? */
    public function isOpen(string $campaignId, int $threshold): bool
    {
        return $this->streak($campaignId) >= max(1, $threshold);
    }

    /** Close the breaker (e.g. on resume, or when the provider recovers). */
    public function reset(string $campaignId): void
    {
        try {
            $path = $this->path(trim($campaignId));
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * A grind outcome is "provider healthy" when the provider clearly RESPONDED — it won, explored scenarios,
     * or emitted proposals — OR the outcome is a legitimate non-provider terminal (backpressure / skip). Only a
     * truly empty no-win (no winner, 0 scenarios, 0 proposals) signals the provider produced nothing = down.
     *
     * @param  array<string,mixed>  $outcome
     */
    public static function outcomeIsProviderHealthy(array $outcome): bool
    {
        $status = strtolower(trim((string) ($outcome['status'] ?? '')));

        // Legitimate NON-provider terminals — never count as an outage.
        if (in_array($status, ['winner', 'backpressure', 'skipped', 'merged'], true)) {
            return true;
        }
        if (($outcome['has_winner'] ?? false) === true) {
            return true;
        }
        // The provider DID respond if it produced any work product.
        if ((int) ($outcome['scenarios_explored'] ?? 0) > 0 || (int) ($outcome['proposals'] ?? 0) > 0) {
            return true;
        }

        // No winner, nothing explored, nothing proposed, not a legit skip ⇒ the provider produced nothing.
        return false;
    }
}
