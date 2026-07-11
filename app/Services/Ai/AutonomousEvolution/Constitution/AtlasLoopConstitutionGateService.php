<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

/**
 * LOOP-OS · FASE 3 · SLICE 4 — the ConstitutionGate service: turns the candidate's bytes into a verdict +
 * PASS-token (pétreo / FORBIDDEN under Constitution/). This is what {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard}::admit()
 * routes a property_gated file to instead of a silent unlock.
 *
 * Each property_gated SURFACE has its own weakening vector, so the gate dispatches per surface:
 *  - config/atlas.php — the only weakening is a MONOTONICITY violation (a safety gate flipped off / a
 *    threshold raised); {@see admitConfig} runs the §3.7 data check and is COMPLETE for this surface today.
 *  - the cert-chain closure / mutation operators / selector (the JUDGE surface) — the weakening is a BLINDER;
 *    {@see admitJudge} must run the candidate's judge bytes through the {@see AtlasLoopBatteryRunner} against
 *    the frozen battery (∀ bad REFUTED / ∀ good CERTIFIED / ∀ robust HELD). The candidate-bytes substrate +
 *    the §3.6(i) probe + the RobustnessProbe are built and proven; the §3.6(ii) judge-execution leg
 *    (clone-local autoload so the candidate's edited judge loads) is the remaining integration, so this path
 *    is fail-closed (REJECT) until it lands — it never returns a PASS it cannot back.
 *
 * On PASS the service mints the §3.5 token bound to the candidate tree + battery root; the pétreo actuator
 * re-verifies it under lock before committing.
 */
final class AtlasLoopConstitutionGateService
{
    public const VERDICT_PASS = 'PASS';

    public const VERDICT_REJECT = 'REJECT';

    public function __construct(
        private readonly ?AtlasLoopConstitutionResolveFlags $resolveFlags = null,
        private readonly ?AtlasLoopConstitutionGateToken $token = null,
    ) {}

    /**
     * Gate a candidate edit to config/atlas.php. COMPLETE: a monotonic candidate PASSES (with a token); a
     * candidate that disables a safety gate or raises a gate-disabling threshold is REJECTED. The candidate
     * config is read by literal require with .env excluded (§3.7).
     *
     * @return array{verdict:string, reason:string, violations?:list<string>, token:?string, nonce:?string}
     */
    public function admitConfig(string $liveConfigPath, string $candidateConfigPath, string $candidateTreeSha, string $batteryRootHash, string $nonce): array
    {
        $resolver = $this->resolveFlags();
        $candidate = $resolver->resolve($candidateConfigPath);
        if ($candidate === []) {
            return ['verdict' => self::VERDICT_REJECT, 'reason' => 'candidate_config_unreadable (fail-closed)', 'token' => null, 'nonce' => null];
        }
        $mono = $resolver->monotonicityCheck($resolver->resolve($liveConfigPath), $candidate);
        if (! $mono['ok']) {
            return ['verdict' => self::VERDICT_REJECT, 'reason' => 'config_monotonicity_violation', 'violations' => $mono['violations'], 'token' => null, 'nonce' => null];
        }

        return [
            'verdict' => self::VERDICT_PASS,
            'reason' => 'config_monotonic',
            'token' => $this->token()->mint($candidateTreeSha, $batteryRootHash, self::VERDICT_PASS, $nonce),
            'nonce' => $nonce,
        ];
    }

    /**
     * Gate a candidate edit to a JUDGE file (cert-chain closure / mutation operators / selector). Fail-closed
     * until the §3.6(ii) judge-execution leg (clone-local autoload) lands: it must run the CANDIDATE's judge
     * against the battery and confirm ∀ bad REFUTED before any PASS. It NEVER returns a PASS it cannot back —
     * a blinder must never slip through a not-yet-built check.
     *
     * @return array{verdict:string, reason:string, token:?string, nonce:?string}
     */
    public function admitJudge(string $candidateDiff, string $repoRoot, string $batteryDir): array
    {
        // The candidate-bytes probe (§3.6(i)) + RobustnessProbe are proven; the §3.6(ii) bad/good
        // judge-execution leg is the remaining integration. Until then, a judge self-edit is REJECTED
        // (fail-closed) — the door stays shut, never a false PASS.
        return [
            'verdict' => self::VERDICT_REJECT,
            'reason' => 'judge_refute_leg_not_yet_dischargeable (§3.6(ii) pending; fail-closed — never a false PASS)',
            'token' => null,
            'nonce' => null,
        ];
    }

    private function resolveFlags(): AtlasLoopConstitutionResolveFlags
    {
        return $this->resolveFlags ?? new AtlasLoopConstitutionResolveFlags;
    }

    private function token(): AtlasLoopConstitutionGateToken
    {
        return $this->token ?? new AtlasLoopConstitutionGateToken;
    }
}
