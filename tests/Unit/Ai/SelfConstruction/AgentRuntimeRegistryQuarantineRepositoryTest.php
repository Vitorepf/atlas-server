<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryQuarantineRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryQuarantineRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function svc(): AgentRuntimeRegistryQuarantineRepository
    {
        return new AgentRuntimeRegistryQuarantineRepository;
    }

    // ── AC2: scope + expiry policy on quarantine ────────────────────────────────

    public function test_quarantine_records_scope_when_supplied(): void
    {
        $repo = $this->svc();
        $result = $repo->quarantine('agent-scope', [
            'code' => 'scope_violation',
            'declared_by' => 'op',
            'scope' => ['task_family:origination', 'capability:code_edit'],
        ]);

        $this->assertSame(['task_family:origination', 'capability:code_edit'], $result['record']['scope']);
    }

    public function test_scope_defaults_to_empty_when_absent(): void
    {
        $repo = $this->svc();
        $result = $repo->quarantine('agent-noscope', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);

        $this->assertSame([], $result['record']['scope']);
    }

    public function test_retry_after_minutes_defines_expiry_policy(): void
    {
        $repo = $this->svc();
        $result = $repo->quarantine('agent-expiry', [
            'code' => 'stale_heartbeat',
            'declared_by' => 'op',
            'retry_after_minutes' => 45,
        ]);

        $this->assertSame(45, $result['record']['retry_after_minutes']);
        $this->assertNotNull($result['record']['retry_after_at']);
    }

    // ── AC3: release requires evidence refs when quarantine opts in ────────────

    public function test_release_blocked_without_evidence_when_require_evidence_for_release(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-strict', [
            'code' => 'evidence_failure',
            'declared_by' => 'op',
            'require_evidence_for_release' => true,
        ]);

        $result = $repo->release('agent-strict', ['reviewer' => 'rev', 'reason' => 'fixed']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('release_evidence_refs_missing', $result['reason']);
        $this->assertTrue($repo->isQuarantined('agent-strict'));
    }

    public function test_release_succeeds_with_evidence_when_require_evidence_for_release(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-strict2', [
            'code' => 'evidence_failure',
            'declared_by' => 'op',
            'require_evidence_for_release' => true,
        ]);

        $result = $repo->release('agent-strict2', [
            'reviewer' => 'rev',
            'reason' => 'fixed',
            'evidence_refs' => ['ledger:receipt-1', 'test:GreenSuiteRun'],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['ledger:receipt-1', 'test:GreenSuiteRun'], $result['record']['release_evidence_refs']);
        $this->assertFalse($repo->isQuarantined('agent-strict2'));
    }

    public function test_release_without_require_evidence_flag_still_works_without_evidence(): void
    {
        // Backward compatibility: quarantines that never opt in must keep the pre-existing
        // reviewer+reason-only release contract unchanged.
        $repo = $this->svc();
        $repo->quarantine('agent-legacy', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);

        $result = $repo->release('agent-legacy', ['reviewer' => 'rev', 'reason' => 'restored']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['record']['release_evidence_refs']);
    }

    public function test_release_history_preserved_never_deleted_after_release(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-history', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $repo->release('agent-history', ['reviewer' => 'rev', 'reason' => 'restored', 'evidence_refs' => ['ev-1']]);

        $records = $repo->list(['only_active' => false]);
        $record = $records[0];

        $this->assertNotEmpty($record['history']);
        $this->assertSame('quarantined', $record['history'][0]['event']);
        $this->assertSame('released', $record['history'][1]['event']);
        $this->assertGreaterThanOrEqual(2, count($record['receipts']));
    }

    // ── AC4: dispatch block distinguishes active, expired, released, unrelated ──

    public function test_dispatch_block_reports_active_status_when_currently_quarantined(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-active', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);

        $result = $repo->dispatchBlock('agent-active');

        $this->assertSame('active', $result['quarantine_status']);
        $this->assertTrue($result['dispatch_block']);
    }

    public function test_dispatch_block_reports_expired_status_after_retry_window_passes(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-expired', [
            'code' => 'stale_heartbeat',
            'declared_by' => 'op',
            'retry_after_minutes' => 10,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

        $result = $repo->dispatchBlock('agent-expired');

        $this->assertSame('expired', $result['quarantine_status']);
        $this->assertFalse($result['dispatch_block'], 'an expired time-based quarantine must not block dispatch');
    }

    public function test_dispatch_block_reports_released_status_after_explicit_release(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-released', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $repo->release('agent-released', ['reviewer' => 'rev', 'reason' => 'restored']);

        $result = $repo->dispatchBlock('agent-released');

        $this->assertSame('released', $result['quarantine_status']);
        $this->assertFalse($result['dispatch_block']);
    }

    public function test_dispatch_block_reports_unrelated_status_for_never_quarantined_target(): void
    {
        $repo = $this->svc();

        $result = $repo->dispatchBlock('agent-never-seen');

        $this->assertSame('unrelated', $result['quarantine_status']);
        $this->assertFalse($result['dispatch_block']);
    }

    public function test_dispatch_block_active_when_retry_window_has_not_yet_passed(): void
    {
        $repo = $this->svc();
        $repo->quarantine('agent-not-yet', [
            'code' => 'stale_heartbeat',
            'declared_by' => 'op',
            'retry_after_minutes' => 30,
        ]);

        $result = $repo->dispatchBlock('agent-not-yet');

        $this->assertSame('active', $result['quarantine_status']);
        $this->assertTrue($result['dispatch_block']);
    }
}
