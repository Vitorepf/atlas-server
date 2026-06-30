<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRegressionRepairTaskSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRegressionRepairTaskSynthesizerTest extends TestCase
{
    private AtlasExternalBrainRegressionRepairTaskSynthesizer $svc;

    protected function setUp(): void
    {
        $this->svc = new AtlasExternalBrainRegressionRepairTaskSynthesizer;
    }

    private function validDiag(array $overrides = []): array
    {
        return array_merge([
            'diagnostic_id'          => 'diag_001',
            'gate_name'              => 'HonestyGate',
            'target_path'            => 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRegressionRepairTaskSynthesizer.php',
            'runnable_proof_command' => '/opt/homebrew/bin/php artisan test tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRegressionRepairTaskSynthesizerTest.php',
            'failing_reason'         => 'HonestyGate assertion failed at line 42: expected true, got false',
        ], $overrides);
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_schema_present_in_output(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $this->assertSame(AtlasExternalBrainRegressionRepairTaskSynthesizer::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        foreach (['schema', 'repair_specs', 'rejected_diagnostics', 'allowed_files', 'acceptance_criteria', 'unblock_reason'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing key: {$k}");
        }
    }

    // ── AC1: full diagnostic produces repair_spec with impl + test files ──────

    public function test_valid_diagnostic_produces_one_repair_spec(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $this->assertCount(1, $result['repair_specs']);
        $this->assertEmpty($result['rejected_diagnostics']);
    }

    public function test_repair_spec_has_required_keys(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $spec = $result['repair_specs'][0];
        foreach (['diagnostic_id', 'gate_name', 'target_path', 'allowed_files', 'acceptance_criteria', 'unblock_reason'] as $k) {
            $this->assertArrayHasKey($k, $spec, "repair_spec missing: {$k}");
        }
    }

    public function test_allowed_files_contains_impl_file(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $spec = $result['repair_specs'][0];
        $implFile = 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRegressionRepairTaskSynthesizer.php';
        $this->assertContains($implFile, $spec['allowed_files']);
    }

    public function test_allowed_files_contains_derived_test_file(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $spec = $result['repair_specs'][0];
        $this->assertTrue(
            (bool) array_filter($spec['allowed_files'], static fn (string $f): bool => str_ends_with($f, 'Test.php')),
            'No test file in allowed_files',
        );
    }

    public function test_unblock_reason_is_non_empty_for_valid_diagnostic(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $this->assertNotEmpty($result['repair_specs'][0]['unblock_reason']);
    }

    public function test_unblock_plan_overrides_default_reason_when_provided(): void
    {
        $diag = $this->validDiag(['unblock_plan' => 'Add a missing guard in the honesty check loop.']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertStringContainsString('guard', $result['repair_specs'][0]['unblock_reason']);
    }

    // ── AC2: rejected when missing actionable location / contradictory ────────

    public function test_diagnostic_missing_gate_name_is_rejected(): void
    {
        $diag = $this->validDiag(['gate_name' => '']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertEmpty($result['repair_specs']);
        $this->assertCount(1, $result['rejected_diagnostics']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_diagnostic_missing_target_path_and_component_is_rejected(): void
    {
        $diag = $this->validDiag(['target_path' => '']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertEmpty($result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_diagnostic_missing_proof_command_is_rejected(): void
    {
        $diag = $this->validDiag(['runnable_proof_command' => '']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertEmpty($result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_diagnostic_pointing_to_test_file_is_rejected(): void
    {
        $diag = $this->validDiag([
            'target_path'            => 'tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRegressionRepairTaskSynthesizerTest.php',
        ]);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertEmpty($result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_TEST_ONLY,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_forbidden_target_without_unblock_plan_is_rejected(): void
    {
        $diag = $this->validDiag([
            'forbidden_self_target' => true,
            'unblock_plan'          => '',
        ]);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertEmpty($result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_FORBIDDEN_TARGET,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_manual_operator_required_without_bootstrap_flag_is_rejected(): void
    {
        $diag = $this->validDiag([
            'requires_manual_operator_action' => true,
            'is_bootstrap_only'               => false,
        ]);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $this->assertEmpty($result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_MANUAL_OPERATOR,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_rejected_diagnostics_carry_diagnostic_id_and_original_diag(): void
    {
        $diag = $this->validDiag(['gate_name' => '', 'diagnostic_id' => 'bad_diag_42']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag]]);

        $rejected = $result['rejected_diagnostics'][0];
        $this->assertSame('bad_diag_42', $rejected['diagnostic_id']);
        $this->assertArrayHasKey('diagnostic', $rejected);
    }

    // ── AC3: acceptance_criteria contain runnable /opt/homebrew/bin/php cmd ──

    public function test_acceptance_criteria_include_runnable_php_command(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $acJoined = implode(' ', $result['repair_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('/opt/homebrew/bin/php', $acJoined);
    }

    public function test_acceptance_criteria_mention_gate_name(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        $acJoined = implode(' ', $result['repair_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('HonestyGate', $acJoined);
    }

    public function test_no_test_only_allowed_files_in_any_repair_spec(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => [$this->validDiag()]]);

        foreach ($result['repair_specs'] as $spec) {
            $hasImplFile = (bool) array_filter(
                $spec['allowed_files'],
                static fn (string $f): bool => ! str_starts_with($f, 'tests/') && ! str_ends_with($f, 'Test.php'),
            );
            $this->assertTrue($hasImplFile, "repair_spec has no impl file — test-only packet: " . implode(', ', $spec['allowed_files']));
        }
    }

    // ── grouping behavior ─────────────────────────────────────────────────────

    public function test_two_diagnostics_for_same_target_produce_one_macro_spec(): void
    {
        $diag1 = $this->validDiag(['diagnostic_id' => 'd1', 'gate_name' => 'GateA']);
        $diag2 = $this->validDiag(['diagnostic_id' => 'd2', 'gate_name' => 'GateB']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag1, $diag2]]);

        $this->assertCount(1, $result['repair_specs']);
        $spec = $result['repair_specs'][0];
        $this->assertStringContainsString('GateA', $spec['gate_name']);
        $this->assertStringContainsString('GateB', $spec['gate_name']);
    }

    public function test_diagnostics_for_different_targets_produce_separate_specs(): void
    {
        $diag1 = $this->validDiag(['diagnostic_id' => 'd1', 'target_path' => 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRegressionRepairTaskSynthesizer.php']);
        $diag2 = $this->validDiag(['diagnostic_id' => 'd2', 'target_path' => 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainAmplifierControlPlane.php']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag1, $diag2]]);

        $this->assertCount(2, $result['repair_specs']);
    }

    public function test_deduped_allowed_files_when_same_target_grouped(): void
    {
        $diag1 = $this->validDiag(['diagnostic_id' => 'd1', 'gate_name' => 'G1']);
        $diag2 = $this->validDiag(['diagnostic_id' => 'd2', 'gate_name' => 'G2']);

        $result = $this->svc->synthesize(['diagnostics' => [$diag1, $diag2]]);

        $files      = $result['repair_specs'][0]['allowed_files'];
        $implTarget = 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRegressionRepairTaskSynthesizer.php';
        $this->assertSame(1, count(array_filter($files, static fn ($f) => $f === $implTarget)));
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_same_input_produces_same_output(): void
    {
        $input = ['diagnostics' => [$this->validDiag()]];

        $this->assertSame($this->svc->synthesize($input), $this->svc->synthesize($input));
    }

    public function test_empty_diagnostics_returns_empty_specs(): void
    {
        $result = $this->svc->synthesize(['diagnostics' => []]);

        $this->assertSame([], $result['repair_specs']);
        $this->assertSame([], $result['rejected_diagnostics']);
        $this->assertNull($result['unblock_reason']);
    }
}
