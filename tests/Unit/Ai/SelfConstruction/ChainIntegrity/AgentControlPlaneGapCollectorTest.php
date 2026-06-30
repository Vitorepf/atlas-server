<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneGapCollector;
use Tests\TestCase;

class AgentControlPlaneGapCollectorTest extends TestCase
{
    public function test_capability_gaps_returns_empty_when_all_present(): void
    {
        $reports = [
            ['checks' => ['capability_contract_registered' => true, 'capability_preflight_registered' => true, 'capability_implementation_packet_registered' => true, 'capability_invoker_service_registered' => true, 'capability_status_projection_registered' => true]],
        ];

        self::assertSame([], AgentControlPlaneGapCollector::capabilityGaps([['slice_key' => 'a']], $reports));
    }

    public function test_capability_gaps_detects_missing(): void
    {
        $reports = [
            ['checks' => ['capability_contract_registered' => true, 'capability_preflight_registered' => false]],
        ];

        $gaps = AgentControlPlaneGapCollector::capabilityGaps([['slice_key' => 'slice_a']], $reports);

        self::assertCount(4, $gaps);
        self::assertSame('slice_a', $gaps[0]['slice_key']);
        self::assertSame('capability_preflight_registered', $gaps[0]['capability_check']);
    }

    public function test_collect_invoker_gaps_empty_when_ok(): void
    {
        $reports = [
            ['slice_key' => 'a', 'checks' => ['invoker_class_exists' => true, 'invoker_prepare_method_exists' => true]],
        ];

        self::assertSame([], AgentControlPlaneGapCollector::collectInvokerGaps($reports));
    }

    public function test_collect_invoker_gaps_detects_missing(): void
    {
        $reports = [
            ['slice_key' => 'a', 'invoker_class' => 'Foo', 'prepare_method' => 'bar', 'checks' => ['invoker_class_exists' => false, 'invoker_prepare_method_exists' => true]],
        ];

        $gaps = AgentControlPlaneGapCollector::collectInvokerGaps($reports);

        self::assertCount(1, $gaps);
        self::assertSame('Foo', $gaps[0]['invoker_class']);
        self::assertFalse($gaps[0]['invoker_class_exists']);
    }

    public function test_collect_readiness_gaps_empty_when_ok(): void
    {
        $reports = [
            ['slice_key' => 'a', 'checks' => ['contract_method_exists' => true, 'preflight_method_exists' => true, 'implementation_packet_method_exists' => true, 'status_method_exists' => true]],
        ];

        self::assertSame([], AgentControlPlaneGapCollector::collectReadinessGaps($reports));
    }

    public function test_collect_readiness_gaps_detects_missing(): void
    {
        $reports = [
            ['slice_key' => 'a', 'checks' => ['contract_method_exists' => true, 'preflight_method_exists' => false, 'implementation_packet_method_exists' => true, 'status_method_exists' => true]],
        ];

        $gaps = AgentControlPlaneGapCollector::collectReadinessGaps($reports);

        self::assertCount(1, $gaps);
        self::assertSame('preflight_method_exists', $gaps[0]['readiness_check']);
    }

    public function test_runtime_safety_gaps_empty_when_all_false(): void
    {
        $safety = ['runtime_safety_all_false' => true];

        self::assertSame([], AgentControlPlaneGapCollector::runtimeSafetyGaps($safety));
    }

    public function test_runtime_safety_gaps_detects_true_flags(): void
    {
        $safety = [
            'runtime_safety_all_false' => true,
            'codex_cli_invoked' => true,
            'dispatch_allowed_anywhere' => true,
        ];

        $gaps = AgentControlPlaneGapCollector::runtimeSafetyGaps($safety);

        self::assertContains('codex_cli_invoked', array_column($gaps, 'flag'));
        self::assertContains('dispatch_allowed_anywhere', array_column($gaps, 'flag'));
    }

    public function test_runtime_safety_gaps_reports_all_false_missing(): void
    {
        $gaps = AgentControlPlaneGapCollector::runtimeSafetyGaps([]);

        self::assertContains('runtime_safety_all_false', array_column($gaps, 'flag'));
    }

    public function test_all_methods_are_deterministic(): void
    {
        $reports = [['slice_key' => 'a', 'checks' => []]];

        self::assertSame(
            AgentControlPlaneGapCollector::collectReadinessGaps($reports),
            AgentControlPlaneGapCollector::collectReadinessGaps($reports),
        );
    }

    public function test_capability_gaps_include_severity_and_repair_hint(): void
    {
        $reports = [['checks' => ['capability_contract_registered' => false]]];
        $gaps = AgentControlPlaneGapCollector::capabilityGaps([['slice_key' => 'a']], $reports);

        self::assertNotEmpty($gaps);
        self::assertArrayHasKey('severity', $gaps[0]);
        self::assertArrayHasKey('repair_hint', $gaps[0]);
        self::assertNotEmpty($gaps[0]['severity']);
        self::assertNotEmpty($gaps[0]['repair_hint']);
    }

    public function test_invoker_gaps_include_severity_and_repair_hint(): void
    {
        $reports = [['slice_key' => 'a', 'invoker_class' => 'Foo', 'prepare_method' => 'bar', 'checks' => ['invoker_class_exists' => false, 'invoker_prepare_method_exists' => true]]];
        $gaps = AgentControlPlaneGapCollector::collectInvokerGaps($reports);

        self::assertNotEmpty($gaps);
        self::assertSame('high', $gaps[0]['severity']);
        self::assertSame('create_invoker_class', $gaps[0]['repair_hint']);
    }

    public function test_readiness_gaps_include_severity_and_repair_hint(): void
    {
        $reports = [['slice_key' => 'a', 'checks' => ['contract_method_exists' => true, 'preflight_method_exists' => false, 'implementation_packet_method_exists' => true, 'status_method_exists' => true]]];
        $gaps = AgentControlPlaneGapCollector::collectReadinessGaps($reports);

        self::assertNotEmpty($gaps);
        self::assertSame('medium', $gaps[0]['severity']);
        self::assertSame('implement_preflight_method', $gaps[0]['repair_hint']);
    }

    public function test_runtime_safety_gaps_include_severity_and_repair_hint(): void
    {
        $gaps = AgentControlPlaneGapCollector::runtimeSafetyGaps(['runtime_safety_all_false' => true, 'codex_cli_invoked' => true]);

        self::assertNotEmpty($gaps);
        self::assertSame('critical', $gaps[0]['severity']);
        self::assertStringStartsWith('disable_', $gaps[0]['repair_hint']);
        self::assertArrayHasKey('flag', $gaps[0]);
    }

    public function test_all_gap_methods_return_empty_for_green_reports(): void
    {
        $allChecksTrue = ['checks' => ['capability_contract_registered' => true, 'capability_preflight_registered' => true, 'capability_implementation_packet_registered' => true, 'capability_invoker_service_registered' => true, 'capability_status_projection_registered' => true, 'invoker_class_exists' => true, 'invoker_prepare_method_exists' => true, 'contract_method_exists' => true, 'preflight_method_exists' => true, 'implementation_packet_method_exists' => true, 'status_method_exists' => true]];

        self::assertSame([], AgentControlPlaneGapCollector::capabilityGaps([['slice_key' => 'a']], [$allChecksTrue]));
        self::assertSame([], AgentControlPlaneGapCollector::collectInvokerGaps([$allChecksTrue]));
        self::assertSame([], AgentControlPlaneGapCollector::collectReadinessGaps([$allChecksTrue]));
        self::assertSame([], AgentControlPlaneGapCollector::runtimeSafetyGaps(['runtime_safety_all_false' => true]));
    }
}
