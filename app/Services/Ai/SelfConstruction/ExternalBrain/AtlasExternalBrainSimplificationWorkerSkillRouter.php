<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Routing gate: not every simplification task should go to the same muscle. High-risk changes,
 * deep-proof work, and boundary/core-logic files need a different worker class than a safe,
 * shallow-proof edit — routing everything to one profile wastes skill and produces avoidable
 * give_backs. This router picks a base profile from risk, proof depth, and file kind, then
 * checks the profile's give_back history for this task pattern: a profile that has already
 * failed this pattern repeatedly is never re-selected — the router routes to the next
 * non-failing profile, or holds for respec if every candidate profile has already failed.
 *
 * Input contract:
 *   risk_level?:          string  ('low'|'medium'|'high', default 'low')
 *   proof_depth?:         string  ('minimal'|'standard'|'deep', default 'minimal')
 *   file_kind?:           string  ('boundary'|'core_logic'|'config'|'test'|..., default '')
 *   give_back_history?:   array<string,int>  (profile => give_back count for this pattern)
 *
 * BASE PROFILE SELECTION (first match wins):
 *   risk_level === 'high'                         → senior_worker
 *   proof_depth === 'deep'                        → proof_worker
 *   file_kind in {boundary, core_logic}            → senior_worker
 *   default                                       → safe_worker
 *
 * A profile is FAILING when give_back_history[profile] >= GIVE_BACK_THRESHOLD. If the base
 * profile is failing, the router falls back through [senior_worker, proof_worker, safe_worker]
 * for the first non-failing candidate; if every candidate is failing, it returns hold.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationWorkerSkillRouter
{
    public const SCHEMA = 'atlas.external_brain.simplification_worker_skill_router.v1';

    public const PROFILE_SAFE_WORKER   = 'safe_worker';
    public const PROFILE_SENIOR_WORKER = 'senior_worker';
    public const PROFILE_PROOF_WORKER  = 'proof_worker';
    public const PROFILE_HOLD          = 'hold';

    public const GIVE_BACK_THRESHOLD = 2;

    /** @var list<string> Fallback candidate order when the base profile is failing. */
    private const FALLBACK_ORDER = [self::PROFILE_SENIOR_WORKER, self::PROFILE_PROOF_WORKER, self::PROFILE_SAFE_WORKER];

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, assigned_profile:string, base_profile:string, avoided_profiles:list<string>}
     */
    public function route(array $facts): array
    {
        $riskLevel  = strtolower(trim((string) ($facts['risk_level'] ?? 'low')));
        $proofDepth = strtolower(trim((string) ($facts['proof_depth'] ?? 'minimal')));
        $fileKind   = strtolower(trim((string) ($facts['file_kind'] ?? '')));
        $giveBackHistory = is_array($facts['give_back_history'] ?? null) ? $facts['give_back_history'] : [];

        $baseProfile = match (true) {
            $riskLevel === 'high' => self::PROFILE_SENIOR_WORKER,
            $proofDepth === 'deep' => self::PROFILE_PROOF_WORKER,
            in_array($fileKind, ['boundary', 'core_logic'], true) => self::PROFILE_SENIOR_WORKER,
            default => self::PROFILE_SAFE_WORKER,
        };

        $avoided = [];

        if (! $this->isFailing($baseProfile, $giveBackHistory)) {
            return [
                'schema'            => self::SCHEMA,
                'assigned_profile'  => $baseProfile,
                'base_profile'      => $baseProfile,
                'avoided_profiles'  => [],
            ];
        }

        $avoided[] = $baseProfile;

        foreach (self::FALLBACK_ORDER as $candidate) {
            if ($candidate === $baseProfile) {
                continue;
            }
            if ($this->isFailing($candidate, $giveBackHistory)) {
                $avoided[] = $candidate;

                continue;
            }

            return [
                'schema'            => self::SCHEMA,
                'assigned_profile'  => $candidate,
                'base_profile'      => $baseProfile,
                'avoided_profiles'  => $avoided,
            ];
        }

        return [
            'schema'            => self::SCHEMA,
            'assigned_profile'  => self::PROFILE_HOLD,
            'base_profile'      => $baseProfile,
            'avoided_profiles'  => array_values(array_unique($avoided)),
        ];
    }

    /** @param  array<string,mixed>  $giveBackHistory */
    private function isFailing(string $profile, array $giveBackHistory): bool
    {
        return (int) ($giveBackHistory[$profile] ?? 0) >= self::GIVE_BACK_THRESHOLD;
    }
}
