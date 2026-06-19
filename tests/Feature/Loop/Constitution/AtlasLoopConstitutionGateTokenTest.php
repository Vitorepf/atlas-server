<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateToken;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4 — the merge-time PASS-token binds the gate verdict to the exact tree + battery
 * version + a single-use nonce, so a stale/moved/replayed PASS cannot wave a property_gated edit into main.
 */
final class AtlasLoopConstitutionGateTokenTest extends TestCase
{
    private AtlasLoopConstitutionGateToken $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = new AtlasLoopConstitutionGateToken();
    }

    public function test_a_token_minted_for_a_tree_and_battery_verifies_against_the_same(): void
    {
        $token = $this->t->mint('treeAAA', 'battery111', 'PASS', 'nonce-1');
        $v = $this->t->verify($token, 'treeAAA', 'battery111', 'nonce-1', []);
        $this->assertTrue($v['valid']);
    }

    public function test_a_moved_tree_invalidates_the_token(): void
    {
        $token = $this->t->mint('treeAAA', 'battery111', 'PASS', 'nonce-1');
        // The post-apply tree differs from what the gate judged ⇒ the bind breaks ⇒ no commit.
        $v = $this->t->verify($token, 'treeBBB', 'battery111', 'nonce-1', []);
        $this->assertFalse($v['valid']);
        $this->assertStringContainsString('token_mismatch', $v['reason']);
    }

    public function test_a_bumped_battery_invalidates_an_in_flight_token(): void
    {
        $token = $this->t->mint('treeAAA', 'battery111', 'PASS', 'nonce-1');
        $v = $this->t->verify($token, 'treeAAA', 'battery222', 'nonce-1', []); // battery grew since the gate ran
        $this->assertFalse($v['valid']);
    }

    public function test_a_reject_verdict_can_never_verify_as_pass(): void
    {
        $rejectToken = $this->t->mint('treeAAA', 'battery111', 'REJECT', 'nonce-1');
        $v = $this->t->verify($rejectToken, 'treeAAA', 'battery111', 'nonce-1', []);
        $this->assertFalse($v['valid'], 'verify only accepts a PASS-bound token');
    }

    public function test_a_replayed_or_empty_nonce_is_rejected(): void
    {
        $token = $this->t->mint('treeAAA', 'battery111', 'PASS', 'nonce-1');
        $this->assertFalse($this->t->verify($token, 'treeAAA', 'battery111', 'nonce-1', ['nonce-1'])['valid'], 'consumed nonce');
        $this->assertFalse($this->t->verify($this->t->mint('treeAAA', 'battery111', 'PASS', ''), 'treeAAA', 'battery111', '', [])['valid'], 'empty nonce');
    }
}
