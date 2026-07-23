<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneCompletionEvidenceValidator;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneCompletionEvidenceValidator rejects completion evidence whose
 * commands_run are generic and not bound to the expected allowed_files or a concrete test
 * target, while leaving evidence_hash validation and files_changed_outside_allowed_scope
 * behavior unchanged.
 */
final class AgentControlPlaneCompletionEvidenceValidatorTest extends TestCase
{
    private function evidence(array $overrides = []): array
    {
        $evidence = array_merge([
            'packet_id' => 'tp1',
            'lease_id' => 'l1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => ['php artisan test'],
            'tests_or_gates_result' => 'pass',
            'implementation_notes' => 'The completion record names the concrete scope-bound verification.',
            'capability_delta' => 'The task packet can be settled only with scoped completion evidence.',
            'git_status_short' => 'clean',
            'git_diff_check_result' => 'clean',
        ], $overrides);
        $evidence['evidence_hash'] = AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }

    private function binding(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp1',
            'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
        ], $overrides);
    }

    public function test_generic_artisan_test_command_with_no_binding_is_blocked(): void
    {
        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence(
            $this->evidence(),
            $this->binding(),
        );

        self::assertSame('blocked', $result['status']);
        self::assertContains('command_not_bound_to_allowed_scope', $result['blockers']);
        self::assertFalse($result['commands_bound_to_allowed_scope']);
        self::assertFalse($result['structured_completion_evidence_valid']);
    }

    public function test_command_naming_concrete_allowed_path_satisfies_binding(): void
    {
        $evidence = $this->evidence(['commands_run' => ['/opt/homebrew/bin/php artisan test app/Foo.php']]);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('valid', $result['status']);
        self::assertNotContains('command_not_bound_to_allowed_scope', $result['blockers']);
        self::assertTrue($result['commands_bound_to_allowed_scope']);
    }

    public function test_command_naming_concrete_test_path_via_basename_satisfies_binding(): void
    {
        $evidence = $this->evidence(['commands_run' => ['vendor/bin/phpunit tests/Unit/FooTest.php']]);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertSame('valid', $result['status']);
        self::assertTrue($result['commands_bound_to_allowed_scope']);
    }

    public function test_evidence_hash_validation_unchanged(): void
    {
        $evidence = $this->evidence();
        $evidence['evidence_hash'] = str_repeat('0', 64);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertContains('evidence_hash_mismatch', $result['blockers']);
    }

    public function test_files_changed_outside_allowed_scope_unchanged(): void
    {
        $evidence = $this->evidence([
            'files_changed' => ['app/Foo.php', 'app/Bar.php'],
            'commands_run' => ['php artisan test app/Foo.php'],
        ]);

        $result = AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $this->binding());

        self::assertContains('files_changed_outside_allowed_scope', $result['blockers']);
        self::assertSame(['app/Bar.php'], $result['files_changed_outside_allowed_scope']);
    }
}
