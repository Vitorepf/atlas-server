<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientResultVerifier;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientResultVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainLocalClientResultVerifier
    {
        return new AtlasExternalBrainLocalClientResultVerifier;
    }

    private function greenFacts(array $overrides = []): array
    {
        return array_merge([
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria' => ['foo works'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'provided_evidence' => [
                'tests_or_gates_result' => '5 passed',
                'implementation_notes' => 'added Foo logic',
            ],
            'claimed_changed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'reported_test_commands' => [
                ['command' => 'php artisan test FooTest', 'executed' => true, 'passed' => true],
            ],
            'claimed_green' => true,
        ], $overrides);
    }

    public function test_fully_proven_result_is_verified_green_and_safe_to_report(): void
    {
        $result = $this->verifier()->verify($this->greenFacts());

        $this->assertSame('verified_green', $result['verifier_status']);
        $this->assertTrue($result['claimed_green']);
        $this->assertTrue($result['verified_green']);
        $this->assertTrue($result['safe_to_report_success']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_missing_required_evidence_blocks_and_is_not_safe_to_report(): void
    {
        $facts = $this->greenFacts();
        unset($facts['provided_evidence']['implementation_notes']);

        $result = $this->verifier()->verify($facts);

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertFalse($result['safe_to_report_success']);
        $this->assertContains('missing_required_evidence:implementation_notes', $result['blockers']);
    }

    public function test_changed_files_outside_allowed_scope_blocks(): void
    {
        $facts = $this->greenFacts(['claimed_changed_files' => ['app/Foo.php', 'app/Unrelated.php']]);

        $result = $this->verifier()->verify($facts);

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertContains('changed_file_outside_allowed_scope:app/Unrelated.php', $result['blockers']);
    }

    public function test_self_reported_completion_without_runnable_test_proof_is_blocked(): void
    {
        $facts = $this->greenFacts(['reported_test_commands' => []]);

        $result = $this->verifier()->verify($facts);

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertFalse($result['safe_to_report_success']);
        $this->assertContains('claimed_success_without_runnable_test_proof', $result['blockers']);
        $this->assertTrue($result['claimed_green']);
        $this->assertFalse($result['verified_green']);
    }

    public function test_claimed_green_separated_from_verified_green_in_learning_signal(): void
    {
        $facts = $this->greenFacts(['reported_test_commands' => []]);

        $result = $this->verifier()->verify($facts);

        $this->assertTrue($result['learning_signal']['claimed_green']);
        $this->assertFalse($result['learning_signal']['verified_green']);
        $this->assertFalse($result['learning_signal']['claim_matched_verification']);
    }

    public function test_failed_reported_test_command_blocks(): void
    {
        $facts = $this->greenFacts(['reported_test_commands' => [
            ['command' => 'php artisan test FooTest', 'executed' => true, 'passed' => false],
        ]]);

        $result = $this->verifier()->verify($facts);

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertContains('reported_test_command_failed', $result['blockers']);
    }

    public function test_unexecuted_test_command_does_not_count_as_proof(): void
    {
        $facts = $this->greenFacts(['reported_test_commands' => [
            ['command' => 'php artisan test FooTest', 'executed' => false, 'passed' => true],
        ]]);

        $result = $this->verifier()->verify($facts);

        $this->assertSame('blocked', $result['verifier_status']);
        $this->assertContains('claimed_success_without_runnable_test_proof', $result['blockers']);
    }
}
