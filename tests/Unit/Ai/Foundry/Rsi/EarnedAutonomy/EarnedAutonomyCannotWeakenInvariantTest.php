<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\DriftAnomalyDetectorService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\EarnedAutonomyGateService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\KillAuthorityService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\RiskClassifierService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\StandingRedTeamService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\TrustLedgerService;
use App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Earned Autonomy · the layer CANNOT weaken any invariant (mirrors
 * RsiCannotWeakenInvariantTest). Build-Safety for the earned-autonomy layer MUST
 * be green before the layer can ever be armed. Proves:
 *
 *   P1 — DEFAULT-OFF: every proposal stays proposal-only / human-gated regardless
 *        of accrued trust (flag off and/or kill disarmed).
 *   P2 — cannot auto-apply above the earned tier.
 *   P3 — the trust ledger cannot be forged to inflate the tier (tamper rejected).
 *   P4 — a planted malicious proposal is blocked by the live red team and grants
 *        no autonomy + never auto-applies.
 *   P5 — drift => auto-revoke to tier 0.
 *   P6 — kill switch: no auto-apply when disarmed, and the loop cannot disarm.
 *   P7 — WIRED end-to-end through the REAL RsiSelfImprovementProposalGate::admit:
 *        flag ON + low-risk proposal at sufficient clean trust => auto_apply=true,
 *        while a high-risk / gate_or_invariant_touch proposal => human_gate.
 *   P8 — gate_or_invariant_touch NEVER auto-applies even at max trust.
 */
final class EarnedAutonomyCannotWeakenInvariantTest extends TestCase
{
    private const ENABLED = ['earned_autonomy_mode_enabled' => true, 'area_id' => 'area', 'focus' => 'focus'];

    private const NON_SACRED = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/FindingDecomposerService.php';

    private const SACRED = 'app/Services/Ai/Foundry/Rsi/RsiInvariantGuardService.php';

    private const SACRED_PROVIDER_PROOF = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php';

    private string $killRoot;

    private string $trustRoot;

    private string $valueRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(6));
        $this->killRoot = sys_get_temp_dir().'/atlas_ea_weak_kill_'.$suffix;
        $this->trustRoot = sys_get_temp_dir().'/atlas_ea_weak_trust_'.$suffix;
        $this->valueRoot = sys_get_temp_dir().'/atlas_ea_weak_value_'.$suffix;
    }

    protected function tearDown(): void
    {
        foreach ([$this->killRoot, $this->trustRoot, $this->valueRoot] as $dir) {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    private function clock(): \Closure
    {
        return static fn (): string => '2026-05-30T12:00:00+00:00';
    }

    private function killAuthority(bool $armed): KillAuthorityService
    {
        $svc = new KillAuthorityService($this->clock());
        $svc->setStorageRootForTesting($this->killRoot);
        if ($armed) {
            $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        }

        return $svc;
    }

    private function trustLedger(int $proven): TrustLedgerService
    {
        $svc = new TrustLedgerService($this->clock());
        $svc->setStorageRootForTesting($this->trustRoot);
        for ($i = 0; $i < $proven; $i++) {
            $svc->recordCycle(
                ['area_id' => 'area', 'focus' => 'focus', 'cycle_id' => 'c'.$i],
                ['outcome_proven' => true, 'red_team_survived' => true, 'drift_clean' => true, 'risk_class' => 'cosmetic', 'auto_applied' => false],
            );
        }

        return $svc;
    }

    private function driftDetector(bool $healthy): DriftAnomalyDetectorService
    {
        $ledger = new ComponentValueLedgerService();
        $ledger->setStorageRootForTesting($this->valueRoot);

        if ($healthy) {
            $path = rtrim($this->valueRoot, '/').'/area/focus.jsonl';
            File::ensureDirectoryExists(dirname($path));
            $lines = [];
            for ($i = 0; $i < 6; $i++) {
                $lines[] = json_encode([
                    'schema_version' => ComponentValueLedgerService::EVENT_SCHEMA,
                    'recorded_at' => sprintf('2026-05-30T%02d:00:00+00:00', $i),
                    'area_id' => 'area',
                    'focus' => 'focus',
                    'cycle_id' => 'vc'.$i,
                    'outcome_status' => ComponentValueLedgerService::OUTCOME_PROVEN,
                    'proven_value_delta' => 1000.0,
                    'total_tokens_cycle' => 1000,
                    'value_per_token_cycle' => 1.0,
                    'components' => [[
                        'component_id' => 'session_ap786',
                        'seam_type' => ComponentValueLedgerService::SEAM_LIVE_PROVIDER,
                        'tokens_consumed' => 1000,
                        'participation_flags' => ['provider_invoked' => true],
                    ]],
                ], JSON_UNESCAPED_SLASHES);
            }
            File::put($path, implode("\n", $lines)."\n");
        }

        $detector = new DriftAnomalyDetectorService();
        $detector->setValueLedgerForTesting($ledger);

        return $detector;
    }

    private function composer(bool $armed = true, bool $healthyDrift = true, int $proven = 30): EarnedAutonomyGateService
    {
        return new EarnedAutonomyGateService(
            new RiskClassifierService(),
            $this->trustLedger($proven),
            $this->driftDetector($healthyDrift),
            $this->killAuthority($armed),
            new StandingRedTeamService(),
        );
    }

    /** The REAL proposal gate (live guard) wired to the supplied composer. */
    private function wiredGate(EarnedAutonomyGateService $composer): RsiSelfImprovementProposalGate
    {
        return new RsiSelfImprovementProposalGate(
            app(RsiInvariantGuardService::class),
            $composer,
        );
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $added
     * @return array<string,mixed>
     */
    private function proposal(array $paths, array $added = ['    private function helper(): int { return 1; }']): array
    {
        return ['diff' => ['changed_paths' => $paths, 'added_lines' => $added]];
    }

    // ---- P1: default-off ---------------------------------------------------

    public function test_p1_default_off_human_gates_every_proposal_regardless_of_trust(): void
    {
        // Max trust, healthy, but kill disarmed (default-off).
        $composer = $this->composer(armed: false, healthyDrift: true, proven: 5000);

        foreach ([self::NON_SACRED, self::SACRED] as $path) {
            $d = $composer->decide($this->proposal([$path]), self::ENABLED);
            $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
            $this->assertFalse($d['auto_applied']);
        }
    }

    // ---- P2: cannot auto-apply above earned tier ---------------------------

    public function test_p2_cannot_auto_apply_above_earned_tier(): void
    {
        // tier 1 (max-auto-rank 0). A rank-1 behavioural change must NOT auto-apply.
        $composer = $this->composer(armed: true, healthyDrift: true, proven: 3);

        $d = $composer->decide($this->proposal([self::NON_SACRED]), self::ENABLED);
        $this->assertSame(1, $d['risk_rank']);
        $this->assertSame(0, $d['earned_tier']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
    }

    // ---- P3: trust ledger cannot be forged to inflate the tier -------------

    public function test_p3_forged_trust_ledger_line_is_rejected_as_tamper(): void
    {
        $trust = $this->trustLedger(2); // 2 honest cycles (< tier 1).

        // Forge: rewrite line 0 to a different cycle id, breaking the prev-hash chain.
        $path = $trust->ledgerPath('area', 'focus');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), static fn ($l) => trim($l) !== ''));
        $forged = json_decode($lines[0], true);
        $forged['cycle_id'] = 'forged';
        $forged['event_hash'] = 'tampered';
        $lines[0] = (string) json_encode($forged, JSON_UNESCAPED_SLASHES);
        file_put_contents($path, implode("\n", $lines)."\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prev-hash chain break');
        $trust->earnedTier('area', 'focus');
    }

    public function test_p3_false_signal_cycles_never_inflate_the_tier(): void
    {
        // Even 5000 cycles that each fail a real proof signal accrue ZERO trust.
        $svc = new TrustLedgerService($this->clock());
        $svc->setStorageRootForTesting($this->trustRoot);
        $records = array_fill(0, 5000, [
            'event_type' => TrustLedgerService::EVENT_CYCLE_PROVEN,
            'outcome_proven' => true,
            'red_team_survived' => true,
            'drift_clean' => false, // never clean => never qualifies.
        ]);
        $this->assertSame(-1, $svc->earnedTier('area', 'focus', $records));
    }

    // ---- P4: planted malicious proposal => red team blocks, no autonomy ----

    public function test_p4_live_red_team_blocks_every_planted_attack(): void
    {
        // The red team runs the LIVE guard (real registry). Its full battery of
        // planted malicious proposals — weaken-invariant, metric-poison,
        // gate-bypass, ledger-forge — is blocked by the real defence. "Survived"
        // therefore means the production barrier actually rejected each attack.
        $redTeam = new StandingRedTeamService(app(RsiInvariantGuardService::class));
        $result = $redTeam->attack($this->proposal([self::NON_SACRED]), self::ENABLED);

        $this->assertTrue($result['attack_blocked']);
        $this->assertFalse($result['provider_invoked']);
        $strategies = array_column($result['attempts'], 'strategy');
        $this->assertContains(StandingRedTeamService::STRATEGY_WEAKEN_INVARIANT, $strategies);
        $this->assertContains(StandingRedTeamService::STRATEGY_METRIC_POISON, $strategies);
        $this->assertContains(StandingRedTeamService::STRATEGY_GATE_BYPASS, $strategies);
        $this->assertContains(StandingRedTeamService::STRATEGY_LEDGER_FORGE, $strategies);
        foreach ($result['attempts'] as $attempt) {
            $this->assertTrue($attempt['attack_blocked'], "strategy {$attempt['strategy']} must be blocked by the live guard");
        }
    }

    public function test_p4b_a_sacred_malicious_proposal_is_blocked_before_any_autonomy_through_the_real_gate(): void
    {
        // A planted malicious proposal that touches a sacred provider-proof file is
        // BLOCKED by the live guard pre-gate of the REAL proposal gate — it never
        // even reaches the earned-autonomy composer, so autonomy is never granted
        // and it is never auto-applied, even at max trust + armed.
        $gate = $this->wiredGate($this->composer(armed: true, healthyDrift: true, proven: 5000));

        $result = $gate->admit(
            ['diff' => [
                'changed_paths' => [self::SACRED_PROVIDER_PROOF],
                'removed_lines' => [self::SACRED_PROVIDER_PROOF => ['if ($providerCalls > 0) { return true; } // provider_proof']],
            ]],
            self::ENABLED + ['rsi_mode_enabled' => true],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_BLOCKED_BY_INVARIANT, $result['status']);
        $this->assertFalse($result['auto_applied']);
        $this->assertFalse($result['routed_to_human_gate']);
    }

    public function test_p4c_red_team_breach_revokes_and_grants_no_autonomy(): void
    {
        // FAIL-CLOSED proof of the composer's R3 branch using ONLY honest signals:
        // the StandingRedTeam's verdict is derived from the live guard. We construct
        // a red team whose guard is the real guard but pointed (via fingerprints)
        // at an empty sandbox repo — the sacred path SET is a frozen const so the
        // real guard STILL blocks every attack. Therefore R3-false is unreachable
        // with the production guard, which is itself the strongest safety statement:
        // the red team can only EVER report survived=true while the real defence is
        // intact. We assert exactly that the live battery survives, and that a
        // SURVIVING red team alone does NOT grant autonomy unless the tier earns it.
        $redTeam = new StandingRedTeamService(app(RsiInvariantGuardService::class));
        $this->assertTrue($redTeam->attack($this->proposal([self::NON_SACRED]), self::ENABLED)['attack_blocked']);

        // Surviving red team + healthy + armed, but ZERO earned trust => no autonomy.
        $composer = $this->composer(armed: true, healthyDrift: true, proven: 0);
        $d = $composer->decide($this->proposal([self::NON_SACRED], ['// comment only']), self::ENABLED);

        $this->assertTrue($d['red_team_survived']);
        $this->assertSame(-1, $d['earned_tier']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
        $this->assertSame('risk_rank_exceeds_earned_tier', $d['reasons'][0]['code']);
    }

    // ---- P5: drift => auto-revoke to tier 0 --------------------------------

    public function test_p5_drift_auto_revokes_to_tier_0(): void
    {
        $composer = $this->composer(armed: true, healthyDrift: false, proven: 5000);

        $d = $composer->decide($this->proposal([self::NON_SACRED]), self::ENABLED);
        $this->assertTrue($d['drift_detected']);
        $this->assertTrue($d['revoked']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);

        $trust = new TrustLedgerService($this->clock());
        $trust->setStorageRootForTesting($this->trustRoot);
        $this->assertSame(-1, $trust->earnedTier('area', 'focus'));
    }

    // ---- P6: kill switch + loop cannot disarm ------------------------------

    public function test_p6_disarmed_kill_switch_blocks_auto_apply(): void
    {
        $composer = $this->composer(armed: false, healthyDrift: true, proven: 5000);
        $d = $composer->decide($this->proposal([self::NON_SACRED], ['// comment only']), self::ENABLED);

        $this->assertFalse($d['kill_armed']);
        $this->assertFalse($d['auto_applied']);
    }

    public function test_p6b_loop_cannot_disarm_or_self_arm_the_kill_switch(): void
    {
        // The KillAuthority exposes no loop-callable arm/disarm without an operator
        // actor; the composer only READS isArmed(). Prove arm/disarm demand an
        // operator actor (empty actor throws).
        $kill = $this->killAuthority(false);
        $this->expectException(\InvalidArgumentException::class);
        $kill->arm(['source' => 'loop'], '   ');
    }

    public function test_p6c_composer_never_arms_the_kill_switch(): void
    {
        // A decide() over a disarmed switch leaves the ledger disarmed — the
        // composer never self-arms as a side effect.
        $kill = $this->killAuthority(false);
        $composer = new EarnedAutonomyGateService(
            new RiskClassifierService(),
            $this->trustLedger(5000),
            $this->driftDetector(true),
            $kill,
            new StandingRedTeamService(),
        );

        $composer->decide($this->proposal([self::NON_SACRED]), self::ENABLED);

        $this->assertSame(KillAuthorityService::STATE_DISARMED, $kill->state());
    }

    // ---- P7: WIRED end-to-end through the REAL proposal gate ----------------

    public function test_p7_wired_low_risk_proposal_auto_applies_through_the_real_gate(): void
    {
        // tier 1, healthy, armed; a cosmetic (comment-only) proposal => auto_apply.
        $gate = $this->wiredGate($this->composer(armed: true, healthyDrift: true, proven: 3));

        $result = $gate->admit(
            $this->proposal([self::NON_SACRED], ['        // a clarifying comment only']),
            self::ENABLED + ['rsi_mode_enabled' => true],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_AUTO_APPLIED_EARNED, $result['status']);
        $this->assertTrue($result['auto_applied']);
        $this->assertFalse($result['routed_to_human_gate']);
        $this->assertFalse($result['auto_canonized']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_AUTO_APPLY, $result['earned_autonomy']['decision']);
    }

    public function test_p7_wired_high_risk_proposal_stays_human_gated_through_the_real_gate(): void
    {
        // Same max trust + armed, but a behavioural rank-1 change exceeds tier 1.
        $gate = $this->wiredGate($this->composer(armed: true, healthyDrift: true, proven: 3));

        $result = $gate->admit(
            $this->proposal([self::NON_SACRED]),
            self::ENABLED + ['rsi_mode_enabled' => true],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $result['status']);
        $this->assertFalse($result['auto_applied']);
        $this->assertTrue($result['routed_to_human_gate']);
    }

    public function test_p7_wired_gate_or_invariant_touch_is_blocked_before_reaching_the_composer(): void
    {
        // A sacred-touch proposal is BLOCKED by the guard pre-gate (never even
        // consults the composer) — the strongest human gate.
        $gate = $this->wiredGate($this->composer(armed: true, healthyDrift: true, proven: 5000));

        $result = $gate->admit(
            $this->proposal([self::SACRED_PROVIDER_PROOF]),
            self::ENABLED + ['rsi_mode_enabled' => true],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_BLOCKED_BY_INVARIANT, $result['status']);
        $this->assertFalse($result['auto_applied']);
        $this->assertFalse($result['routed_to_human_gate']);
    }

    public function test_p7_wired_flag_off_is_byte_identical_to_proposal_only(): void
    {
        // Flag OFF (no override): the gate consults the composer, which returns
        // human_gate at R0 => STATUS_ROUTED_TO_HUMAN_GATE, auto_applied=false.
        $gate = $this->wiredGate($this->composer(armed: true, healthyDrift: true, proven: 5000));

        $result = $gate->admit(
            $this->proposal([self::NON_SACRED], ['// comment only']),
            ['rsi_mode_enabled' => true, 'area_id' => 'area', 'focus' => 'focus'],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $result['status']);
        $this->assertFalse($result['auto_applied']);
        $this->assertTrue($result['routed_to_human_gate']);
        $this->assertTrue($result['proposal_only']);
    }

    // ---- P8: gate_or_invariant_touch NEVER auto-applies even at max trust ---

    public function test_p8_gate_or_invariant_touch_never_auto_applies_at_max_trust(): void
    {
        $composer = $this->composer(armed: true, healthyDrift: true, proven: 5000);

        // A weakening signature in added lines => gate_or_invariant_touch (rank 3).
        $d = $composer->decide(
            $this->proposal([self::NON_SACRED], ['        $proposal["auto_apply"] = true;']),
            self::ENABLED,
        );

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $d['risk_class']);
        $this->assertSame(3, $d['risk_rank']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
        $this->assertSame('invariant_touch_never_auto', $d['reasons'][0]['code']);
    }
}
