<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneCompletionEvidenceValidator;
use Tests\TestCase;

class AgentControlPlaneCompletionEvidenceValidatorTest extends TestCase
{
    public function test_completion_evidence_unwraps_nested(): void
    {
        $evidence = [
            'completion_evidence' => [
                'packet_id' => 'tp1',
                'lease_id' => 'l1',
                'files_changed' => ['app/Foo.php'],
            ],
            'evidence_hash' => 'h1',
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::completionEvidence($evidence);

        self::assertSame('tp1', $result['packet_id']);
        self::assertSame('l1', $result['lease_id']);
        self::assertSame('h1', $result['evidence_hash']);
    }

    public function test_completion_evidence_returns_flat_when_no_nested(): void
    {
        $evidence = ['packet_id' => 'tp1', 'lease_id' => 'l1'];

        $result = AgentControlPlaneCompletionEvidenceValidator::completionEvidence($evidence);

        self::assertSame($evidence, $result);
    }

    public function test_completion_evidence_falls_back_to_operator_supplied_hash(): void
    {
        $evidence = [
            'completion_evidence' => ['packet_id' => 'tp1'],
            'operator_supplied_evidence_hash' => 'osh',
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::completionEvidence($evidence);

        self::assertSame('osh', $result['evidence_hash']);
    }

    public function test_canonical_hash_is_deterministic(): void
    {
        $evidence = ['packet_id' => 'tp1', 'lease_id' => 'l1', 'files_changed' => ['a.php', 'b.php']];
        $h1 = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $h2 = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        self::assertSame($h1, $h2);
    }

    public function test_canonical_hash_strips_evidence_hash_field(): void
    {
        $a = ['packet_id' => 'tp1', 'evidence_hash' => str_repeat('a', 64)];
        $b = ['packet_id' => 'tp1', 'evidence_hash' => str_repeat('b', 64)];

        self::assertSame(
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($a),
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($b),
        );
    }

    public function test_canonical_hash_strips_operator_supplied_evidence_hash(): void
    {
        $a = ['packet_id' => 'tp1', 'operator_supplied_evidence_hash' => str_repeat('a', 64)];
        $b = ['packet_id' => 'tp1', 'operator_supplied_evidence_hash' => str_repeat('b', 64)];

        self::assertSame(
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($a),
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($b),
        );
    }

    public function test_canonical_hash_order_independent(): void
    {
        $a = ['packet_id' => 'tp1', 'lease_id' => 'l1'];
        $b = ['lease_id' => 'l1', 'packet_id' => 'tp1'];

        self::assertSame(
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($a),
            AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($b),
        );
    }

    public function test_canonical_hash_returns_64_hex(): void
    {
        $hash = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash(['x' => 1]);
        self::assertSame(64, strlen($hash));
        self::assertTrue(ctype_xdigit($hash));
    }

    public function test_validate_blocked_when_evidence_hash_missing(): void
    {
        $evidence = ['packet_id' => 'tp1', 'lease_id' => 'l1'];
        $binding = ['task_packet_id' => 'tp1', 'lease_id' => 'l1'];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('evidence_hash_missing', $result['blockers']);
        self::assertFalse($result['evidence_hash_matches_payload']);
    }

    public function test_validate_blocked_when_evidence_hash_invalid(): void
    {
        $evidence = ['packet_id' => 'tp1', 'lease_id' => 'l1', 'evidence_hash' => 'not-hex'];
        $binding = ['task_packet_id' => 'tp1', 'lease_id' => 'l1'];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('evidence_hash_invalid', $result['blockers']);
    }

    public function test_validate_blocked_when_files_outside_allowed(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'evidence_hash' => str_repeat('c', 64),
            'files_changed' => ['app/Foo.php', 'app/OutOfScope.php'],
            'commands_run' => ['cmd'],
            'tests_or_gates_result' => 'pass',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('files_changed_outside_allowed_scope', $result['blockers']);
    }

    public function test_validate_blocked_when_tests_not_passing(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'evidence_hash' => str_repeat('c', 64),
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['cmd'],
            'tests_or_gates_result' => 'fail',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('tests_or_gates_result_not_passing', $result['blockers']);
    }

    public function test_validate_blocked_when_packet_id_mismatch(): void
    {
        $evidence = [
            'packet_id' => 'tp_actual', 'lease_id' => 'l1',
            'evidence_hash' => str_repeat('c', 64),
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['cmd'],
            'tests_or_gates_result' => 'pass',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $binding = [
            'task_packet_id' => 'tp_expected', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('packet_id_mismatch', $result['blockers']);
    }

    public function test_validate_valid_for_complete_evidence(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'evidence_hash' => str_repeat('c', 64),
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['php artisan test app/Foo.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo via bar contract.',
            'capability_delta' => 'Added guard preventing empty payload.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        // Compute the actual hash for matching
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertTrue($result['structured_completion_evidence_valid']);
        self::assertTrue($result['evidence_hash_matches_payload']);
    }

    public function test_generic_command_not_bound_to_allowed_scope_blocks(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['php artisan test'],
            'tests_or_gates_result' => 'pass',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('command_not_bound_to_allowed_scope', $result['blockers']);
        self::assertFalse($result['commands_bound_to_allowed_scope']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_command_naming_concrete_allowed_path_satisfies_binding(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php app/Foo.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo via bar contract.',
            'capability_delta' => 'Added guard preventing empty payload.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('valid', $result['status']);
        self::assertNotContains('command_not_bound_to_allowed_scope', $result['blockers']);
        self::assertTrue($result['commands_bound_to_allowed_scope']);
    }

    public function test_validate_never_allows_completion(): void
    {
        $evidence = ['packet_id' => 'tp1', 'lease_id' => 'l1'];
        $binding = ['task_packet_id' => 'tp1', 'lease_id' => 'l1'];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertFalse($result['completion_real_allowed']);
    }

    public function test_normalize_evidence_for_hash_passthrough_non_array(): void
    {
        self::assertSame('foo', AgentControlPlaneCompletionEvidenceValidator::normalizeEvidenceForHash('foo'));
        self::assertSame(42, AgentControlPlaneCompletionEvidenceValidator::normalizeEvidenceForHash(42));
    }

    public function test_normalize_strips_nested_evidence_hash(): void
    {
        $normalized = AgentControlPlaneCompletionEvidenceValidator::normalizeEvidenceForHash([
            'packet_id' => 'tp1',
            'nested' => ['evidence_hash' => 'aaa', 'data' => 'b'],
        ]);

        self::assertArrayNotHasKey('evidence_hash', $normalized['nested']);
        self::assertSame('b', $normalized['nested']['data']);
    }

    public function test_normalize_preserves_list_order(): void
    {
        $input = ['c', 'a', 'b'];
        $normalized = AgentControlPlaneCompletionEvidenceValidator::normalizeEvidenceForHash($input);

        self::assertSame(['c', 'a', 'b'], $normalized);
    }

    public function test_required_command_not_run_blocks_validation(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['git status'],
            'tests_or_gates_result' => 'pass',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
            'required_commands' => ['vendor/bin/phpunit tests/Unit/FooTest.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('required_command_not_run', $result['blockers']);
        self::assertSame(['vendor/bin/phpunit tests/Unit/FooTest.php'], $result['missing_required_commands']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_required_evidence_missing_blocks_validation(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['vendor/bin/phpunit tests/Unit/FooTest.php'],
            'tests_or_gates_result' => 'pass',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
            // implementation_notes intentionally absent
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('required_evidence_missing', $result['blockers']);
        self::assertSame(['implementation_notes'], $result['missing_required_evidence_labels']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_vague_command_summary_does_not_satisfy_required_command(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['tests passed', 'all green'],
            'tests_or_gates_result' => 'pass',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
            'required_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('required_command_not_run', $result['blockers']);
        self::assertSame(
            ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            $result['missing_required_commands'],
        );
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_complete_evidence_with_required_binding_passes(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['vendor/bin/phpunit tests/Unit/FooTest.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo via bar contract.',
            'capability_delta' => 'Added guard preventing empty payload.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
            'required_commands' => ['vendor/bin/phpunit tests/Unit/FooTest.php'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertSame([], $result['missing_required_commands']);
        self::assertSame([], $result['missing_required_evidence_labels']);
        self::assertTrue($result['structured_completion_evidence_valid']);
    }

    // ── AC: implementation_notes now required unconditionally ───────────────

    public function test_validate_blocked_when_implementation_notes_missing(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['php artisan test app/Foo.php'],
            'tests_or_gates_result' => 'pass',
            'capability_delta' => 'Added guard preventing empty payload.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('implementation_notes_missing', $result['blockers']);
        self::assertFalse($result['structured_completion_evidence_valid']);
        self::assertFalse($result['implementation_notes_present']);
    }

    // ── AC: capability_delta now required unconditionally ───────────────────

    public function test_validate_blocked_when_capability_delta_missing(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['php artisan test app/Foo.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('blocked', $result['status']);
        self::assertContains('capability_delta_missing', $result['blockers']);
        self::assertFalse($result['capability_delta_present']);
    }

    // ── AC: proxy evidence rejection ────────────────────────────────────────

    public function test_validate_blocks_doc_only_evidence(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['docs/README.md'],
            'commands_run' => ['php artisan test app/Foo.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Updated docs.',
            'capability_delta' => 'Doc clarification.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['docs/README.md'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('proxy_doc_only_evidence', $result['blockers']);
        self::assertTrue($result['proxy_doc_only_evidence']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_validate_blocks_exit_code_only_evidence(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['echo done', 'ls -la'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo.',
            'capability_delta' => 'Added guard.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('proxy_exit_code_only_evidence', $result['blockers']);
        self::assertTrue($result['proxy_exit_code_only_evidence']);
    }

    public function test_validate_blocks_schema_only_evidence(): void
    {
        $evidence = [
            'schema' => 'atlas.evidence.v1',
            'schema_version' => '1.0',
            'evidence_hash' => str_repeat('c', 64),
        ];
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('proxy_schema_only_evidence', $result['blockers']);
        self::assertTrue($result['proxy_schema_only_evidence']);
    }

    public function test_validate_blocks_out_of_scope_changed_files(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php', 'app/Unrelated.php'],
            'commands_run' => ['php artisan test app/Foo.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Implemented Foo.',
            'capability_delta' => 'Added guard.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertContains('files_changed_outside_allowed_scope', $result['blockers']);
        self::assertContains('app/Unrelated.php', $result['files_changed_outside_allowed_scope']);
    }

    // ── AC: accepts only with full scoped implementation + verification ─────

    public function test_validate_accepts_complete_evidence_with_capability_delta_and_notes(): void
    {
        $evidence = [
            'packet_id' => 'tp1', 'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['vendor/bin/phpunit tests/Unit/FooTest.php'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'Added capability guard to Foo.',
            'capability_delta' => 'New guard blocks empty payload from reaching processor.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
        $binding = [
            'task_packet_id' => 'tp1', 'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
            'required_commands' => ['vendor/bin/phpunit tests/Unit/FooTest.php'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
        ];

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $binding);

        self::assertSame('valid', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertTrue($result['structured_completion_evidence_valid']);
        self::assertTrue($result['implementation_notes_present']);
        self::assertTrue($result['capability_delta_present']);
        self::assertFalse($result['proxy_doc_only_evidence']);
        self::assertFalse($result['proxy_exit_code_only_evidence']);
        self::assertFalse($result['proxy_schema_only_evidence']);
    }
}