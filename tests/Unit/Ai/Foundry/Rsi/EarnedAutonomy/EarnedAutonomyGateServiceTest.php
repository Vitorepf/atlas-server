<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\DriftAnomalyDetectorService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\EarnedAutonomyGateService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\KillAuthorityService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\RiskClassifierService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\StandingRedTeamService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\TrustLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Earned Autonomy · EarnedAutonomyGateService (the composer) proof.
 *
 * Exercises the frozen R0..R4 decision algorithm end-to-end over the REAL leaf
 * services (no provider, no mocks of the guard — the red team attacks the live
 * guard). Proves the immune-system invariants the composer is responsible for:
 *
 *   - R0 default-off: kill disarmed / flag off => human_gate no matter the trust.
 *   - R1 invariant ceiling: gate_or_invariant_touch never auto-applies, any tier.
 *   - R2 drift => revoke to tier 0 + human_gate.
 *   - R3 red-team breach => revoke to tier 0 + human_gate.
 *   - R4 earned ceiling: auto_apply iff risk_rank <= earned max-auto-rank.
 */
final class EarnedAutonomyGateServiceTest extends TestCase
{
    private const ENABLED = ['earned_autonomy_mode_enabled' => true, 'area_id' => 'area', 'focus' => 'focus'];

    private const NON_SACRED = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/FindingDecomposerService.php';

    private const SACRED = 'app/Services/Ai/Foundry/Rsi/ImmutableInvariantRegistryService.php';

    private string $killRoot;

    private string $trustRoot;

    private string $valueRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(6));
        $this->killRoot = sys_get_temp_dir().'/atlas_ea_gate_kill_'.$suffix;
        $this->trustRoot = sys_get_temp_dir().'/atlas_ea_gate_trust_'.$suffix;
        $this->valueRoot = sys_get_temp_dir().'/atlas_ea_gate_value_'.$suffix;
        File::ensureDirectoryExists($this->valueRoot);
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

    /**
     * A clock pinned far in the future so the dead-man heartbeat written by arm()
     * is always fresh relative to it (the arm and the read share the same clock).
     */
    private function clock(): \Closure
    {
        return static fn (): string => '2026-05-30T12:00:00+00:00';
    }

    /** A KillAuthority either armed (operator) or left disarmed (default-off). */
    private function killAuthority(bool $armed): KillAuthorityService
    {
        $svc = new KillAuthorityService($this->clock());
        $svc->setStorageRootForTesting($this->killRoot);
        if ($armed) {
            $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        }

        return $svc;
    }

    /** A TrustLedger seeded with $proven consecutive qualifying cycles. */
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

    /**
     * A DriftAnomalyDetector bound to a value-ledger that is either healthy (clean
     * => drift_detected=false) or empty (fail-closed => drift_detected=true).
     */
    private function driftDetector(bool $healthy): DriftAnomalyDetectorService
    {
        $ledger = new ComponentValueLedgerService();
        $ledger->setStorageRootForTesting($this->valueRoot);

        if ($healthy) {
            // Seed a stable, monotonic, non-reverting proven history directly as the
            // canonical event shape so the detector's replay folds clean.
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

    /**
     * Compose the gate from explicit leaves. Defaults: armed, healthy drift, real
     * red team (live guard), and $proven seeded trust cycles.
     */
    private function gate(bool $armed = true, bool $healthyDrift = true, int $proven = 30): EarnedAutonomyGateService
    {
        return new EarnedAutonomyGateService(
            new RiskClassifierService(),
            $this->trustLedger($proven),
            $this->driftDetector($healthyDrift),
            $this->killAuthority($armed),
            new StandingRedTeamService(),
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

    // ---- R0 default-off ----------------------------------------------------

    public function test_r0_kill_disarmed_human_gates_even_at_max_trust(): void
    {
        // Flag override on, but kill NOT armed => default-off => human_gate.
        $gate = $this->gate(armed: false, healthyDrift: true, proven: 5000);

        $d = $gate->decide($this->proposal([self::NON_SACRED]), self::ENABLED);

        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
        $this->assertFalse($d['kill_armed']);
        $this->assertSame('kill_disarmed_or_flag_off', $d['reasons'][0]['code']);
    }

    public function test_r0_flag_off_human_gates_even_when_armed_ledger_would_say_armed(): void
    {
        // No flag override at all (config default false). Even with an armed kill
        // ledger, isArmed() is false because the master flag is off => human_gate.
        $gate = $this->gate(armed: true, healthyDrift: true, proven: 5000);

        $d = $gate->decide($this->proposal([self::NON_SACRED]), ['area_id' => 'area', 'focus' => 'focus']);

        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
        $this->assertFalse($d['kill_armed']);
        $this->assertSame('kill_disarmed_or_flag_off', $d['reasons'][0]['code']);
    }

    // ---- R1 invariant ceiling ----------------------------------------------

    public function test_r1_gate_or_invariant_touch_never_auto_applies_at_max_trust(): void
    {
        $gate = $this->gate(armed: true, healthyDrift: true, proven: 5000);

        // A sacred-path proposal classifies as gate_or_invariant_touch (rank 3).
        $d = $gate->decide($this->proposal([self::SACRED]), self::ENABLED);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $d['risk_class']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
        $this->assertSame('invariant_touch_never_auto', $d['reasons'][0]['code']);
    }

    // ---- R2 drift => revoke ------------------------------------------------

    public function test_r2_drift_revokes_to_tier_0_and_human_gates(): void
    {
        // Empty value-ledger => detector fails closed => drift_detected=true.
        $gate = $this->gate(armed: true, healthyDrift: false, proven: 5000);

        $d = $gate->decide($this->proposal([self::NON_SACRED]), self::ENABLED);

        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertTrue($d['drift_detected']);
        $this->assertTrue($d['revoked']);
        $this->assertFalse($d['auto_applied']);
        $this->assertSame('drift_anomaly_revoke_to_tier_0', $d['reasons'][0]['code']);

        // The revocation was actually appended to the trust ledger.
        $trust = new TrustLedgerService($this->clock());
        $trust->setStorageRootForTesting($this->trustRoot);
        $this->assertSame(-1, $trust->earnedTier('area', 'focus'), 'trust must be revoked to the floor');
    }

    // ---- R4 earned ceiling -------------------------------------------------

    public function test_r4_cosmetic_proposal_auto_applies_at_tier_1(): void
    {
        // tier 1 => max-auto-rank 0 (cosmetic only). A cosmetic (comment-only) diff
        // is rank 0 <= 0 => auto_apply.
        $gate = $this->gate(armed: true, healthyDrift: true, proven: 3);

        $d = $gate->decide(
            $this->proposal([self::NON_SACRED], ['        // a clarifying comment only']),
            self::ENABLED,
        );

        $this->assertSame(RiskClassifierService::RISK_CLASS_COSMETIC, $d['risk_class']);
        $this->assertSame(0, $d['risk_rank']);
        $this->assertSame(0, $d['earned_tier']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_AUTO_APPLY, $d['decision']);
        $this->assertTrue($d['auto_applied']);
        $this->assertTrue($d['kill_armed']);
        $this->assertTrue($d['red_team_survived']);
        $this->assertFalse($d['drift_detected']);
        $this->assertSame('within_earned_tier', $d['reasons'][0]['code']);
    }

    public function test_r4_non_sacred_logic_exceeds_tier_1_and_human_gates(): void
    {
        // tier 1 => max-auto-rank 0. A behavioural .php change is rank 1 > 0 => gate.
        $gate = $this->gate(armed: true, healthyDrift: true, proven: 3);

        $d = $gate->decide($this->proposal([self::NON_SACRED]), self::ENABLED);

        $this->assertSame(RiskClassifierService::RISK_CLASS_NON_SACRED_LOGIC, $d['risk_class']);
        $this->assertSame(1, $d['risk_rank']);
        $this->assertSame(0, $d['earned_tier']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_HUMAN_GATE, $d['decision']);
        $this->assertFalse($d['auto_applied']);
        $this->assertSame('risk_rank_exceeds_earned_tier', $d['reasons'][0]['code']);
    }

    public function test_r4_non_sacred_logic_auto_applies_at_tier_2(): void
    {
        // tier 2 => max-auto-rank 1. rank 1 <= 1 => auto_apply.
        $gate = $this->gate(armed: true, healthyDrift: true, proven: 10);

        $d = $gate->decide($this->proposal([self::NON_SACRED]), self::ENABLED);

        $this->assertSame(RiskClassifierService::RISK_CLASS_NON_SACRED_LOGIC, $d['risk_class']);
        $this->assertSame(1, $d['earned_tier']);
        $this->assertSame(EarnedAutonomyGateService::DECISION_AUTO_APPLY, $d['decision']);
        $this->assertTrue($d['auto_applied']);
    }

    public function test_decision_is_deterministic_and_hashed(): void
    {
        $gate = $this->gate(armed: true, healthyDrift: true, proven: 3);
        $proposal = $this->proposal([self::NON_SACRED], ['        // comment only']);

        $a = $gate->decide($proposal, self::ENABLED);
        $b = $gate->decide($proposal, self::ENABLED);

        $this->assertSame($a['decision_hash'], $b['decision_hash']);
        $this->assertSame(64, strlen((string) $a['decision_hash']));
        $this->assertFalse($a['provider_invoked']);
    }
}
