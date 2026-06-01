<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasLocalAgentSurfaceService;
use Tests\TestCase;

/**
 * Pins the documented "Fronteira" boundary of the Atlas Local Agent Surface.
 *
 * @see docs/engineering-knowledge-base/atlas-local-agent-surface.md
 */
class AtlasLocalAgentSurfaceTest extends TestCase
{
    private function service(): AtlasLocalAgentSurfaceService
    {
        return new AtlasLocalAgentSurfaceService();
    }

    /** A fully governed + locally ready claim with a heartbeat watchdog => allow. */
    private function goodRequest(): array
    {
        return [
            'job_class' => 'stewardship_loop',
            'readiness' => [
                'agent_online' => true,
                'caffeinate_reconciled' => true,
                'awake_for_session' => true,
            ],
            'watchdogs' => ['heartbeat', 'push'],
            'receipt_signed' => true,
            'policy_allowed' => true,
            'evidence_present' => true,
        ];
    }

    public function test_fully_governed_and_ready_claim_is_allowed(): void
    {
        $r = $this->service()->decideClaim($this->goodRequest());

        $this->assertSame(AtlasLocalAgentSurfaceService::DECISION_ALLOW, $r['decision']);
        $this->assertTrue($r['claim_allowed']);
        $this->assertTrue($r['kernel_governed']);
        $this->assertTrue($r['local_ready']);
        $this->assertSame([], $r['blocking_reasons']);
        $this->assertSame([], $r['readiness_failures']);
    }

    /**
     * Load-bearing invariant: green readiness NEVER bypasses Kernel governance.
     * Readiness fully green, but no signed receipt => still BLOCK on receipt.
     */
    public function test_green_readiness_never_bypasses_missing_receipt(): void
    {
        $req = $this->goodRequest();
        $req['receipt_signed'] = false;

        $r = $this->service()->decideClaim($req);

        $this->assertSame(AtlasLocalAgentSurfaceService::DECISION_BLOCK, $r['decision']);
        $this->assertTrue($r['local_ready']); // readiness is green...
        $this->assertFalse($r['kernel_governed']); // ...yet the claim is denied.
        $invariants = array_column($r['blocking_reasons'], 'invariant');
        $this->assertContains(AtlasLocalAgentSurfaceService::INV_RECEIPT_REQUIRED, $invariants);
    }

    /** Failed local readiness blocks the claim and surfaces the exact signal. */
    public function test_failed_local_readiness_blocks_and_is_surfaced(): void
    {
        $req = $this->goodRequest();
        $req['readiness']['agent_online'] = false;

        $r = $this->service()->decideClaim($req);

        $this->assertSame(AtlasLocalAgentSurfaceService::DECISION_BLOCK, $r['decision']);
        $this->assertFalse($r['local_ready']);
        $this->assertSame('agent_online', $r['readiness_failures'][0]['signal']);
        $invariants = array_column($r['blocking_reasons'], 'invariant');
        $this->assertContains(AtlasLocalAgentSurfaceService::INV_READINESS_LOCAL, $invariants);
    }

    /** Elevation-by-locality is a hard stop even when everything else is green. */
    public function test_local_elevation_request_is_hard_stop(): void
    {
        $req = $this->goodRequest();
        $req['elevate_request'] = true;

        $r = $this->service()->decideClaim($req);

        $this->assertSame(AtlasLocalAgentSurfaceService::DECISION_BLOCK, $r['decision']);
        $invariants = array_column($r['blocking_reasons'], 'invariant');
        $this->assertContains(AtlasLocalAgentSurfaceService::INV_NO_LOCAL_ELEVATION, $invariants);
    }

    /** Asking to hide a readiness failure is itself a blocking violation. */
    public function test_suppressing_readiness_failure_is_blocked(): void
    {
        $req = $this->goodRequest();
        $req['suppress_readiness_failure'] = true;

        $r = $this->service()->decideClaim($req);

        $this->assertSame(AtlasLocalAgentSurfaceService::DECISION_BLOCK, $r['decision']);
        $this->assertFalse($r['local_ready']);
        $invariants = array_column($r['blocking_reasons'], 'invariant');
        $this->assertContains(AtlasLocalAgentSurfaceService::INV_NO_HIDDEN_READINESS, $invariants);
    }

    /** push/inbox cannot be the only watchdog; a heartbeat watchdog is required. */
    public function test_push_inbox_only_watchdog_is_blocked(): void
    {
        $req = $this->goodRequest();
        $req['watchdogs'] = ['push', 'inbox'];

        $r = $this->service()->decideClaim($req);

        $this->assertSame(AtlasLocalAgentSurfaceService::DECISION_BLOCK, $r['decision']);
        $this->assertFalse($r['watchdog_ok']);
        $invariants = array_column($r['blocking_reasons'], 'invariant');
        $this->assertContains(AtlasLocalAgentSurfaceService::INV_WATCHDOG_REQUIRED, $invariants);

        // ...but adding a heartbeat watchdog satisfies the rule.
        $this->assertTrue($this->service()->hasAutonomousWatchdog(['push', 'heartbeat']));
        $this->assertFalse($this->service()->hasAutonomousWatchdog(['push', 'inbox']));
    }
}
