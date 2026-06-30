<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * §W40 · PROVIDER-SWAP POLICY — the reversible failover brain of substrate sovereignty. It reads the
 * {@see AtlasLoopProviderHealthProbe} ledger (latency/ok-rate) and the {@see AtlasLoopProviderCircuitBreaker}
 * streak for the CURRENTLY-active provider and decides, deterministically:
 *
 *   - HOLD   — the active provider is fine (or degrading but not yet for K_DEGRADE rounds).
 *   - SWAP   — the PRIMARY has been degraded (p95 over ceiling / ok-rate under floor / breaker open) for
 *              K_DEGRADE consecutive rounds → fail over to the next provider in the fallback chain.
 *   - REVERT — while on the fallback, the PRIMARY... no: while swapped, the FALLBACK has been healthy for
 *              K_REVERT consecutive rounds → return to the primary (the swap is reversible, never sticky).
 *
 * Pure FACTS, no action: it only RECOMMENDS; the supervisor applies the choice. State (current active +
 * the two streaks) is file-backed per campaign — like the circuit breaker, no migration. Fail-safe: a state
 * read/write error degrades to HOLD (never breaks provider selection). The ENABLE flag is enforced at the
 * supervisor call-site (so this class stays directly unit-testable); the chain being empty also makes it a
 * no-op (nothing to swap to).
 */
final class AtlasLoopProviderSwapPolicy
{
    public const SCHEMA = 'atlas.loop.provider_swap.v1';

    private const STORAGE_PREFIX = 'atlas/loop/provider-swap';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly ?AtlasLoopProviderHealthProbe $probe = null,
        private readonly ?AtlasLoopProviderCircuitBreaker $breaker = null,
    ) {}

    /** Test seam: redirect the per-campaign state directory to a throwaway path. */
    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Advance the swap state-machine ONE round for $campaignId and recommend an action. Deterministic: same
     * state + same probe window ⇒ same decision.
     *
     * @param  list<string>|array<int,string>  $fallbackChain  ordered fallback provider keys
     * @return array{schema:string, action:string, from_provider:string, to_provider:?string, reason:string, consecutive_rounds:int}
     */
    public function decide(string $campaignId, string $primary, array $fallbackChain): array
    {
        $primary = trim($primary);
        $fallback = $this->firstFallback($fallbackChain, $primary);

        $state = $this->loadState($campaignId);
        $active = $state['current_active'] !== '' ? $state['current_active'] : $primary;

        $window = max(1, (int) config('atlas.loop.provider_swap.window_seconds', 1800));
        $kDegrade = max(1, (int) config('atlas.loop.provider_swap.degrade_rounds', 3));
        $kRevert = max(1, (int) config('atlas.loop.provider_swap.revert_rounds', 3));

        // No fallback to swap to ⇒ permanent HOLD on the primary (reset any stale state).
        if ($fallback === '') {
            $this->saveState($campaignId, ['current_active' => $primary, 'degrade_streak' => 0, 'revert_streak' => 0]);

            return $this->result('hold', $primary, null, 'healthy', 0);
        }

        if ($active !== $fallback) {
            // ON PRIMARY — watch for degradation; swap after K_DEGRADE consecutive degraded rounds.
            $health = $this->health($primary, $window);
            if ($health['degraded']) {
                $degrade = $state['degrade_streak'] + 1;
                if ($degrade >= $kDegrade) {
                    $this->saveState($campaignId, ['current_active' => $fallback, 'degrade_streak' => 0, 'revert_streak' => 0]);

                    return $this->result('swap', $primary, $fallback, $health['reason'], $degrade, $health);
                }
                $this->saveState($campaignId, ['current_active' => $primary, 'degrade_streak' => $degrade, 'revert_streak' => 0]);

                return $this->result('hold', $primary, null, $health['reason'], $degrade, $health);
            }
            $this->saveState($campaignId, ['current_active' => $primary, 'degrade_streak' => 0, 'revert_streak' => 0]);

            return $this->result('hold', $primary, null, 'healthy', 0, $health);
        }

        // ON FALLBACK — watch for sustained recovery; revert after K_REVERT consecutive healthy rounds.
        $health = $this->health($fallback, $window);
        if (! $health['degraded']) {
            $revert = $state['revert_streak'] + 1;
            if ($revert >= $kRevert) {
                $this->saveState($campaignId, ['current_active' => $primary, 'degrade_streak' => 0, 'revert_streak' => 0]);

                return $this->result('revert', $fallback, $primary, 'recovered', $revert, $health);
            }
            $this->saveState($campaignId, ['current_active' => $fallback, 'degrade_streak' => 0, 'revert_streak' => $revert]);

            return $this->result('hold', $fallback, null, 'healthy', $revert, $health);
        }
        // fallback still degraded — stay on it, reset the recovery streak.
        $this->saveState($campaignId, ['current_active' => $fallback, 'degrade_streak' => 0, 'revert_streak' => 0]);

        return $this->result('hold', $fallback, null, $health['reason'], 0, $health);
    }

    /** The provider the policy currently considers active (primary until a swap is persisted). */
    public function activeProvider(string $campaignId, string $primary): string
    {
        $active = $this->loadState($campaignId)['current_active'];

        return $active !== '' ? $active : trim($primary);
    }

    /**
     * @return array{degraded:bool, reason:string, sample_count:int, ok_rate:float, p95_ms:int, breaker_streak:int, evidence_strong:bool, cooldown_active:bool}
     */
    private function health(string $providerKey, int $windowSeconds): array
    {
        $p95Ceiling = max(1, (int) config('atlas.loop.provider_swap.p95_ceiling_ms', 120000));
        $okFloor = (float) config('atlas.loop.provider_swap.ok_rate_floor', 0.6);
        $okMinSamples = max(1, (int) config('atlas.loop.provider_swap.ok_rate_min_samples', 5));
        $breakerThreshold = max(1, (int) config('atlas.loop.provider_swap.degrade_rounds', 3));

        $snapshot = ($this->probe ?? new AtlasLoopProviderHealthProbe)->snapshot($providerKey, $windowSeconds);
        $samples = (int) ($snapshot['sample_count'] ?? 0);
        $ok = $samples > 0 ? (int) ($snapshot['ok_count'] ?? 0) : 0;
        $p95 = (int) ($snapshot['p95_ms'] ?? 0);
        $okRate = $samples > 0 ? round($ok / $samples, 4) : 0.0;
        $evidenceStrong = $samples >= $okMinSamples;
        $cooldownActive = $samples > 0 && ! $evidenceStrong;

        $streak = ($this->breaker ?? new AtlasLoopProviderCircuitBreaker)->streak($providerKey);

        $base = [
            'sample_count' => $samples,
            'ok_rate' => $okRate,
            'p95_ms' => $p95,
            'breaker_streak' => $streak,
            'evidence_strong' => $evidenceStrong,
            'cooldown_active' => $cooldownActive,
        ];

        // No evidence ⇒ not degraded (never swap on an empty window — fail-safe).
        if ($samples > 0) {
            if ($p95 > $p95Ceiling) {
                return array_merge($base, ['degraded' => true, 'reason' => 'p95_breach']);
            }
            if ($evidenceStrong && $okRate < $okFloor) {
                return array_merge($base, ['degraded' => true, 'reason' => 'ok_rate_breach']);
            }
        }

        if ($streak >= $breakerThreshold) {
            return array_merge($base, ['degraded' => true, 'reason' => 'ok_rate_breach']);
        }

        return array_merge($base, ['degraded' => false, 'reason' => 'healthy']);
    }

    /**
     * @param  array<int,string>  $fallbackChain
     */
    private function firstFallback(array $fallbackChain, string $primary): string
    {
        foreach ($fallbackChain as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && $candidate !== $primary) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param  array{sample_count?:int, ok_rate?:float, p95_ms?:int, breaker_streak?:int, evidence_strong?:bool, cooldown_active?:bool}  $healthData
     * @return array{schema:string, action:string, from_provider:string, to_provider:?string, reason:string, consecutive_rounds:int, evidence:array{sample_count:int, ok_rate:float, p95_ms:int, breaker_streak:int, evidence_strong:bool, cooldown_active:bool}}
     */
    private function result(string $action, string $from, ?string $to, string $reason, int $consecutive, array $healthData = []): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'from_provider' => $from,
            'to_provider' => $to,
            'reason' => $reason,
            'consecutive_rounds' => $consecutive,
            'evidence' => [
                'sample_count' => (int) ($healthData['sample_count'] ?? 0),
                'ok_rate' => (float) ($healthData['ok_rate'] ?? 0.0),
                'p95_ms' => (int) ($healthData['p95_ms'] ?? 0),
                'breaker_streak' => (int) ($healthData['breaker_streak'] ?? 0),
                'evidence_strong' => (bool) ($healthData['evidence_strong'] ?? false),
                'cooldown_active' => (bool) ($healthData['cooldown_active'] ?? false),
            ],
        ];
    }

    /**
     * @return array{current_active:string, degrade_streak:int, revert_streak:int}
     */
    private function loadState(string $campaignId): array
    {
        try {
            $path = $this->path($campaignId);
            if (Storage::disk('local')->exists($path)) {
                $decoded = json_decode((string) Storage::disk('local')->get($path), true);
                if (is_array($decoded)) {
                    return [
                        'current_active' => trim((string) ($decoded['current_active'] ?? '')),
                        'degrade_streak' => max(0, (int) ($decoded['degrade_streak'] ?? 0)),
                        'revert_streak' => max(0, (int) ($decoded['revert_streak'] ?? 0)),
                    ];
                }
            }
        } catch (Throwable) {
            // fail-safe: unreadable state ⇒ fresh
        }

        return ['current_active' => '', 'degrade_streak' => 0, 'revert_streak' => 0];
    }

    /**
     * @param  array{current_active:string, degrade_streak:int, revert_streak:int}  $state
     */
    private function saveState(string $campaignId, array $state): void
    {
        try {
            Storage::disk('local')->put($this->path($campaignId), (string) json_encode([
                'schema' => self::SCHEMA,
                'current_active' => trim((string) $state['current_active']),
                'degrade_streak' => max(0, (int) $state['degrade_streak']),
                'revert_streak' => max(0, (int) $state['revert_streak']),
            ]));
        } catch (Throwable) {
            // fail-safe: a state write must never break provider selection
        }
    }

    private function path(string $campaignId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($campaignId)) ?: 'unknown';
        $root = $this->storageRootOverride !== null ? trim($this->storageRootOverride, '/') : self::STORAGE_PREFIX;

        return $root.'/'.$safe.'.json';
    }
}
