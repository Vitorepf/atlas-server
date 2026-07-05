<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackToSpecRepairCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroGiveBackToSpecRepairCompilerTest extends TestCase
{
    private AtlasMaestroGiveBackToSpecRepairCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new AtlasMaestroGiveBackToSpecRepairCompiler;
    }

    private function baseSpec(): array
    {
        return [
            'task_packet_id' => 'pkt-1',
            'objective' => 'Original objective.',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['Run tests.'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    // ── AC: each give_back reason maps to a repaired field ──

    public function test_unclear_objective_rewrites_objective(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['objective is unclear'],
        ]);

        $this->assertCount(1, $result['repairs']);
        $this->assertSame('objective', $result['repairs'][0]['field']);
        $this->assertNotSame('Original objective.', $result['repaired_spec']['objective']);
    }

    public function test_weak_acceptance_repairs_acceptance_criteria(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['acceptance criteria are not runnable'],
        ]);

        $this->assertSame('acceptance_criteria', $result['repairs'][0]['field']);
        $this->assertCount(3, $result['repaired_spec']['acceptance_criteria']);
        $this->assertContains('Bind each criterion to a specific allowed file or test class.', $result['repaired_spec']['acceptance_criteria']);
    }

    public function test_missing_evidence_repairs_required_evidence(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['missing proof evidence'],
        ]);

        $this->assertSame('required_evidence', $result['repairs'][0]['field']);
        $this->assertContains('implementation_notes', $result['repaired_spec']['required_evidence']);
    }

    public function test_scope_reason_repairs_allowed_files(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['allowed_files do not cover the scope'],
        ]);

        $this->assertSame('allowed_files', $result['repairs'][0]['field']);
        $this->assertSame('expand', $result['repairs'][0]['action']);
    }

    // ── AC: unrelated fields are preserved ──

    public function test_unrelated_fields_are_preserved(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['objective is unclear'],
        ]);

        $this->assertSame(['app/Foo.php'], $result['repaired_spec']['allowed_files']);
        $this->assertContains('Run tests.', $result['repaired_spec']['acceptance_criteria']);
        $this->assertContains('tests_or_gates_result', $result['repaired_spec']['required_evidence']);
    }

    // ── AC: reject reasons mark rejected ──

    public function test_duplicate_reason_marks_rejected(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['already_satisfied duplicate'],
        ]);

        $this->assertTrue($result['rejected']);
        $this->assertSame('quarantine_as_duplicate_or_noop', $result['repaired_spec']['objective']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler->compile([
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => [],
        ]);

        $this->assertSame(AtlasMaestroGiveBackToSpecRepairCompiler::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('repairs', $result);
        $this->assertArrayHasKey('repaired_spec', $result);
        $this->assertArrayHasKey('rejected', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'task_packet_id' => 'pkt-1',
            'spec' => $this->baseSpec(),
            'give_back_reasons' => ['objective is unclear', 'acceptance criteria are weak'],
        ];

        $a = $this->compiler->compile($input);
        $b = $this->compiler->compile($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
