<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Earned Autonomy · Gate Composer (the only authority that may signal auto_apply).
 *
 * Composes the five leaf services into a SINGLE deterministic decision over a
 * guard-PASSED self-improvement proposal. It enforces, in this EXACT order, every
 * frozen rule of the earned-autonomy contract; ANY failing check short-circuits to
 * human_gate:
 *
 *   R0 DEFAULT-OFF       — if the kill switch is not armed (flag off and/or no
 *                          operator arm), EVERYTHING is human-gated. This makes the
 *                          whole feature inert by default — byte-identical to the
 *                          proposal-only behaviour shipped today.
 *   R1 INVARIANT CEILING — a gate_or_invariant_touch (rank 3) proposal can NEVER
 *                          auto_apply at ANY tier; it is hard-capped here regardless
 *                          of accrued trust.
 *   R2 DRIFT => REVOKE   — drift_detected forces a revocation to tier 0 + human_gate.
 *   R3 RED-TEAM => REVOKE — a standing red-team breach (any strategy not provably
 *                          blocked by the LIVE guard) forces a revocation + human_gate.
 *   R4 EARNED CEILING    — only when all the above are clean does the composer
 *                          compare the proposal's risk_rank to the earned max-auto
 *                          -rank: auto_apply iff risk_rank <= earned_tier; else
 *                          human_gate.
 *
 * The composer NEVER applies anything itself, NEVER calls a provider, NEVER arms
 * the kill switch — it only READS isArmed(). decision=auto_apply is a SIGNAL the
 * proposal gate may act on under its existing governed apply responsibility; the
 * actual apply remains the caller's. The decision is hashed for audit.
 */
final class EarnedAutonomyGateService
{
    public const SCHEMA = 'atlas.foundry.rsi.earned_autonomy.gate_decision.v1';

    public const DECISION_AUTO_APPLY = 'auto_apply';

    public const DECISION_HUMAN_GATE = 'human_gate';

    private ?RiskClassifierService $riskClassifier;

    private ?TrustLedgerService $trustLedger;

    private ?DriftAnomalyDetectorService $driftDetector;

    private ?KillAuthorityService $killAuthority;

    private ?StandingRedTeamService $redTeam;

    public function __construct(
        ?RiskClassifierService $riskClassifier = null,
        ?TrustLedgerService $trustLedger = null,
        ?DriftAnomalyDetectorService $driftDetector = null,
        ?KillAuthorityService $killAuthority = null,
        ?StandingRedTeamService $redTeam = null,
    ) {
        $this->riskClassifier = $riskClassifier;
        $this->trustLedger = $trustLedger;
        $this->driftDetector = $driftDetector;
        $this->killAuthority = $killAuthority;
        $this->redTeam = $redTeam;
    }

    /**
     * Decide whether a guard-PASSED proposal may be auto-applied under earned
     * autonomy, or must stay human-gated. Evaluates R0..R4 in the exact frozen
     * order; the first failing check short-circuits to human_gate.
     *
     * @param  array<string,mixed>  $proposal  candidate proposal; carries a `diff` descriptor
     * @param  array<string,mixed>  $input  gate context (area_id/focus + flag override seam)
     * @return array<string,mixed>
     */
    public function decide(array $proposal, array $input = []): array
    {
        $riskClassifier = $this->riskClassifier ?? app(RiskClassifierService::class);
        $killAuthority = $this->killAuthority ?? app(KillAuthorityService::class);
        $driftDetector = $this->driftDetector ?? app(DriftAnomalyDetectorService::class);
        $redTeam = $this->redTeam ?? app(StandingRedTeamService::class);
        $trustLedger = $this->trustLedger ?? app(TrustLedgerService::class);

        $diff = is_array($proposal['diff'] ?? null) ? $proposal['diff'] : [];
        $area = (string) ($input['area_id'] ?? '');
        $focus = (string) ($input['focus'] ?? '');
        $context = [
            'area_id' => $area,
            'focus' => $focus,
            'cycle_id' => (string) ($input['cycle_id'] ?? ''),
            'merge_hash' => (string) ($input['merge_hash'] ?? ''),
        ];

        $classification = $riskClassifier->classify($diff);
        $riskClass = (string) ($classification['risk_class'] ?? RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH);
        $riskRank = (int) ($classification['risk_rank'] ?? $riskClassifier->rankOf($riskClass));

        $killArmed = $killAuthority->isArmed($input);

        // (R0 DEFAULT-OFF) kill switch not armed (flag off and/or no operator arm)
        // => human_gate. This makes the whole feature inert by default.
        if (! $killArmed) {
            return $this->emit(
                decision: self::DECISION_HUMAN_GATE,
                riskClass: $riskClass,
                riskRank: $riskRank,
                earnedTier: -1,
                reasons: [$this->reason('kill_disarmed_or_flag_off', 'earned-autonomy kill switch is not armed (flag off and/or no operator arm); proposal stays human-gated')],
                autoApplied: false,
                revoked: false,
                killArmed: false,
                redTeamSurvived: false,
                driftDetected: false,
            );
        }

        // (R1 INVARIANT CEILING) gate_or_invariant_touch can NEVER auto_apply at any
        // tier — hard-capped here regardless of accrued trust.
        if ($riskClass === RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH) {
            return $this->emit(
                decision: self::DECISION_HUMAN_GATE,
                riskClass: $riskClass,
                riskRank: $riskRank,
                earnedTier: -1,
                reasons: [$this->reason('invariant_touch_never_auto', 'proposal is gate_or_invariant_touch (rank 3); can never auto_apply at any tier')],
                autoApplied: false,
                revoked: false,
                killArmed: true,
                redTeamSurvived: false,
                driftDetected: false,
            );
        }

        // (R2 DRIFT => REVOKE) drift detected => revoke accrued trust to tier 0 +
        // human_gate.
        $drift = $driftDetector->detect($context);
        $driftDetected = ($drift['drift_detected'] ?? true) === true;
        if ($driftDetected) {
            $trustLedger->recordRevocation($context, 'drift_anomaly');

            return $this->emit(
                decision: self::DECISION_HUMAN_GATE,
                riskClass: $riskClass,
                riskRank: $riskRank,
                earnedTier: -1,
                reasons: [$this->reason('drift_anomaly_revoke_to_tier_0', 'drift / anomaly detected over the proven value-ledger; accrued trust revoked to tier 0')],
                autoApplied: false,
                revoked: true,
                killArmed: true,
                redTeamSurvived: false,
                driftDetected: true,
            );
        }

        // (R3 RED-TEAM => REVOKE) any standing red-team strategy not provably blocked
        // by the LIVE guard => revoke + human_gate.
        $redTeamResult = $redTeam->attack($proposal, $input);
        $redTeamSurvived = ($redTeamResult['attack_blocked'] ?? false) === true;
        if (! $redTeamSurvived) {
            $trustLedger->recordRevocation($context, 'red_team_breach');

            return $this->emit(
                decision: self::DECISION_HUMAN_GATE,
                riskClass: $riskClass,
                riskRank: $riskRank,
                earnedTier: -1,
                reasons: [$this->reason('red_team_breach_revoke_to_tier_0', 'standing red team breached: a live-guard attack was not provably blocked; accrued trust revoked to tier 0')],
                autoApplied: false,
                revoked: true,
                killArmed: true,
                redTeamSurvived: false,
                driftDetected: false,
            );
        }

        // (R4 EARNED CEILING) all of: killArmed, class != gate_or_invariant_touch,
        // drift clean, red-team survived — all true here. auto_apply iff the risk
        // rank is within the earned max-auto-rank.
        $earnedTier = $trustLedger->earnedTier($area, $focus);

        if ($riskRank <= $earnedTier) {
            return $this->emit(
                decision: self::DECISION_AUTO_APPLY,
                riskClass: $riskClass,
                riskRank: $riskRank,
                earnedTier: $earnedTier,
                reasons: [$this->reason('within_earned_tier', "risk_rank {$riskRank} <= earned max-auto-rank {$earnedTier}; kill armed, drift-clean, red-team survived")],
                autoApplied: true,
                revoked: false,
                killArmed: true,
                redTeamSurvived: true,
                driftDetected: false,
            );
        }

        return $this->emit(
            decision: self::DECISION_HUMAN_GATE,
            riskClass: $riskClass,
            riskRank: $riskRank,
            earnedTier: $earnedTier,
            reasons: [$this->reason('risk_rank_exceeds_earned_tier', "risk_rank {$riskRank} exceeds earned max-auto-rank {$earnedTier}; proposal stays human-gated")],
            autoApplied: false,
            revoked: false,
            killArmed: true,
            redTeamSurvived: true,
            driftDetected: false,
        );
    }

    /**
     * @param  list<array<string,string>>  $reasons
     * @return array<string,mixed>
     */
    private function emit(
        string $decision,
        string $riskClass,
        int $riskRank,
        int $earnedTier,
        array $reasons,
        bool $autoApplied,
        bool $revoked,
        bool $killArmed,
        bool $redTeamSurvived,
        bool $driftDetected,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'risk_class' => $riskClass,
            'risk_rank' => $riskRank,
            'earned_tier' => $earnedTier,
            'reasons' => array_values($reasons),
            'auto_applied' => $autoApplied,
            'revoked' => $revoked,
            'provider_invoked' => false,
            'kill_armed' => $killArmed,
            'red_team_survived' => $redTeamSurvived,
            'drift_detected' => $driftDetected,
        ];

        $payload['decision_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function reason(string $code, string $detail): array
    {
        return ['code' => $code, 'detail' => $detail];
    }
}
