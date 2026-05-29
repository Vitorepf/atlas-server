<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TransactionalCycleStateService;
use Tests\TestCase;

final class TransactionalCycleStateServiceTest extends TestCase
{
    private string $storageRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_lhl07_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->storageRoot !== '' && is_dir($this->storageRoot)) {
            $this->rmrf($this->storageRoot);
        }
        parent::tearDown();
    }

    private function service(): TransactionalCycleStateService
    {
        $service = app(TransactionalCycleStateService::class);
        $service->setStorageRootForTesting($this->storageRoot);

        return $service;
    }

    /**
     * @return array<string,mixed>
     */
    private function identity(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 1,
        ];
    }

    public function test_legal_sequence_advances_and_appends_each_state(): void
    {
        $service = $this->service();
        $legalOrder = [
            TransactionalCycleStateService::STATE_PREFLIGHTED,
            TransactionalCycleStateService::STATE_EXECUTING,
            TransactionalCycleStateService::STATE_VALIDATED,
            TransactionalCycleStateService::STATE_JUDGED,
            TransactionalCycleStateService::STATE_MERGED_OR_BLOCKED,
            TransactionalCycleStateService::STATE_AUDITED,
            TransactionalCycleStateService::STATE_CLEANED,
        ];

        $step = 0;
        foreach ($legalOrder as $target) {
            $input = $this->identity();
            $input['target_state'] = $target;
            if ($target === TransactionalCycleStateService::STATE_MERGED_OR_BLOCKED) {
                $input['outcome'] = 'merged';
            }
            $report = $service->transition($input);

            $this->assertSame(TransactionalCycleStateService::STATUS_OK, $report['status'], "advancing to {$target} should be ok");
            $this->assertTrue($report['applied'], "advancing to {$target} should append a durable row");
            $this->assertSame($target, $report['state']);
            $this->assertSame(++$step, $report['durable_row_count']);
        }

        // The durable ledger now reads back as the terminal state.
        $load = $service->load($this->identity());
        $this->assertSame(TransactionalCycleStateService::STATE_CLEANED, $load['state']);
        $this->assertTrue($load['is_terminal']);
        $this->assertSame(count($legalOrder), $load['durable_row_count']);
        $this->assertSame('merged', $load['outcome']);

        // A real durable JSONL file was appended.
        $path = $service->recordPath('agentic_engineering_os', 'dev_forge', 'run-001', 1);
        $this->assertFileExists($path);
        $this->assertSame(count($legalOrder), count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    }

    public function test_default_target_advances_one_legal_step(): void
    {
        // With no explicit target, a fresh cycle (planned) advances exactly to preflighted.
        $report = $this->service()->transition($this->identity());

        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $report['status']);
        $this->assertSame(TransactionalCycleStateService::STATE_PLANNED, $report['from_state']);
        $this->assertSame(TransactionalCycleStateService::STATE_PREFLIGHTED, $report['state']);
        $this->assertTrue($report['applied']);
    }

    public function test_illegal_skip_planned_to_validated_is_rejected_fail_closed(): void
    {
        $input = $this->identity();
        $input['current_state'] = TransactionalCycleStateService::STATE_PLANNED;
        $input['target_state'] = TransactionalCycleStateService::STATE_VALIDATED;

        $report = $this->service()->transition($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertFalse($report['applied']);
        // No advance: state stays at the current state.
        $this->assertSame(TransactionalCycleStateService::STATE_PLANNED, $report['state']);
        $this->assertContains('cannot_skip_preflighted', $report['blockers']);
        $this->assertSame('stop_fail_closed', $report['next_action']);
    }

    public function test_cannot_skip_preflighted_under_any_target(): void
    {
        // AP-807: preflighted can never be skipped regardless of how far the skip jumps.
        foreach ([
            TransactionalCycleStateService::STATE_EXECUTING,
            TransactionalCycleStateService::STATE_JUDGED,
            TransactionalCycleStateService::STATE_CLEANED,
        ] as $target) {
            $input = $this->identity();
            $input['current_state'] = TransactionalCycleStateService::STATE_PLANNED;
            $input['target_state'] = $target;

            $report = $this->service()->transition($input);

            $this->assertSame(TransactionalCycleStateService::STATUS_FAIL_CLOSED, $report['status'], "skipping to {$target} must fail closed");
            $this->assertFalse($report['applied']);
            $this->assertContains('cannot_skip_preflighted', $report['blockers']);
        }
    }

    public function test_backward_transition_is_rejected_fail_closed(): void
    {
        $input = $this->identity();
        $input['current_state'] = TransactionalCycleStateService::STATE_VALIDATED;
        $input['target_state'] = TransactionalCycleStateService::STATE_EXECUTING;

        $report = $this->service()->transition($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertFalse($report['applied']);
        $this->assertContains('illegal_backward_or_repeat_transition', $report['blockers']);
    }

    public function test_ambiguous_explicit_current_state_fails_closed(): void
    {
        $input = $this->identity();
        $input['current_state'] = 'totally_unknown_state';
        $input['target_state'] = TransactionalCycleStateService::STATE_PREFLIGHTED;

        $report = $this->service()->transition($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertFalse($report['applied']);
        $this->assertContains('ambiguous_current_state_fail_closed', $report['blockers']);
        $this->assertNull($report['resume_point']);
    }

    public function test_ambiguous_ledger_sequence_fails_closed_on_load(): void
    {
        // A ledger whose rows are an illegal sequence is ambiguous => fail closed.
        $input = $this->identity();
        $input['ledger'] = [
            ['state' => TransactionalCycleStateService::STATE_PREFLIGHTED],
            ['state' => TransactionalCycleStateService::STATE_CLEANED], // illegal jump
        ];

        $report = $this->service()->load($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertNull($report['state']);
        $this->assertNull($report['resume_point']);
        $this->assertContains('ambiguous_current_state_fail_closed', $report['blockers']);
    }

    public function test_resume_resumes_only_from_last_durable_state(): void
    {
        $service = $this->service();

        // Durably advance planned -> preflighted -> executing, then "crash".
        $service->transition($this->identity() + ['target_state' => TransactionalCycleStateService::STATE_PREFLIGHTED]);
        $service->transition($this->identity() + ['target_state' => TransactionalCycleStateService::STATE_EXECUTING]);

        $resume = $service->resumePoint($this->identity());

        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $resume['status']);
        // The ONLY safe resume point is the last durable state, never a non-written one.
        $this->assertSame(TransactionalCycleStateService::STATE_EXECUTING, $resume['resume_point']);
        $this->assertSame(TransactionalCycleStateService::STATE_VALIDATED, $resume['next_legal_state']);
        $this->assertFalse($resume['is_terminal']);
        $this->assertSame(2, $resume['durable_row_count']);
    }

    public function test_resume_point_of_fresh_cycle_is_planned(): void
    {
        $resume = $this->service()->resumePoint($this->identity());

        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $resume['status']);
        $this->assertSame(TransactionalCycleStateService::STATE_PLANNED, $resume['resume_point']);
        $this->assertSame(TransactionalCycleStateService::STATE_PREFLIGHTED, $resume['next_legal_state']);
        $this->assertSame(0, $resume['durable_row_count']);
    }

    public function test_cannot_advance_past_terminal_cleaned_state(): void
    {
        $input = $this->identity();
        $input['current_state'] = TransactionalCycleStateService::STATE_CLEANED;

        $report = $this->service()->transition($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertFalse($report['applied']);
        $this->assertContains('cycle_already_terminal_no_advance', $report['blockers']);
    }

    public function test_merged_or_blocked_outcome_blocked_is_never_dressed_as_merged(): void
    {
        // NEGATIVE INVARIANT: a blocked cycle outcome is reported as blocked, not merged.
        $input = $this->identity();
        $input['current_state'] = TransactionalCycleStateService::STATE_JUDGED;
        $input['target_state'] = TransactionalCycleStateService::STATE_MERGED_OR_BLOCKED;
        $input['outcome'] = 'blocked';

        $report = $this->service()->transition($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $report['status']);
        $this->assertTrue($report['applied']);
        $this->assertSame(TransactionalCycleStateService::STATE_MERGED_OR_BLOCKED, $report['state']);
        $this->assertSame('blocked', $report['outcome']);
        $this->assertNotSame('merged', $report['outcome']);
        $this->assertFalse($report['is_terminal'], 'merged_or_blocked is not the terminal success state');
        $this->assertContains('merged_or_blocked_outcome_is_blocked_not_merged', $report['warnings']);
    }

    public function test_load_reflects_current_durable_state_from_ledger_seam(): void
    {
        $input = $this->identity();
        $input['ledger'] = [
            ['state' => TransactionalCycleStateService::STATE_PREFLIGHTED],
            ['state' => TransactionalCycleStateService::STATE_EXECUTING],
            ['state' => TransactionalCycleStateService::STATE_VALIDATED],
        ];

        $report = $this->service()->load($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $report['status']);
        $this->assertSame(TransactionalCycleStateService::STATE_VALIDATED, $report['state']);
        $this->assertSame(TransactionalCycleStateService::STATE_VALIDATED, $report['resume_point']);
        $this->assertFalse($report['is_terminal']);
        $this->assertSame(3, $report['durable_row_count']);
    }

    public function test_default_empty_input_does_not_crash_and_starts_at_planned(): void
    {
        $service = $this->service();

        $load = $service->load();
        $this->assertSame(TransactionalCycleStateService::REPORT_SCHEMA, $load['schema_version']);
        $this->assertSame('LHL-07', $load['slice_id']);
        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $load['status']);
        $this->assertSame(TransactionalCycleStateService::STATE_PLANNED, $load['state']);

        $resume = $service->resumePoint();
        $this->assertSame(TransactionalCycleStateService::STATE_PLANNED, $resume['resume_point']);
    }

    public function test_claim_policy_is_read_only_and_non_destructive(): void
    {
        $report = $this->service()->load($this->identity());

        $policy = $report['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['runs_provider']);
        $this->assertFalse($policy['runs_loop']);
        $this->assertFalse($policy['runs_merge']);
        $this->assertFalse($policy['deletes_branches']);
        $this->assertTrue($policy['blocked_never_dressed_as_ready']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        // Determinism: same input twice => identical report_hash (volatile stripped).
        $input = $this->identity();
        $input['ledger'] = [
            ['state' => TransactionalCycleStateService::STATE_PREFLIGHTED],
            ['state' => TransactionalCycleStateService::STATE_EXECUTING],
        ];

        $first = $this->service()->load($input);
        $second = $this->service()->load($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash',
        );

        // A different durable state must hash differently.
        $other = $this->identity();
        $other['ledger'] = [['state' => TransactionalCycleStateService::STATE_PREFLIGHTED]];
        $o1 = $this->service()->load($other);
        $o2 = $this->service()->load($other);
        $this->assertSame($o1['report_hash'], $o2['report_hash']);
        $this->assertNotSame($first['report_hash'], $o1['report_hash']);
    }

    public function test_transition_is_deterministic_when_seam_driven(): void
    {
        // A seam-driven transition (explicit current_state, no disk write) hashes stably.
        $input = $this->identity();
        $input['current_state'] = TransactionalCycleStateService::STATE_EXECUTING;
        $input['target_state'] = TransactionalCycleStateService::STATE_VALIDATED;

        $a = $this->service()->transition($input);
        $b = $this->service()->transition($input);

        $this->assertSame(TransactionalCycleStateService::STATUS_OK, $a['status']);
        $this->assertSame($a['report_hash'], $b['report_hash']);
    }

    private function rmrf(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
