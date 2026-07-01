<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneCompletionEvidenceValidator;
use Tests\TestCase;

/**
 * Focused contract test: builds canonical passing completion evidence, proves the valid path,
 * then proves the validator blocks (never accepts) hash mismatch, missing required command,
 * missing required evidence, outside-scope files, and a non-green tests_or_gates_result.
 */
final class AgentControlPlaneCompletionEvidenceValidatorTest extends TestCase
{
    private function binding(): array
    {
        return [
            'task_packet_id' => 'tp-1',
            'lease_id' => 'lease-1',
            'allowed_files' => ['app/Foo.php'],
            'required_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
        ];
    }

    private function canonicalPassingEvidence(): array
    {
        $evidence = [
            'packet_id' => 'tp-1',
            'lease_id' => 'lease-1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo per contract.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }

    public function test_canonical_passing_evidence_is_valid(): void
    {
        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence(
            $this->canonicalPassingEvidence(),
            $this->binding(),
        );

        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertTrue($result['structured_completion_evidence_valid']);
    }

    public function test_hash_mismatch_blocks_and_never_reports_valid(): void
    {
        $evidence = $this->canonicalPassingEvidence();
        $evidence['evidence_hash'] = str_repeat('f', 64);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('blocked', $result['status']);
        self::assertContains('evidence_hash_mismatch', $result['blockers']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_missing_required_command_blocks_and_never_reports_valid(): void
    {
        $evidence = $this->canonicalPassingEvidence();
        $evidence['commands_run'] = ['some other unrelated command'];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('blocked', $result['status']);
        self::assertContains('required_command_not_run', $result['blockers']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_missing_required_evidence_blocks_and_never_reports_valid(): void
    {
        $evidence = $this->canonicalPassingEvidence();
        unset($evidence['implementation_notes']);
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('blocked', $result['status']);
        self::assertContains('required_evidence_missing', $result['blockers']);
        self::assertSame(['implementation_notes'], $result['missing_required_evidence_labels']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_files_outside_allowed_scope_block_and_never_report_valid(): void
    {
        $evidence = $this->canonicalPassingEvidence();
        $evidence['files_changed'] = ['app/Foo.php', 'app/NotAllowed.php'];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('blocked', $result['status']);
        self::assertContains('files_changed_outside_allowed_scope', $result['blockers']);
        self::assertSame(['app/NotAllowed.php'], $result['files_changed_outside_allowed_scope']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_non_green_gate_result_blocks_and_never_reports_valid(): void
    {
        $evidence = $this->canonicalPassingEvidence();
        $evidence['tests_or_gates_result'] = 'fail';
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('blocked', $result['status']);
        self::assertContains('tests_or_gates_result_not_passing', $result['blockers']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }
}
