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

        $bids = array_map(
            fn (ProviderProfile $profile): ProviderBid => $this->bidFor($task, $profile),
            $selectedProfiles,
        );

        return new BidSet($bids);
    }

    private function bidFor(TaskEnvelope $task, ProviderProfile $profile): ProviderBid
    {
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

        return (int) round(
            ($estimate['in'] * $profile->observedCostPerTokenIn)
            + ($estimate['out'] * $profile->observedCostPerTokenOut),
            0,
        );
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
        return (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}

final class InvalidProviderException extends RuntimeException {}
