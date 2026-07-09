<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use PHPUnit\Framework\TestCase;

final class AtlasTaskCommitGovernanceChainTest extends TestCase
{
    private string $verdictLedgerPath;

    private string $releaseLedgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verdictLedgerPath = sys_get_temp_dir().'/atlas_commit_gov_verdict_'.uniqid('', true).'.jsonl';
        $this->releaseLedgerPath = sys_get_temp_dir().'/atlas_commit_gov_release_'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->verdictLedgerPath)) {
            unlink($this->verdictLedgerPath);
        }
        if (is_dir($this->releaseLedgerPath)) {
            rmdir($this->releaseLedgerPath);
        } elseif (file_exists($this->releaseLedgerPath)) {
            unlink($this->releaseLedgerPath);
        }
        parent::tearDown();
    }

    private function chain(string $mode): AtlasTaskCommitGovernanceChain
    {
        return new AtlasTaskCommitGovernanceChain(
            verdictLedger: new AtlasVerificationCourtVerdictLedger($this->verdictLedgerPath),
            releaseLedger: new AtlasMergeGovernorReleaseDecisionLedger($this->releaseLedgerPath),
            clock: static fn (): string => '2026-01-01T00:00:00+00:00',
            modeOverride: $mode,
        );
    }

    public function test_enforce_mode_blocks_failed_admission_and_reports_enforced_block(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern([
            'task_packet_id' => 'task-1',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => ['passed' => false],
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['enforced_block']);
        $this->assertSame(AtlasTaskCommitGovernanceChain::MODE_ENFORCE, $result['mode']);
    }

    public function test_enforce_mode_does_not_block_admitted_commit(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern([
            'task_packet_id' => 'task-2',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-1'],
        ]);

        $this->assertTrue($result['admitted']);
        $this->assertFalse($result['enforced_block']);
        $this->assertSame(AtlasMergeGovernorReleaseDecisionLedger::STATUS_OK, $result['recorded']['release_ledger']);

        $rows = (new AtlasMergeGovernorReleaseDecisionLedger($this->releaseLedgerPath))->all();
        $this->assertCount(1, $rows);
        $this->assertNotEmpty($rows[0]['evidence_refs']);
        $this->assertSame('revertible:git_revert_scoped_commit', $rows[0]['rollback_posture']);
    }

    public function test_enforce_mode_blocks_green_without_evidence_hash(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern([
            'task_packet_id' => 'task-missing-evidence',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => ['passed' => true],
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['enforced_block']);
        $this->assertSame('repair_required', $result['decision']);
        $this->assertContains('evidence_hash_missing', $result['blockers']);
    }

    public function test_enforce_mode_blocks_when_release_ledger_cannot_write(): void
    {
        $blockedParent = sys_get_temp_dir().'/atlas_commit_gov_release_blocker_'.uniqid('', true);
        file_put_contents($blockedParent, 'not-a-directory');
        $this->releaseLedgerPath = $blockedParent.'/release.jsonl';

        try {
            $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern([
                'task_packet_id' => 'task-ledger-error',
                'project_id' => 'atlas-self-construction',
                'changed_files' => ['app/Services/Foo.php'],
                'verification' => ['passed' => true, 'evidence_hash' => 'ev-ledger-error'],
            ]);

            $this->assertFalse($result['admitted']);
            $this->assertTrue($result['enforced_block']);
            $this->assertSame('governance_ledger_error_fail_closed', $result['decision']);
            $this->assertContains('release_ledger_error', $result['blockers']);
        } finally {
            @unlink($blockedParent);
        }
    }

    public function test_observe_mode_records_verdict_and_release_ledger_statuses_without_blocking(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE)->govern([
            'task_packet_id' => 'task-3',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => ['passed' => false],
        ]);

        $this->assertFalse($result['admitted'], 'admission still fails');
        $this->assertFalse($result['enforced_block'], 'observe mode never blocks');
        $this->assertSame(AtlasTaskCommitGovernanceChain::MODE_OBSERVE, $result['mode']);
        $this->assertNotSame('skipped', $result['recorded']['verdict_ledger']);
        $this->assertNotSame('skipped', $result['recorded']['release_ledger']);
        $this->assertFileExists($this->verdictLedgerPath);
        $this->assertFileExists($this->releaseLedgerPath);
    }

    public function test_missing_task_id_skips_both_ledgers(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE)->govern([
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-2'],
        ]);

        $this->assertSame('skipped_no_task_id', $result['recorded']['verdict_ledger']);
        $this->assertSame('skipped_no_task_id', $result['recorded']['release_ledger']);
        $this->assertFileDoesNotExist($this->verdictLedgerPath);
        $this->assertFileDoesNotExist($this->releaseLedgerPath);
    }

    public function test_observe_mode_includes_replay_verdict_without_blocking(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE)->govern([
            'task_packet_id' => 'task-5',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-5'],
        ]);

        $this->assertArrayHasKey('replay_verdict', $result);
        $this->assertSame('passed', $result['replay_verdict']['verdict']);
        $this->assertFalse($result['enforced_block']);
    }

    public function test_enforce_mode_blocks_false_green_replay_contradiction_even_when_verification_passed_true(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE)->govern([
            'task_packet_id' => 'task-6',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'],
            'verification' => [
                'passed' => true,
                'evidence_hash' => 'ev-6',
                'planned_commands' => [
                    ['command_id' => 'cmd-1', 'output_hash' => 'hash-1'],
                ],
                'replay_results' => [
                    ['command_id' => 'cmd-1', 'exit_code' => 0, 'output_hash' => null],
                ],
            ],
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['enforced_block']);
        $this->assertContains('false_green_replay_contradiction', $result['blockers']);
        $this->assertNotSame('passed', $result['replay_verdict']['verdict']);
    }

    public function test_off_mode_is_a_no_op_and_skips_both_ledgers(): void
    {
        $result = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OFF)->govern([
            'task_packet_id' => 'task-4',
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-3'],
        ]);

        $this->assertSame('skipped', $result['recorded']['verdict_ledger']);
        $this->assertSame('skipped', $result['recorded']['release_ledger']);
        $this->assertFalse($result['enforced_block']);
    }
}
