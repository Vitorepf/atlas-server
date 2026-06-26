<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ChainIntegrity;

/**
 * Collects gap reports from deep-chain slice reports and runtime safety flags.
 *
 * Extracted from AgentControlPlaneChainIntegrityAuditService to reduce the
 * god-class. All methods are pure — no instance state.
 */
final class AgentControlPlaneGapCollector
{
    /**
     * @param  list<array<string, string>>  $deepChain
     * @param  list<array<string, mixed>>  $sliceReports
     * @return list<array<string, mixed>>
     */
    public static function capabilityGaps(array $deepChain, array $sliceReports): array
    {
        $gaps = [];
        foreach ($sliceReports as $index => $report) {
            $checks = (array) ($report['checks'] ?? []);
            foreach (['capability_contract_registered', 'capability_preflight_registered', 'capability_implementation_packet_registered', 'capability_invoker_service_registered', 'capability_status_projection_registered'] as $key) {
                if (($checks[$key] ?? false) !== true) {
                    $gaps[] = [
                        'slice_key' => $deepChain[$index]['slice_key'] ?? '',
                        'capability_check' => $key,
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return list<array<string, mixed>>
     */
    public static function collectInvokerGaps(array $sliceReports): array
    {
        $gaps = [];
        foreach ($sliceReports as $report) {
            $checks = (array) ($report['checks'] ?? []);
            if (($checks['invoker_class_exists'] ?? false) !== true || ($checks['invoker_prepare_method_exists'] ?? false) !== true) {
                $gaps[] = [
                    'slice_key' => (string) ($report['slice_key'] ?? ''),
                    'invoker_class' => (string) ($report['invoker_class'] ?? ''),
                    'prepare_method' => (string) ($report['prepare_method'] ?? ''),
                    'invoker_class_exists' => (bool) ($checks['invoker_class_exists'] ?? false),
                    'invoker_prepare_method_exists' => (bool) ($checks['invoker_prepare_method_exists'] ?? false),
                ];
            }
        }

        return $gaps;
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return list<array<string, mixed>>
     */
    public static function collectReadinessGaps(array $sliceReports): array
    {
        $gaps = [];
        foreach ($sliceReports as $report) {
            $checks = (array) ($report['checks'] ?? []);
            foreach (['contract_method_exists', 'preflight_method_exists', 'implementation_packet_method_exists', 'status_method_exists'] as $key) {
                if (($checks[$key] ?? false) !== true) {
                    $gaps[] = [
                        'slice_key' => (string) ($report['slice_key'] ?? ''),
                        'readiness_check' => $key,
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $runtimeSafety
     * @return list<string>
     */
    public static function runtimeSafetyGaps(array $runtimeSafety): array
    {
        $gaps = [];
        $expectedFalse = [
            'actual_process_start_allowed_anywhere',
            'provider_process_call_allowed_anywhere',
            'adapter_invocation_allowed_anywhere',
            'adapter_execution_allowed_anywhere',
            'dispatch_allowed_anywhere',
            'token_spend_allowed_anywhere',
            'self_programming_allowed_anywhere',
            'external_process_started_by_atlas',
            'codex_cli_invoked',
            'shell_spawned_by_runtime',
        ];
        foreach ($expectedFalse as $flag) {
            if ((bool) ($runtimeSafety[$flag] ?? false) === true) {
                $gaps[] = $flag;
            }
        }
        if (($runtimeSafety['runtime_safety_all_false'] ?? false) !== true) {
            $gaps[] = 'runtime_safety_all_false';
        }

        return $gaps;
    }
}
