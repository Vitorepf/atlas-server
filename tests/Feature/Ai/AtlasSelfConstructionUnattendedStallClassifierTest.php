<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionUnattendedStallClassifierTest extends TestCase
{
    private AtlasSelfConstructionUnattendedStallClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AtlasSelfConstructionUnattendedStallClassifier;
    }

    private function classify(array $facts): array
    {
        return $this->classifier->classify(['facts' => $facts]);
    }

    private function healthyFacts(): array
    {
        return [
            'queue'         => ['safety_stop' => false, 'depth' => 5, 'claimable_count' => 3],
            'heartbeat'     => ['is_stale' => false],
            'native_worker' => ['ready' => true],
            'verification'  => ['failed_run_count' => 0],
            'merge'         => ['blocked' => false],
            'replenisher'   => ['last_run_status' => 'ok'],
            'brain_quota'   => [],
        ];
    }

    // ── AC2: unsafe_stop takes precedence over everything ─────────────────────

    public function test_unsafe_stop_overrides_heartbeat_stale(): void
    {
        $facts = $this->healthyFacts();
        $facts['queue']['safety_stop'] = true;
        $facts['heartbeat']['is_stale'] = true;

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP, $r['classification']);
        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::SEVERITY_CRITICAL, $r['severity']);
    }

    public function test_unsafe_stop_overrides_merge_blocked(): void
    {
        $facts = $this->healthyFacts();
        $facts['queue']['safety_stop'] = true;
        $facts['merge']['blocked'] = true;

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP, $r['classification']);
    }

    public function test_unsafe_stop_overrides_verification_blocked(): void
    {
        $facts = $this->healthyFacts();
        $facts['queue']['safety_stop'] = true;
        $facts['verification']['failed_run_count'] = 2;

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP, $r['classification']);
    }

    public function test_heartbeat_stale_takes_precedence_over_merge(): void
    {
        $facts = $this->healthyFacts();
        $facts['heartbeat']['is_stale'] = true;
        $facts['merge']['blocked'] = true;

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE, $r['classification']);
    }

    // ── AC3: brain quota stall reasons classify distinctly, recovery_needed=true

    public function test_stale_brain_heartbeat_classifies_distinctly(): void
    {
        $facts = $this->healthyFacts();
        $facts['brain_quota'] = ['stall_reason' => 'stale_brain_heartbeat'];

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::STALE_BRAIN_HEARTBEAT, $r['classification']);
        $this->assertTrue($r['recovery_needed']);
    }

    public function test_stalled_before_quota_classifies_distinctly(): void
    {
        $facts = $this->healthyFacts();
        $facts['brain_quota'] = ['stall_reason' => 'stalled_before_quota'];

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::STALLED_BEFORE_QUOTA, $r['classification']);
        $this->assertTrue($r['recovery_needed']);
    }

    public function test_temp_spec_already_done_classifies_distinctly(): void
    {
        $facts = $this->healthyFacts();
        $facts['brain_quota'] = ['stall_reason' => 'temp_spec_already_done'];

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::TEMP_SPEC_ALREADY_DONE, $r['classification']);
        $this->assertTrue($r['recovery_needed']);
    }

    public function test_zero_active_brain_commands_classifies_distinctly(): void
    {
        $facts = $this->healthyFacts();
        $facts['brain_quota'] = ['status' => 'running', 'active_brain_commands' => 0];

        $r = $this->classify($facts);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::ZERO_ACTIVE_BRAIN_COMMANDS, $r['classification']);
        $this->assertTrue($r['recovery_needed']);
    }

    // ── AC4: healthy → healthy, severity=none, recovery_needed=false ──────────

    public function test_healthy_snapshot_returns_healthy(): void
    {
        $r = $this->classify($this->healthyFacts());

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $r['classification']);
    }

    public function test_healthy_severity_is_none(): void
    {
        $r = $this->classify($this->healthyFacts());

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::SEVERITY_NONE, $r['severity']);
    }

    public function test_healthy_recovery_not_needed(): void
    {
        $r = $this->classify($this->healthyFacts());

        $this->assertFalse($r['recovery_needed']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = $this->healthyFacts();
        $facts['queue']['safety_stop'] = true;

        $a = $this->classify($facts);
        $b = $this->classify($facts);

        $this->assertSame($a['classifier_hash'], $b['classifier_hash']);
    }

    public function test_schema_is_set(): void
    {
        $r = $this->classify([]);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::SCHEMA, $r['schema']);
    }
}
