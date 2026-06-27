<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * SCOPE FLAG AUDITOR — read-only inspection of the brain's config flag combination for surprising
 * configurations (e.g. reflection_enabled=true but scope_signal_digest_enabled=false: brain records
 * but doesn't consume; master_switch=ON but reflection_enabled=false: brain runs but blind).
 *
 * Pure + deterministic + read-only. Pétreo.
 */
final class AtlasBrainScopeFlagAuditor
{
    public const SCHEMA = 'atlas.brain.scope_flag_auditor.v1';

    /**
     * @return array{schema:string, oddities:list<array{code:string, advice:string}>}
     */
    public function audit(bool $masterEnabled, bool $reflectionEnabled, bool $digestEnabled, bool $causalSelectorEnabled): array
    {
        $oddities = [];

        if ($reflectionEnabled && ! $digestEnabled) {
            $oddities[] = ['code' => 'recording_without_digesting', 'advice' => 'reflection_enabled=true but scope_signal_digest_enabled=false — brain is recording reflections but not consuming the rich signal payload'];
        }
        if ($masterEnabled && ! $reflectionEnabled) {
            $oddities[] = ['code' => 'master_on_reflection_off', 'advice' => 'master switch ON but reflection_enabled=false — brain runs blind, no perception time-series accumulates'];
        }
        if ($causalSelectorEnabled && ! $reflectionEnabled) {
            $oddities[] = ['code' => 'causal_selector_without_reflection', 'advice' => 'causal_selector_enabled=true but reflection_enabled=false — selector has nothing to learn from'];
        }
        if (! $masterEnabled && $reflectionEnabled) {
            $oddities[] = ['code' => 'reflection_on_master_off', 'advice' => 'reflection_enabled=true but master switch OFF — recording config is armed but origination is inert'];
        }

        return ['schema' => self::SCHEMA, 'oddities' => $oddities];
    }
}
