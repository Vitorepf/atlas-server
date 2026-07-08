<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityCorridorAnalyzer;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneChainIntegrityCorridorAnalyzerTest extends TestCase
{
    private function analyzer(): AgentControlPlaneChainIntegrityCorridorAnalyzer
    {
        return new AgentControlPlaneChainIntegrityCorridorAnalyzer;
    }

    // ── terminal horizon: next_required_slice + terminal_blockers ─────────────

    public function test_linear_next_horizon_reports_next_required_slice_and_no_blockers(): void
    {
        $deepChain = [
            ['activate_key' => 'ak_a'],
            ['activate_key' => 'ak_b'],
        ];
        $cycle = ['intentional_reentry_detected' => false, 'terminal_horizon' => 'ak_b'];

        $result = $this->analyzer()->terminalHorizonAnalysis($deepChain, 'ak_a', $cycle);

        $this->assertTrue($result['horizon_ok']);
        $this->assertSame('ak_b', $result['next_required_slice']);
        $this->assertSame([], $result['terminal_blockers']);
    }

    public function test_blocked_unknown_horizon_reports_terminal_blockers_with_reason(): void
    {
        $deepChain = [['activate_key' => 'ak_a']];
        $cycle = ['intentional_reentry_detected' => false];

        $result = $this->analyzer()->terminalHorizonAnalysis($deepChain, 'unknown_pointer', $cycle);

        $this->assertFalse($result['horizon_ok']);
        $this->assertSame('unknown_pointer', $result['next_required_slice']);
        $this->assertNotEmpty($result['terminal_blockers']);
        $this->assertSame('blocked_unknown', $result['terminal_blockers'][0]['blocker_type']);
        $this->assertSame('unknown_pointer', $result['terminal_blockers'][0]['pointer']);
        $this->assertNotSame('', $result['terminal_blockers'][0]['reason']);
    }

    public function test_intentional_reentry_horizon_is_ok_with_no_blockers(): void
    {
        $deepChain = [['activate_key' => 'ak_a']];
        $cycle = [
            'intentional_reentry_detected' => true,
            'intentional_reentry_reason' => 'explicit_operator_replay',
        ];

        $result = $this->analyzer()->terminalHorizonAnalysis($deepChain, 'ak_a', $cycle);

        $this->assertTrue($result['horizon_ok']);
        $this->assertSame([], $result['terminal_blockers']);
        $this->assertSame('reentry_into_post_start_evidence_corridor', $result['next_required_slice']);
    }

    // ── gap collectors: readiness, invoker, runtime-safety are separate ───────

    public function test_readiness_invoker_and_runtime_safety_gaps_are_separate_collections(): void
    {
        $sliceReports = [
            [
                'slice_key' => 'slice_a',
                'checks' => [
                    'invoker_class_exists' => false,
                    'invoker_prepare_method_exists' => false,
                    'contract_method_exists' => false,
                    'preflight_method_exists' => true,
                    'implementation_packet_method_exists' => true,
                    'status_method_exists' => true,
                ],
            ],
        ];
        $runtimeSafety = ['dispatch_allowed_anywhere' => true, 'runtime_safety_all_false' => false];

        $invokerGaps = $this->analyzer()->collectInvokerGaps($sliceReports);
        $readinessGaps = $this->analyzer()->collectReadinessGaps($sliceReports);
        $runtimeGaps = $this->analyzer()->runtimeSafetyGaps($runtimeSafety);

        $this->assertNotEmpty($invokerGaps);
        $this->assertNotEmpty($readinessGaps);
        $this->assertNotEmpty($runtimeGaps);

        $this->assertNotSame($invokerGaps, $readinessGaps);
        $this->assertNotSame($readinessGaps, $runtimeGaps);
    }

    public function test_clean_slice_report_yields_no_readiness_or_invoker_gaps(): void
    {
        $sliceReports = [
            [
                'slice_key' => 'slice_a',
                'checks' => [
                    'invoker_class_exists' => true,
                    'invoker_prepare_method_exists' => true,
                    'contract_method_exists' => true,
                    'preflight_method_exists' => true,
                    'implementation_packet_method_exists' => true,
                    'status_method_exists' => true,
                ],
            ],
        ];

        $this->assertSame([], $this->analyzer()->collectInvokerGaps($sliceReports));
        $this->assertSame([], $this->analyzer()->collectReadinessGaps($sliceReports));
    }

    public function test_all_flags_false_and_runtime_safety_all_false_true_yields_no_runtime_gaps(): void
    {
        $runtimeSafety = [
            'actual_process_start_allowed_anywhere' => false,
            'provider_process_call_allowed_anywhere' => false,
            'adapter_invocation_allowed_anywhere' => false,
            'adapter_execution_allowed_anywhere' => false,
            'dispatch_allowed_anywhere' => false,
            'token_spend_allowed_anywhere' => false,
            'self_programming_allowed_anywhere' => false,
            'external_process_started_by_atlas' => false,
            'codex_cli_invoked' => false,
            'shell_spawned_by_runtime' => false,
            'runtime_safety_all_false' => true,
        ];

        $this->assertSame([], $this->analyzer()->runtimeSafetyGaps($runtimeSafety));
    }

    // ── allCorridorSlicesOk ─────────────────────────────────────────────────

    public function test_all_corridor_slices_ok_true_when_all_present_and_ok(): void
    {
        $result = $this->analyzer()->allCorridorSlicesOk(
            ['slice_a', 'slice_b'],
            [
                'slice_a' => ['all_artifacts_ok' => true],
                'slice_b' => ['all_artifacts_ok' => true],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_all_corridor_slices_ok_false_when_a_required_slice_is_missing(): void
    {
        $result = $this->analyzer()->allCorridorSlicesOk(
            ['slice_a', 'slice_missing'],
            ['slice_a' => ['all_artifacts_ok' => true]],
        );

        $this->assertFalse($result);
    }

    public function test_all_corridor_slices_ok_false_when_a_required_slice_failed(): void
    {
        $result = $this->analyzer()->allCorridorSlicesOk(
            ['slice_a', 'slice_b'],
            [
                'slice_a' => ['all_artifacts_ok' => true],
                'slice_b' => ['all_artifacts_ok' => false],
            ],
        );

        $this->assertFalse($result);
    }
}
