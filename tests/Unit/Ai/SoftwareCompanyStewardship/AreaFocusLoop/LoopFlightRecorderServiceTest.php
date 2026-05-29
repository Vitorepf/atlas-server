<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopFlightRecorderService;
use Tests\TestCase;

final class LoopFlightRecorderServiceTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_lfr_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $this->rmrf($this->storageRoot);
        }
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        foreach ((array) glob($dir.'/*') as $path) {
            if (! is_string($path)) {
                continue;
            }
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function service(): LoopFlightRecorderService
    {
        $service = app(LoopFlightRecorderService::class);
        $service->setStorageRootForTesting($this->storageRoot);

        return $service;
    }

    /**
     * A counted cycle that merged real work, carrying the FULL evidence chain.
     *
     * @return array<string,mixed>
     */
    private function countedCycle(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 3,
            'outcome' => 'merged',
            'candidate' => ['finding_id' => 'AAEOS-001::packet::1'],
            'preflight_ref' => 'lpf_abc123',
            'packet_ref' => 'slice-001',
            'provider_ref' => 'session-zzz',
            'diff_summary' => [
                'files' => ['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'],
                'insertions' => 42,
                'deletions' => 3,
                'commit' => 'deadbeefcafe',
            ],
            'validation_commands' => ['php artisan test', 'git diff --check'],
            'judge_verdict' => 'pass',
            'merge_governor_result' => [
                'merged' => true,
                'merge_target' => 'integration_lane',
                'merge_commit' => 'feed0001',
            ],
            'post_cycle_audit_ref' => 'audit-001',
            'cleanup_ref' => 'cleanup-001',
            'next_state' => 'idle',
            'resource_snapshot' => ['provider_calls' => 1, 'runtime_seconds' => 120],
        ];
    }

    public function test_record_binds_the_full_causal_chain_for_a_counted_cycle(): void
    {
        $report = $this->service()->record($this->countedCycle());

        $this->assertSame(LoopFlightRecorderService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-03', $report['slice_id']);
        $this->assertSame(LoopFlightRecorderService::STATUS_RECORDED, $report['status']);
        $this->assertTrue($report['counted']);
        $this->assertSame([], $report['missing_evidence_refs']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame('continue', $report['next_action']);

        // Every reference in the contract chain is bound.
        $chain = $report['chain'];
        $this->assertSame('lpf_abc123', $chain['preflight_ref']);
        $this->assertSame('AAEOS-001::packet::1', $chain['candidate_ref']);
        $this->assertSame('slice-001', $chain['packet_ref']);
        $this->assertSame('session-zzz', $chain['provider_ref']);
        $this->assertSame('pass', $chain['judge_verdict']);
        $this->assertSame('audit-001', $chain['post_cycle_audit_ref']);
        $this->assertSame('cleanup-001', $chain['cleanup_ref']);
        $this->assertSame('idle', $chain['next_state']);
        $this->assertContains('php artisan test', $chain['validation_commands']);
        $this->assertTrue($chain['merge_governor_result']['merged']);
        $this->assertSame(['provider_calls' => 1, 'runtime_seconds' => 120], $chain['resource_snapshot']);

        // It persisted to the append-only JSONL ledger.
        $this->assertTrue($report['persisted']);
        $this->assertFileExists($report['record_path']);
    }

    public function test_record_flags_incomplete_when_a_counted_cycle_misses_evidence(): void
    {
        $cycle = $this->countedCycle();
        unset($cycle['judge_verdict'], $cycle['post_cycle_audit_ref']);

        $report = $this->service()->record($cycle);

        $this->assertSame(LoopFlightRecorderService::STATUS_INCOMPLETE, $report['status']);
        $this->assertFalse($report['counted'], 'a counted cycle missing evidence must NEVER be counted');
        $this->assertContains('judge_verdict', $report['missing_evidence_refs']);
        $this->assertContains('post_cycle_audit_ref', $report['missing_evidence_refs']);
        $this->assertSame('repair_evidence_chain', $report['next_action']);
        $missingBlocker = array_filter($report['blockers'], static fn (string $b): bool => str_starts_with($b, 'counted_cycle_missing_evidence_refs:'));
        $this->assertNotEmpty($missingBlocker);
    }

    public function test_blocked_cycle_is_recorded_but_never_counted_and_keeps_blocker_and_retry(): void
    {
        $report = $this->service()->record([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 4,
            'outcome' => 'blocked',
            'candidate' => ['finding_id' => 'AAEOS-002'],
            'preflight_ref' => 'lpf_block',
            'blockers' => ['provider_timeout_below_floor'],
            'blocker_reason' => 'provider_timeout_below_floor',
            'retry_policy' => ['retryable' => true, 'max_retries' => 3, 'retries_so_far' => 1, 'backoff' => 'exponential'],
        ]);

        $this->assertSame(LoopFlightRecorderService::STATUS_RECORDED, $report['status']);
        $this->assertFalse($report['counted'], 'a blocked cycle is NEVER counted as success');
        $this->assertSame('blocked', $report['outcome']);
        $this->assertSame('provider_timeout_below_floor', $report['blocker_reason']);
        $this->assertContains('provider_timeout_below_floor', $report['blockers']);
        $this->assertNotNull($report['retry_policy']);
        $this->assertTrue($report['retry_policy']['retryable']);
        $this->assertSame(3, $report['retry_policy']['max_retries']);
        $this->assertSame('retry_or_stop', $report['next_action']);
    }

    public function test_sandbox_commit_is_not_a_merge_and_is_not_counted(): void
    {
        // NEGATIVE INVARIANT: a sandbox commit is not a merge; even if claimed as
        // counted with a full chain, it must not be reported as productive merge.
        $cycle = $this->countedCycle();
        $cycle['outcome'] = 'sandbox_commit';
        $cycle['merge_governor_result'] = ['merged' => true, 'sandbox_only' => true, 'merge_target' => 'sandbox'];
        $cycle['counted'] = true;

        $report = $this->service()->record($cycle);

        $this->assertFalse($report['counted']);
        $this->assertFalse($report['is_productive_outcome']);
        $this->assertFalse($report['chain']['merge_governor_result']['merged'], 'sandbox commit must never report merged=true');
        $this->assertTrue($report['chain']['merge_governor_result']['sandbox_only']);
        $blocker = array_filter($report['blockers'], static fn (string $b): bool => str_starts_with($b, 'non_productive_outcome_claimed_as_counted:'));
        $this->assertNotEmpty($blocker);
    }

    public function test_plan_only_forge_is_not_implementation_and_is_not_counted(): void
    {
        // NEGATIVE INVARIANT: a plan-only Forge path is not real implementation.
        $cycle = $this->countedCycle();
        $cycle['outcome'] = 'plan_only';
        $cycle['counted'] = true;

        $report = $this->service()->record($cycle);

        $this->assertFalse($report['counted']);
        $this->assertFalse($report['is_productive_outcome']);
        $blocker = array_filter($report['blockers'], static fn (string $b): bool => str_starts_with($b, 'non_productive_outcome_claimed_as_counted:plan_only'));
        $this->assertNotEmpty($blocker);
    }

    public function test_starvation_recovery_is_never_counted_as_productivity(): void
    {
        // NEGATIVE INVARIANT: recovery / filler is never productivity.
        $cycle = $this->countedCycle();
        $cycle['outcome'] = 'starvation_recovery';
        $cycle['counted'] = true;

        $report = $this->service()->record($cycle);

        $this->assertFalse($report['counted']);
        $this->assertFalse($report['is_productive_outcome']);
    }

    public function test_explain_returns_the_chain_and_reason_for_a_commit(): void
    {
        $service = $this->service();
        $recorded = $service->record($this->countedCycle());

        $explained = $service->explain([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'commit' => 'deadbeefcafe',
        ]);

        $this->assertTrue($explained['found']);
        $this->assertSame($recorded['flight_record_id'], $explained['flight_record_id']);
        $this->assertSame('merged', $explained['outcome']);
        $this->assertTrue($explained['counted']);
        $this->assertStringContainsString('AAEOS-001::packet::1', $explained['reason']);
        $this->assertStringContainsString('judge=pass', $explained['reason']);
        // The full bound chain is returned so the operator sees WHY it exists.
        $this->assertSame('lpf_abc123', $explained['chain']['preflight_ref']);
        $this->assertSame('session-zzz', $explained['chain']['provider_ref']);
    }

    public function test_explain_returns_the_reason_for_a_blocked_cycle(): void
    {
        $service = $this->service();
        $service->record([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-007',
            'cycle_index' => 9,
            'outcome' => 'blocked',
            'candidate' => ['finding_id' => 'AAEOS-009'],
            'blocker_reason' => 'no_admissible_candidate_backlog_exhausted',
            'retry_policy' => ['retryable' => false, 'max_retries' => 0],
        ]);

        $explained = $service->explain(['run_id' => 'run-007', 'cycle_index' => 9]);

        $this->assertTrue($explained['found']);
        $this->assertFalse($explained['counted']);
        $this->assertStringContainsString('blocked cycle', $explained['reason']);
        $this->assertStringContainsString('no_admissible_candidate_backlog_exhausted', $explained['reason']);
        $this->assertSame('no_admissible_candidate_backlog_exhausted', $explained['blocker_reason']);
        $this->assertNotNull($explained['retry_policy']);
    }

    public function test_explain_can_resolve_via_provider_ref(): void
    {
        $service = $this->service();
        $service->record($this->countedCycle());

        $explained = $service->explain(['provider_ref' => 'session-zzz']);

        $this->assertTrue($explained['found']);
        $this->assertSame('session-zzz', $explained['chain']['provider_ref']);
    }

    public function test_explain_reads_from_an_injected_ledger_seam(): void
    {
        // explain must work purely from an input-seam ledger (no disk dependency).
        $service = $this->service();
        $record = $service->record($this->countedCycle());

        // Point storage somewhere empty; the ledger seam supplies the record.
        $service->setStorageRootForTesting(sys_get_temp_dir().'/atlas_lfr_empty_'.bin2hex(random_bytes(4)));
        $explained = $service->explain([
            'flight_record_id' => $record['flight_record_id'],
            'ledger' => [$record],
        ]);

        $this->assertTrue($explained['found']);
        $this->assertSame($record['flight_record_id'], $explained['flight_record_id']);
    }

    public function test_explain_not_found_is_honest_and_incomplete(): void
    {
        $explained = $this->service()->explain(['commit' => 'does_not_exist']);

        $this->assertFalse($explained['found']);
        $this->assertSame(LoopFlightRecorderService::STATUS_INCOMPLETE, $explained['status']);
        $this->assertContains('flight_record_not_found', $explained['blockers']);
        $this->assertSame('no_flight_record_found_for_query', $explained['reason']);
    }

    public function test_record_is_idempotent_on_the_record_hash(): void
    {
        $service = $this->service();
        $first = $service->record($this->countedCycle());
        $second = $service->record($this->countedCycle());

        $this->assertSame($first['flight_record_id'], $second['flight_record_id']);

        // The ledger must hold exactly one line for the same cycle.
        $lines = array_values(array_filter(
            file($first['record_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            static fn (string $l): bool => $l !== '',
        ));
        $this->assertCount(1, $lines);
    }

    public function test_emits_a_stable_record_hash(): void
    {
        $service = $this->service();
        $cycle = $this->countedCycle();
        $cycle['persist'] = false; // hash determinism does not depend on persistence

        $first = $service->record($cycle);
        $second = $service->record($cycle);

        $this->assertArrayHasKey('record_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['record_hash']);
        $this->assertSame(
            $first['record_hash'],
            $second['record_hash'],
            'same input must produce an identical record_hash (volatile fields stripped)',
        );

        // A blocked cycle hashes stably too AND differs from the counted hash.
        $blocked = ['outcome' => 'blocked', 'candidate' => ['finding_id' => 'X'], 'blocker_reason' => 'b', 'persist' => false];
        $b1 = $service->record($blocked);
        $b2 = $service->record($blocked);
        $this->assertSame($b1['record_hash'], $b2['record_hash']);
        $this->assertNotSame($first['record_hash'], $b1['record_hash']);
    }

    public function test_accepts_cycle_record_via_fixture_input_seam(): void
    {
        $report = $this->service()->record(['fixture' => $this->countedCycle()]);

        $this->assertSame(LoopFlightRecorderService::STATUS_RECORDED, $report['status']);
        $this->assertTrue($report['counted']);
    }

    public function test_default_empty_input_does_not_crash_and_records_incomplete_blocked(): void
    {
        $report = $this->service()->record();

        $this->assertSame(LoopFlightRecorderService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-03', $report['slice_id']);
        $this->assertSame('AP-808', $report['ap_contract']);
        // Empty cycle: not counted, recorded as a (blocked/unknown) non-productive cycle.
        $this->assertFalse($report['counted']);
        $this->assertContains($report['status'], [
            LoopFlightRecorderService::STATUS_RECORDED,
            LoopFlightRecorderService::STATUS_INCOMPLETE,
        ]);
        $this->assertFalse($report['claim_policy']['runs_provider']);
        $this->assertTrue($report['claim_policy']['blocked_never_counted']);
    }
}
