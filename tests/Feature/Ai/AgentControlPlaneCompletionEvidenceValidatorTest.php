<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneCompletionEvidenceValidator;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneCompletionEvidenceValidatorTest extends TestCase
{
    private function baseEvidence(array $overrides = []): array
    {
        return array_merge([
            'packet_id'             => 'task-123',
            'lease_id'              => 'lease-456',
            'files_changed'         => ['app/Services/Foo.php'],
            'commands_run'          => ['./vendor/bin/phpunit tests/FooTest.php --no-progress'],
            'tests_or_gates_result' => 'pass',
            'git_status_short'      => 'nothing to commit',
            'git_diff_check_result' => 'clean',
        ], $overrides);
    }

    private function baseBinding(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-123',
            'lease_id'       => 'lease-456',
            'agent_id'       => 'claude-muscle-3',
            'allowed_files'  => ['app/Services/Foo.php'],
        ], $overrides);
    }

    private function withHash(array $evidence): array
    {
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }

    // ── AC2: missing required fields → blockers + missing_fields ─────────────

    public function test_empty_evidence_blocks_with_all_required_fields_missing(): void
    {
        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence([], $this->baseBinding());

        $this->assertSame('blocked', $r['status']);
        foreach (['packet_id', 'lease_id', 'evidence_hash', 'files_changed', 'commands_run', 'tests_or_gates_result', 'git_status_short', 'git_diff_check_result'] as $field) {
            $this->assertContains($field, $r['missing_fields'], "Expected $field in missing_fields");
        }
    }

    public function test_missing_packet_id_produces_packet_id_missing_blocker(): void
    {
        $ev = $this->baseEvidence();
        unset($ev['packet_id']);
        $ev = $this->withHash($ev);

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('packet_id_missing', $r['blockers']);
        $this->assertContains('packet_id', $r['missing_fields']);
    }

    public function test_missing_lease_id_produces_lease_id_missing_blocker(): void
    {
        $ev = $this->baseEvidence();
        unset($ev['lease_id']);
        $ev = $this->withHash($ev);

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('lease_id_missing', $r['blockers']);
        $this->assertContains('lease_id', $r['missing_fields']);
    }

    public function test_missing_evidence_hash_produces_evidence_hash_missing_blocker(): void
    {
        $ev = $this->baseEvidence(); // no evidence_hash key

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('evidence_hash_missing', $r['blockers']);
        $this->assertContains('evidence_hash', $r['missing_fields']);
    }

    // ── AC3: mismatches → deterministic blockers ─────────────────────────────

    public function test_packet_id_mismatch_produces_blocker(): void
    {
        $ev = $this->withHash($this->baseEvidence(['packet_id' => 'wrong-id']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('packet_id_mismatch', $r['blockers']);
    }

    public function test_lease_id_mismatch_produces_blocker(): void
    {
        $ev = $this->withHash($this->baseEvidence(['lease_id' => 'wrong-lease']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('lease_id_mismatch', $r['blockers']);
    }

    public function test_actor_mismatch_produces_blocker(): void
    {
        $ev = $this->withHash($this->baseEvidence(['actor' => 'wrong-agent']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('actor_mismatch', $r['blockers']);
    }

    public function test_invalid_hash_format_produces_evidence_hash_invalid_blocker(): void
    {
        $ev                   = $this->baseEvidence();
        $ev['evidence_hash']  = 'not-a-valid-sha256';

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('evidence_hash_invalid', $r['blockers']);
    }

    public function test_wrong_hash_value_produces_evidence_hash_mismatch_blocker(): void
    {
        $ev                  = $this->baseEvidence();
        $ev['evidence_hash'] = hash('sha256', 'wrong-payload'); // valid format, wrong value

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('evidence_hash_mismatch', $r['blockers']);
    }

    public function test_files_changed_outside_allowed_scope_produces_blocker(): void
    {
        $ev = $this->withHash($this->baseEvidence([
            'files_changed' => ['app/Services/Foo.php', 'app/Services/Unauthorized.php'],
        ]));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('files_changed_outside_allowed_scope', $r['blockers']);
        $this->assertContains('app/Services/Unauthorized.php', $r['files_changed_outside_allowed_scope']);
    }

    public function test_missing_required_command_produces_blocker(): void
    {
        $ev      = $this->withHash($this->baseEvidence());
        $binding = $this->baseBinding(['required_commands' => ['./vendor/bin/phpunit tests/FooTest.php --no-progress', 'missing-cmd']]);

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $binding);

        $this->assertContains('required_command_not_run', $r['blockers']);
        $this->assertContains('missing-cmd', $r['missing_required_commands']);
    }

    public function test_missing_required_evidence_label_produces_blocker(): void
    {
        $ev      = $this->withHash($this->baseEvidence());
        $binding = $this->baseBinding(['required_evidence' => ['test_output_summary']]);

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $binding);

        $this->assertContains('required_evidence_missing', $r['blockers']);
        $this->assertContains('test_output_summary', $r['missing_required_evidence_labels']);
    }

    // ── AC4: pass/passed/green accepted; hash order-agnostic; valid → valid ──

    public function test_tests_result_pass_is_accepted(): void
    {
        $ev = $this->withHash($this->baseEvidence(['tests_or_gates_result' => 'pass']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertTrue($r['tests_or_gates_passing']);
    }

    public function test_tests_result_passed_is_accepted(): void
    {
        $ev = $this->withHash($this->baseEvidence(['tests_or_gates_result' => 'passed']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertTrue($r['tests_or_gates_passing']);
    }

    public function test_tests_result_green_is_accepted(): void
    {
        $ev = $this->withHash($this->baseEvidence(['tests_or_gates_result' => 'green']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertTrue($r['tests_or_gates_passing']);
    }

    public function test_failing_tests_result_produces_blocker(): void
    {
        $ev = $this->withHash($this->baseEvidence(['tests_or_gates_result' => 'failed']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertContains('tests_or_gates_result_not_passing', $r['blockers']);
    }

    public function test_canonical_hash_is_key_order_independent(): void
    {
        $dataA = ['packet_id' => 'x', 'lease_id' => 'y', 'files_changed' => ['f.php']];
        $dataB = ['files_changed' => ['f.php'], 'lease_id' => 'y', 'packet_id' => 'x'];

        $this->assertSame(
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($dataA),
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($dataB),
        );
    }

    public function test_fully_valid_evidence_produces_valid_status_and_no_blockers(): void
    {
        $ev = $this->withHash($this->baseEvidence(['actor' => 'claude-muscle-3']));

        $r = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($ev, $this->baseBinding());

        $this->assertSame('valid', $r['status']);
        $this->assertTrue($r['structured_completion_evidence_valid']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['missing_fields']);
    }
}
