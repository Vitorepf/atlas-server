<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use RuntimeException;

/**
 * Pure FACT-only bid proposer over a TaskEnvelope + list of ProviderProfiles. Never calls a
 * provider; the bid_hash is deterministic so the Loop can replay byte-identical.
 *
 * Anti-Goodhart: capability_score is computed strictly from the cardinal intersection of required
 * vs declared capabilities — provider self-declared 'strength' fields are ignored. Unknown
 * provider references raise InvalidProviderException to close the silent first-come fallback.
 */
final class AtlasMaestroProviderBidProposer
{
    private const TIER_RANK = ['easy' => 0, 'hard' => 1, 'hardest' => 2];

    private const KIND_MIN_TIER = ['loop' => 'hard', 'maestro' => 'hard', 'cortex' => 'easy'];

    private const KIND_EVIDENCE_BURDEN = ['loop' => 80, 'maestro' => 60, 'cortex' => 40];

    /**
     * @param  list<ProviderProfile>  $profiles
     */
    public function propose(TaskEnvelope $task, array $profiles): BidSet
    {
        $profilesById = [];
        foreach ($profiles as $profile) {
            $profilesById[$profile->providerId] = $profile;
        }

        foreach ($task->providerIds as $providerId) {
            if (! isset($profilesById[$providerId])) {
                throw new InvalidProviderException('unknown_provider:'.$providerId);
            }
        }

        $selectedProfiles = $task->providerIds === []
            ? array_values($profilesById)
            : array_values(array_map(
                static fn (string $providerId): ProviderProfile => $profilesById[$providerId],
                $task->providerIds,
            ));

        // Escalation gate context: an adequate SAFE (local/subscription) profile means any
        // remote/paid profile must justify itself with high task risk before it's eligible —
        // computed once over the whole candidate set, ignoring malformed profiles.
        $hasAdequateSafeProfile = $this->hasAdequateSafeProfile($task, $selectedProfiles);

        $bids = array_map(
            fn (ProviderProfile $profile): ProviderBid => $this->bidFor($task, $profile, $hasAdequateSafeProfile),
            $selectedProfiles,
        );

        // AC: safe (local/subscription) eligible bids sort before higher-cost profiles, so the
        // Loop's first pick is always the cheapest safe option when one is adequate.
        usort($bids, function (ProviderBid $a, ProviderBid $b) use ($profilesById): int {
            $rankA = $this->bidSortRank($a, $profilesById[$a->providerId] ?? null);
            $rankB = $this->bidSortRank($b, $profilesById[$b->providerId] ?? null);

            return $rankA <=> $rankB;
        });

        return new BidSet($bids);
    }

    /** @param list<ProviderProfile> $profiles */
    private function hasAdequateSafeProfile(TaskEnvelope $task, array $profiles): bool
    {
        foreach ($profiles as $profile) {
            if ($this->malformedReasons($profile) !== []) {
                continue;
            }
            if (! $this->isSafeProfile($profile)) {
                continue;
            }
            // Adequate = would be eligible on every rule except the escalation-reason gate itself.
            if ($this->bidFor($task, $profile, false, skipEscalationGate: true)->eligibilityBool) {
                return true;
            }
        }

        return false;
    }

    private function isSafeProfile(ProviderProfile $profile): bool
    {
        return $profile->locality === 'local' || ($profile->extras['client_class'] ?? null) === 'subscription_ui';
    }

    private function bidSortRank(ProviderBid $bid, ?ProviderProfile $profile): array
    {
        $safe = $profile !== null && $this->isSafeProfile($profile);

        return [$bid->eligibilityBool ? 0 : 1, $safe ? 0 : 1, $bid->declaredCostUnits];
    }

    /**
     * @return list<string>
     */
    private function malformedReasons(ProviderProfile $profile): array
    {
        $reasons = [];

        if (trim($profile->providerId) === '') {
            $reasons[] = 'malformed_profile:empty_provider_id';
        }
        if (! in_array($profile->locality, ['local', 'remote'], true)) {
            $reasons[] = 'malformed_profile:invalid_locality:'.$profile->locality;
        }
        if ($profile->observedCostPerTokenIn < 0.0) {
            $reasons[] = 'malformed_profile:negative_cost_per_token_in';
        }
        if ($profile->observedCostPerTokenOut < 0.0) {
            $reasons[] = 'malformed_profile:negative_cost_per_token_out';
        }
        if ($profile->observedP50LatencyMs < 0) {
            $reasons[] = 'malformed_profile:negative_latency';
        }
        if ($profile->currentLoadPct < 0 || $profile->currentLoadPct > 100) {
            $reasons[] = 'malformed_profile:load_pct_out_of_range:'.$profile->currentLoadPct;
        }

        return $reasons;
    }

    private function bidFor(TaskEnvelope $task, ProviderProfile $profile, bool $hasAdequateSafeProfile, bool $skipEscalationGate = false): ProviderBid
    {
        $malformedReasons = $this->malformedReasons($profile);
        if ($malformedReasons !== []) {
            $payload = [
                'provider_id' => $profile->providerId,
                'capability_score' => 0,
                'eligibility_bool' => false,
                'ineligibility_reasons' => $malformedReasons,
                'declared_cost_units' => 0,
                'declared_eta_ms' => 0,
            ];

            return new ProviderBid(
                providerId: $profile->providerId,
                capabilityScore: 0,
                eligibilityBool: false,
                ineligibilityReasons: $malformedReasons,
                declaredCostUnits: 0,
                declaredEtaMs: 0,
                bidHash: hash('sha256', $this->canonicalJson($payload)),
            );
        }

        $capabilityScore = $this->capabilityScore($task, $profile);
        $reasons = [];

        if ($task->localOnly && $profile->locality !== 'local') {
            $reasons[] = 'locality_violation';
        }

        if (! in_array($task->sensitivityClass, $profile->sensitivityAllowed, true)) {
            $reasons[] = 'sensitivity_violation';
        }

        if ($task->requiredCapabilities !== [] && $capabilityScore < 100) {
            $reasons[] = 'capability_missing';
        }

        if ($profile->currentLoadPct >= 100) {
            $reasons[] = 'load_saturated';
        }

        // Tier fit: provider must meet or exceed the minimum tier for this task kind.
        $minTier = self::KIND_MIN_TIER[$task->kind] ?? 'easy';
        $profileTier = (string) ($profile->extras['tier'] ?? 'easy');
        if ((self::TIER_RANK[$profileTier] ?? 0) < (self::TIER_RANK[$minTier] ?? 0)) {
            $reasons[] = 'tier_mismatch';
        }

        // Evidence burden: provider must declare sufficient evidence capacity.
        $burden = self::KIND_EVIDENCE_BURDEN[$task->kind] ?? 50;
        $evidenceMax = (int) ($profile->extras['evidence_burden_max'] ?? 100);
        if ($evidenceMax < $burden) {
            $reasons[] = 'evidence_burden_exceeded';
        }

        // atlas_native must declare local capability proof for non-easy Maestro/Loop work —
        // otherwise it can silently claim hard work it cannot actually perform locally.
        if ($profile->providerId === 'atlas_native'
            && in_array($task->kind, ['loop', 'maestro'], true)
            && (self::TIER_RANK[$minTier] ?? 0) >= (self::TIER_RANK['hard'] ?? 1)
            && empty($profile->extras['atlas_native_capability_proof'])
        ) {
            $reasons[] = 'atlas_native_capability_proof_required';
        }

        // AC: a higher-cost (non-safe) provider needs an explicit escalation justification —
        // either high task risk (non-public sensitivity) or the absence of any adequate safe
        // (local/subscription) alternative — before it is allowed to bid eligibly.
        $isHighRisk = $task->sensitivityClass !== 'public';
        if (! $skipEscalationGate && ! $this->isSafeProfile($profile) && ! $isHighRisk && $hasAdequateSafeProfile) {
            $reasons[] = 'escalation_reason_required';
        }

        $eligibility = $reasons === [];
        $costUnits = $this->declaredCostUnits($task, $profile);
        $etaMs = $this->declaredEtaMs($profile);

        $payload = [
            'provider_id' => $profile->providerId,
            'capability_score' => $capabilityScore,
            'eligibility_bool' => $eligibility,
            'ineligibility_reasons' => array_values($reasons),
            'declared_cost_units' => $costUnits,
            'declared_eta_ms' => $etaMs,
        ];

        return new ProviderBid(
            providerId: $profile->providerId,
            capabilityScore: $capabilityScore,
            eligibilityBool: $eligibility,
            ineligibilityReasons: $reasons,
            declaredCostUnits: $costUnits,
            declaredEtaMs: $etaMs,
            bidHash: hash('sha256', $this->canonicalJson($payload)),
        );
    }

    private function capabilityScore(TaskEnvelope $task, ProviderProfile $profile): int
    {
        $required = array_values(array_unique($task->requiredCapabilities));
        if ($required === []) {
            return 100;
        }

        $declared = array_values(array_unique($profile->declaredCapabilities));
        $intersection = array_values(array_intersect($required, $declared));

        return (int) floor((count($intersection) / count($required)) * 100);
    }

    private function declaredCostUnits(TaskEnvelope $task, ProviderProfile $profile): int
    {
        $estimate = match ($task->kind) {
            'maestro' => ['in' => 600, 'out' => 250],
            'cortex' => ['in' => 450, 'out' => 180],
            'loop' => ['in' => 900, 'out' => 320],
            default => ['in' => 500, 'out' => 200],
        };

        $base = ($estimate['in'] * $profile->observedCostPerTokenIn)
            + ($estimate['out'] * $profile->observedCostPerTokenOut);

        // Recent failure penalty: each failure adds 10% to declared cost.
        $failures = max(0, (int) ($profile->extras['recent_failure_count'] ?? 0));
        $penaltyMultiplier = 1 + ($failures * 0.10);

        return (int) round($base * $penaltyMultiplier, 0);
    }

    private function declaredEtaMs(ProviderProfile $profile): int
    {
        $backpressureMultiplier = 1 + max(0, $profile->currentLoadPct) / 100;

        return (int) round($profile->observedP50LatencyMs * $backpressureMultiplier, 0);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        return (string) json_encode(SharedAtlasMaestroProviderBidProposerSeam::sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

final class InvalidProviderException extends RuntimeException {}
