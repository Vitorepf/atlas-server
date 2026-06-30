<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\AutonomousRuntime;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeSafetyStopGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasAutonomousRuntimeSafetyStopGate: each STOP class triggers action=stop with the named
 * reason; context_freshness blocked alone ⇒ action=hold; clean facts ⇒ action=continue.
 */
final class AtlasAutonomousRuntimeSafetyStopGateTest extends TestCase
{
    private function cleanFacts(): array
    {
        return [
            'verification_court' => ['verdict' => 'passed', 'server_side_green' => true],
            'merge_governor' => ['decision' => 'admitted'],
            'rollback_gate' => ['conformant' => true],
            'malformed_task_sweep' => ['count' => 0],
            'give_back_class' => ['repeated_class' => null, 'repeated_count' => 0],
            'scope_drift' => ['count' => 0],
            'context_freshness' => ['conformant' => true],
        ];
    }

    public function test_clean_facts_yield_continue(): void
    {
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($this->cleanFacts());
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_CONTINUE, $r['action']);
        $this->assertSame([], $r['reasons']);
    }

    public function test_verification_court_red_stops(): void
    {
        $f = $this->cleanFacts();
        $f['verification_court'] = ['verdict' => 'failed', 'server_side_green' => false];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('verification_court_red', $r['reasons']);
    }

    public function test_missing_rollback_stops(): void
    {
        $f = $this->cleanFacts();
        $f['rollback_gate'] = ['conformant' => false];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('rollback_missing', $r['reasons']);
    }

    public function test_malformed_task_sweep_stops(): void
    {
        $f = $this->cleanFacts();
        $f['malformed_task_sweep'] = ['count' => 5, 'sample' => ['pkt-a', 'pkt-b']];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('malformed_task_sweep:5', $r['reasons']);
    }

    public function test_repeated_give_back_class_stops(): void
    {
        $f = $this->cleanFacts();
        $f['give_back_class'] = ['repeated_class' => 'scope_repair_missing_impl', 'repeated_count' => 4];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('repeated_give_back_class:scope_repair_missing_impl:4', $r['reasons']);
    }

    public function test_scope_drift_stops(): void
    {
        $f = $this->cleanFacts();
        $f['scope_drift'] = ['count' => 2];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('scope_drift:2', $r['reasons']);
    }

    public function test_merge_governor_rejected_stops(): void
    {
        $f = $this->cleanFacts();
        $f['merge_governor'] = ['decision' => 'rejected'];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('merge_governor_rejected', $r['reasons']);
    }

    public function test_stale_context_alone_yields_hold(): void
    {
        $f = $this->cleanFacts();
        $f['context_freshness'] = ['conformant' => false, 'blockers' => ['context_pack_stale']];
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->evaluate($f);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_HOLD, $r['action']);
        $this->assertContains('context_freshness_blocked', $r['reasons']);
    }

    // ── resume() tests ──────────────────────────────────────────────────────

    private function goodResumeFacts(array $overrides = []): array
    {
        return $overrides + [
            'atlas_native_resume_proof' => ['verified_at' => '2026-06-30T00:00:00Z', 'source' => 'atlas_native'],
            'queue_health' => true,
            'rollback_readiness' => true,
            'unsafe_release_active' => false,
        ];
    }

    public function test_missing_resume_proof_keeps_stop_closed(): void
    {
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->resume($this->goodResumeFacts(['atlas_native_resume_proof' => null]));
        $this->assertFalse($r['resume_allowed']);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $r['action']);
        $this->assertContains('atlas_native_resume_proof_missing', $r['blockers']);
    }

    public function test_unhealthy_queue_keeps_stop_closed(): void
    {
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->resume($this->goodResumeFacts(['queue_health' => false]));
        $this->assertFalse($r['resume_allowed']);
        $this->assertContains('queue_health_unhealthy', $r['blockers']);
    }

    public function test_rollback_unready_keeps_stop_closed(): void
    {
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->resume($this->goodResumeFacts(['rollback_readiness' => false]));
        $this->assertFalse($r['resume_allowed']);
        $this->assertContains('rollback_unready', $r['blockers']);
    }

    public function test_unsafe_release_active_keeps_stop_closed(): void
    {
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->resume($this->goodResumeFacts(['unsafe_release_active' => true]));
        $this->assertFalse($r['resume_allowed']);
        $this->assertContains('unsafe_release_active', $r['blockers']);
    }

    public function test_complete_resume_proof_opens_to_observe_state(): void
    {
        $r = (new AtlasAutonomousRuntimeSafetyStopGate)->resume($this->goodResumeFacts());
        $this->assertTrue($r['resume_allowed']);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_OBSERVE, $r['action']);
        $this->assertSame([], $r['blockers']);
    }
}
