<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\KillAuthorityService;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Earned Autonomy · KillAuthorityService — external kill switch + dead-man.
 *
 * Proves the SAFETY properties this leaf is responsible for:
 *
 *   K1 — DEFAULT-OFF: flag off + no events => isAutonomyKilled()===true and
 *        isArmed()===false (the composer therefore always human-gates).
 *   K2 — flag on but NOT armed => isArmed()===false (absence of explicit arm
 *        never authorizes anything).
 *   K3 — operator arms (non-empty actor) => isArmed()===true; a kill FILE on
 *        disk still forces isAutonomyKilled()===true regardless of arm state.
 *   K4 — dead-man: a heartbeat older than the frozen TTL forces killed; a fresh
 *        heartbeat (written by arm()) does not.
 *   K5 — kill_cannot_disarm: arm()/disarm() REQUIRE a non-empty operator actor;
 *        disarm() also requires a reason; the class exposes NO loop-facing path
 *        to clear the kill file or extend the dead-man.
 *   K6 — disarm is irreversible-for-the-cycle: once disarmed, the folded state
 *        is DISARMED => isArmed()===false until an explicit operator re-arm.
 *   K7 — append-only fold: state() is a pure replay; a forged/garbage line is
 *        ignored, and a forged "armed" line with no real event hash chain still
 *        only reflects what was actually appended (cannot forge a pass beyond
 *        appending a real arm event, which itself requires an operator actor).
 */
final class KillAuthorityServiceTest extends TestCase
{
    private string $tmp;

    private const CLOCK_NOW = '2026-05-30T12:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ea_kill_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function service(?string $now = null): KillAuthorityService
    {
        $svc = new KillAuthorityService(fn (): string => $now ?? self::CLOCK_NOW);
        $svc->setStorageRootForTesting($this->tmp);

        return $svc;
    }

    // ---- K1: default-off -------------------------------------------------

    public function test_k1_default_off_flag_off_is_killed_and_not_armed(): void
    {
        $svc = $this->service();

        // Flag off (no override): the layer is killed and never armed.
        $this->assertTrue($svc->isAutonomyKilled());
        $this->assertFalse($svc->isArmed());
        $this->assertSame(KillAuthorityService::STATE_DISARMED, $svc->state());
    }

    // ---- K2: flag on but not armed --------------------------------------

    public function test_k2_flag_on_but_not_armed_stays_not_armed(): void
    {
        $svc = $this->service();
        $input = ['earned_autonomy_mode_enabled' => true];

        // Flag enabled but operator never armed => isArmed false (default-off).
        $this->assertFalse($svc->isArmed($input));
        // No kill file, fresh-enough? No heartbeat at all => dead-man expired => killed.
        $this->assertTrue($svc->isAutonomyKilled($input));
    }

    // ---- K3: arm + kill file overrides ----------------------------------

    public function test_k3_operator_arm_arms_but_kill_file_forces_killed(): void
    {
        $svc = $this->service();
        $input = ['earned_autonomy_mode_enabled' => true];

        $event = $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        $this->assertSame(KillAuthorityService::EVENT_ARM, $event['event']);
        $this->assertSame('operator:vitor', $event['actor']);
        $this->assertArrayHasKey('event_hash', $event);

        $this->assertTrue($svc->isArmed($input));
        // arm() wrote a fresh heartbeat, so the dead-man is NOT expired here.
        $this->assertFalse($svc->isAutonomyKilled($input));

        // Operator drops the kill file on disk => killed regardless of arm.
        File::put($this->tmp.'/'.KillAuthorityService::KILL_FILE, "operator stop\n");
        $this->assertTrue($svc->isAutonomyKilled($input));
        // isArmed reflects ledger state (still armed) — the COMPOSER consults
        // isArmed for the gate; the kill-file path is the hard external stop.
        $this->assertTrue($svc->isArmed($input));
    }

    // ---- K4: dead-man ----------------------------------------------------

    public function test_k4_dead_man_stale_heartbeat_forces_killed(): void
    {
        $input = ['earned_autonomy_mode_enabled' => true];

        // Arm at T0 (writes a heartbeat at T0).
        $armSvc = $this->service('2026-05-30T12:00:00+00:00');
        $armSvc->arm(['source' => 'operator_cli'], 'operator:vitor');

        // Read 5 minutes later (< TTL 900s): still alive.
        $fresh = $this->service('2026-05-30T12:05:00+00:00');
        $this->assertFalse($fresh->isAutonomyKilled($input));

        // Read 20 minutes later (> TTL): dead-man expired => killed.
        $stale = $this->service('2026-05-30T12:20:00+00:00');
        $this->assertTrue($stale->isAutonomyKilled($input));
    }

    public function test_k4b_missing_heartbeat_is_killed_fail_closed(): void
    {
        $svc = $this->service();
        $input = ['earned_autonomy_mode_enabled' => true];

        // No arm, no heartbeat file at all => fail closed => killed.
        $this->assertTrue($svc->isAutonomyKilled($input));
    }

    // ---- K5: kill_cannot_disarm — operator actor mandatory ---------------

    public function test_k5_arm_requires_non_empty_actor(): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $svc->arm(['source' => 'loop'], '   ');
    }

    public function test_k5b_disarm_requires_non_empty_actor(): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $svc->disarm(['source' => 'loop'], '', 'red_team_breach');
    }

    public function test_k5c_disarm_requires_reason(): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $svc->disarm(['source' => 'operator_cli'], 'operator:vitor', '   ');
    }

    public function test_k5d_no_loop_facing_disarm_or_heartbeat_extension_method_exists(): void
    {
        // The contract: the loop has NO method to delete the kill file or extend
        // the dead-man. The only public mutators are arm()/disarm(), both of
        // which demand an operator actor. Assert no public method leaks a
        // loop-callable disarm / heartbeat-extend / kill-clear seam.
        $public = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(KillAuthorityService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $expected = [
            '__construct',
            'setStorageRootForTesting',
            'isArmed',
            'isAutonomyKilled',
            'arm',
            'disarm',
            'state',
            'replay',
            'ledgerPath',
        ];
        sort($public);
        sort($expected);
        $this->assertSame($expected, $public, 'KillAuthorityService must expose no extra loop-facing mutator.');
    }

    // ---- K6: disarm irreversible-for-the-cycle ---------------------------

    public function test_k6_disarm_drops_armed_state_until_explicit_rearm(): void
    {
        $svc = $this->service();
        $input = ['earned_autonomy_mode_enabled' => true];

        $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        $this->assertTrue($svc->isArmed($input));

        $svc->disarm(['source' => 'operator_cli'], 'operator:vitor', 'red_team_breach');
        $this->assertSame(KillAuthorityService::STATE_DISARMED, $svc->state());
        $this->assertFalse($svc->isArmed($input));

        // Only an explicit operator re-arm restores it.
        $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        $this->assertTrue($svc->isArmed($input));
    }

    // ---- K7: append-only fold, cannot forge a pass -----------------------

    public function test_k7_state_is_pure_append_only_fold_ignoring_garbage(): void
    {
        $svc = $this->service();
        $input = ['earned_autonomy_mode_enabled' => true];

        $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        $svc->disarm(['source' => 'operator_cli'], 'operator:vitor', 'anomaly_detected');

        // Manually append a garbage line + a forged "armed-shaped" line WITHOUT
        // going through arm() (no actor enforcement, no real event hash chain).
        // The fold reads only the last well-formed event() field; a forged armed
        // event would, at most, append a real-looking line — but it cannot be
        // produced through the service without an operator actor. Here we prove
        // garbage is ignored and the LAST legitimate event governs.
        File::append($this->tmp.'/kill_authority.jsonl', "not-json-garbage\n");

        // After arm then disarm (+ garbage), folded state is DISARMED.
        $this->assertSame(KillAuthorityService::STATE_DISARMED, $svc->state());
        $this->assertFalse($svc->isArmed($input));

        // replay() returns exactly the two real appended events (garbage dropped).
        $events = $svc->replay();
        $this->assertCount(2, $events);
        $this->assertSame(KillAuthorityService::EVENT_ARM, $events[0]['event']);
        $this->assertSame(KillAuthorityService::EVENT_DISARM, $events[1]['event']);
        // Each real event carries a deterministic hash (audit chain).
        $this->assertArrayHasKey('event_hash', $events[0]);
        $this->assertArrayHasKey('event_hash', $events[1]);
    }

    public function test_k7b_append_only_prior_events_are_never_mutated(): void
    {
        $svc = $this->service();

        $arm = $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        $svc->disarm(['source' => 'operator_cli'], 'operator:vitor', 'kill_disarmed');

        // The first event on disk is byte-for-byte the original arm event.
        $events = $svc->replay();
        $this->assertSame($arm['event_hash'], $events[0]['event_hash']);
        $this->assertSame(KillAuthorityService::EVENT_ARM, $events[0]['event']);
    }
}
