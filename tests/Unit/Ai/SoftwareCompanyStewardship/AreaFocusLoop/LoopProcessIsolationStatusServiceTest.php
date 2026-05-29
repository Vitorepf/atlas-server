<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopProcessIsolationStatusService;
use Tests\TestCase;

final class LoopProcessIsolationStatusServiceTest extends TestCase
{
    private function service(): LoopProcessIsolationStatusService
    {
        return app(LoopProcessIsolationStatusService::class);
    }

    public function test_default_reports_l1_isolation_honestly(): void
    {
        // Diagnostic default: the loop runs at L1 (git worktree only); agents run on
        // the host with no real confinement and no sandbox.
        $report = $this->service()->status();

        $this->assertSame(LoopProcessIsolationStatusService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('AP-793', $report['ap_contract']);
        $this->assertSame('LHL-18', $report['slice_id']);
        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $report['status']);
        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $report['isolation_level']);
        $this->assertSame('none', $report['sandbox_provider_status']);
        // Under L1 nothing is confined.
        $this->assertFalse($report['limits']['filesystem']);
        $this->assertFalse($report['limits']['env']);
        $this->assertFalse($report['limits']['network']);
    }

    public function test_horizon_30d_under_l1_blocks_long_horizon(): void
    {
        // A claim of weeks/months under L1 must BLOCK — never dressed as ready.
        $report = $this->service()->status(['horizon' => '30d']);

        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $report['status']);
        $this->assertTrue($report['blocks_long_horizon']);
        $this->assertContains('30d', $report['blocked_horizons']);
        $this->assertContains('long_horizon_requires_l2_process_isolation', $report['blockers']);
        $this->assertSame('stop_long_horizon_requires_l2', $report['next_action']);
    }

    public function test_l2_input_with_30d_horizon_is_not_blocked(): void
    {
        // With a real L2 process-isolated sandbox asserted via the seam, a 30d
        // horizon is reachable.
        $report = $this->service()->status([
            'isolation_level' => LoopProcessIsolationStatusService::STATUS_L2,
            'horizon' => '30d',
        ]);

        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L2, $report['status']);
        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L2, $report['isolation_level']);
        $this->assertFalse($report['blocks_long_horizon']);
        $this->assertSame([], $report['blocked_horizons']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame('continue', $report['next_action']);
    }

    public function test_secrets_not_visible_by_default_means_visible_under_l1(): void
    {
        // Under L1 secrets ARE visible to the agent (host env): secrets-hidden=false.
        $l1 = $this->service()->status();
        $this->assertFalse($l1['limits']['secrets'], 'secrets are NOT hidden under L1');
        $this->assertTrue($l1['secrets_visible']);

        // Under a real L2, secrets are hidden from the agent by default.
        $l2 = $this->service()->status([
            'isolation_level' => LoopProcessIsolationStatusService::STATUS_L2,
        ]);
        $this->assertTrue($l2['limits']['secrets'], 'secrets are hidden under L2');
        $this->assertFalse($l2['secrets_visible']);
    }

    public function test_l2_default_confines_filesystem_env_network_and_has_active_sandbox(): void
    {
        $report = $this->service()->status([
            'isolation_level' => LoopProcessIsolationStatusService::STATUS_L2,
        ]);

        $this->assertSame('active', $report['sandbox_provider_status']);
        $this->assertTrue($report['limits']['filesystem']);
        $this->assertTrue($report['limits']['env']);
        $this->assertTrue($report['limits']['network']);
    }

    public function test_sandbox_claimed_without_l2_is_refused(): void
    {
        // NEGATIVE INVARIANT: an active sandbox cannot be claimed while the level is
        // still L1 — the inflated claim is downgraded to none + warned.
        $report = $this->service()->status([
            'isolation_level' => LoopProcessIsolationStatusService::STATUS_L1,
            'sandbox_provider_status' => 'active',
        ]);

        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $report['status']);
        $this->assertSame('none', $report['sandbox_provider_status']);
        $this->assertContains('sandbox_claimed_without_l2_isolation', $report['warnings']);
    }

    public function test_unknown_isolation_level_falls_back_to_l1_honestly(): void
    {
        // A blank / synthetic / unknown level must never inflate to L2.
        $report = $this->service()->status(['isolation_level' => 'totally_isolated_trust_me']);

        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $report['status']);
    }

    public function test_short_horizon_under_l1_does_not_block(): void
    {
        // A short horizon (e.g. 10 cycles) does not require process isolation.
        $report = $this->service()->status(['horizon' => '10c']);

        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $report['status']);
        $this->assertFalse($report['blocks_long_horizon']);
        $this->assertSame('continue', $report['next_action']);
        // Long horizons are still reported as the set that would be blocked.
        $this->assertContains('30d', $report['blocked_horizons']);
    }

    public function test_limits_overridable_via_input_seam(): void
    {
        $report = $this->service()->status([
            'isolation_level' => LoopProcessIsolationStatusService::STATUS_L2,
            'limits' => ['network' => false],
        ]);

        $this->assertTrue($report['limits']['filesystem']);
        $this->assertFalse($report['limits']['network'], 'explicit seam overrides the L2 default');
    }

    public function test_accepts_isolation_record_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole isolation record under `fixture`; direct
        // keys still win.
        $report = $this->service()->status([
            'fixture' => [
                'isolation_level' => LoopProcessIsolationStatusService::STATUS_L2,
                'horizon' => '30d',
            ],
        ]);

        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L2, $report['status']);
        $this->assertFalse($report['blocks_long_horizon']);

        // Direct key overrides the fixture level (precedence check).
        $override = $this->service()->status([
            'fixture' => ['isolation_level' => LoopProcessIsolationStatusService::STATUS_L2],
            'isolation_level' => LoopProcessIsolationStatusService::STATUS_L1,
            'horizon' => '30d',
        ]);
        $this->assertSame(LoopProcessIsolationStatusService::STATUS_L1, $override['status']);
        $this->assertTrue($override['blocks_long_horizon']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = ['horizon' => '30d'];

        $first = $this->service()->status($input);
        $second = $this->service()->status($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A different isolation level must hash differently AND stably.
        $l2 = ['isolation_level' => LoopProcessIsolationStatusService::STATUS_L2, 'horizon' => '30d'];
        $h1 = $this->service()->status($l2);
        $h2 = $this->service()->status($l2);
        $this->assertSame($h1['report_hash'], $h2['report_hash']);
        $this->assertNotSame($first['report_hash'], $h1['report_hash']);
    }
}
