<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use Tests\TestCase;

final class AgentControlPlaneTaskPacketBuilderTest extends TestCase
{
    private function builder(): AgentControlPlaneTaskPacketBuilder
    {
        return new AgentControlPlaneTaskPacketBuilder;
    }

    private function validInput(): array
    {
        return [
            'objective' => 'Implement feature X',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/FooTest.php'],
            'required_evidence' => ['test_result'],
            'structural_value_rationale' => 'Reduces coupling between Foo and Bar',
            'require_hard_value_contract' => true,
        ];
    }

    // ── AC: packets without a concrete hard_value_contract are rejected or marked non_claimable ──

    public function test_packet_without_hard_value_contract_is_null(): void
    {
        $packet = $this->builder()->build([
            'objective' => 'Do something',
            'allowed_files' => ['app/Foo.php'],
        ]);

        $this->assertNull($packet['hard_value_contract']);
    }

    public function test_packet_with_hard_value_contract_satisfied(): void
    {
        $packet = $this->builder()->build($this->validInput());

        $this->assertNotNull($packet['hard_value_contract']);
        $this->assertTrue($packet['hard_value_contract']['satisfied']);
        $this->assertSame([], $packet['hard_value_contract']['blocking_reasons']);
    }

    public function test_packet_missing_implementation_file_blocked(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = ['tests/Unit/FooTest.php'];

        $packet = $this->builder()->build($input);

        $this->assertContains('missing_implementation_file', $packet['hard_value_contract']['blocking_reasons']);
        $this->assertFalse($packet['hard_value_contract']['satisfied']);
    }

    // ── AC: allowed_files must include both implementation and test targets tied to the named capability ──

    public function test_allowed_files_must_include_both_implementation_and_test(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = ['app/Services/Foo.php']; // no test file

        $packet = $this->builder()->build($input);

        $this->assertFalse($packet['hard_value_contract']['has_test_file']);
    }

    // ── AC: runnable acceptance must name the concrete test path or filter rather than a generic suite command ──

    public function test_runnable_acceptance_with_concrete_test_path_passes(): void
    {
        $input = $this->validInput();
        $input['acceptance_criteria'] = ['php artisan test tests/Unit/FooTest.php'];

        $packet = $this->builder()->build($input);

        $this->assertTrue($packet['hard_value_contract']['has_runnable_proof']);
    }

    public function test_runnable_acceptance_with_concrete_filter_passes(): void
    {
        $input = $this->validInput();
        $input['acceptance_criteria'] = ['php artisan test --filter=FooTest'];

        $packet = $this->builder()->build($input);

        $this->assertTrue($packet['hard_value_contract']['has_runnable_proof']);
    }

    public function test_generic_suite_command_without_concrete_path_blocked(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = ['app/Services/Foo.php']; // no test file
        $input['acceptance_criteria'] = ['php artisan test']; // generic, no path or filter

        $packet = $this->builder()->build($input);

        $this->assertContains('runnable_proof_not_concrete', $packet['hard_value_contract']['blocking_reasons']);
    }

    public function test_missing_required_evidence_blocked(): void
    {
        $input = $this->validInput();
        $input['required_evidence'] = [];

        $packet = $this->builder()->build($input);

        $this->assertContains('missing_required_evidence', $packet['hard_value_contract']['blocking_reasons']);
    }

    public function test_missing_structural_value_rationale_blocked(): void
    {
        $input = $this->validInput();
        $input['structural_value_rationale'] = '';

        $packet = $this->builder()->build($input);

        $this->assertContains('missing_structural_value_rationale', $packet['hard_value_contract']['blocking_reasons']);
    }
}
