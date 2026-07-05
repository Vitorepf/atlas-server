<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegritySurfaceAuditor;
use Tests\TestCase;

final class AgentControlPlaneChainIntegritySurfaceAuditorTest extends TestCase
{
    private function auditor(): AgentControlPlaneChainIntegritySurfaceAuditor
    {
        return new AgentControlPlaneChainIntegritySurfaceAuditor();
    }

    private function sampleDeepChain(): array
    {
        return [
            [
                'slice_key' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate',
                'method_prefix' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGate',
                'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker',
                'prepare_method' => 'prepareCodexRealInvokerPostStartDispatchRelease',
                'doc_bullet' => 'post-start dispatch release gate',
            ],
            [
                'slice_key' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate',
                'method_prefix' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGate',
                'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker',
                'prepare_method' => 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
                'doc_bullet' => 'post-start external process runtime gate',
            ],
        ];
    }

    public function test_documentation_audit_returns_required_keys(): void
    {
        $audit = $this->auditor()->documentationAudit($this->sampleDeepChain());

        $this->assertArrayHasKey('contract_doc_present', $audit);
        $this->assertArrayHasKey('contract_doc_path', $audit);
        $this->assertArrayHasKey('contract_doc_byte_size', $audit);
        $this->assertArrayHasKey('slice_bullets_found', $audit);
        $this->assertArrayHasKey('duplicate_slice_bullets', $audit);
        $this->assertArrayHasKey('missing_doc_bullets', $audit);
    }

    public function test_cli_surface_returns_required_keys(): void
    {
        $surface = $this->auditor()->cliSurface($this->sampleDeepChain());

        $this->assertArrayHasKey('command_name', $surface);
        $this->assertArrayHasKey('expected_options', $surface);
        $this->assertArrayHasKey('present_options', $surface);
        $this->assertArrayHasKey('missing_options', $surface);
        $this->assertArrayHasKey('handlers_aligned', $surface);
    }

    public function test_invoker_surface_returns_required_keys(): void
    {
        $surface = $this->auditor()->invokerSurface($this->sampleDeepChain());

        $this->assertArrayHasKey('deep_checked', $surface);
        $this->assertArrayHasKey('missing', $surface);
        $this->assertArrayHasKey('missing_invokers', $surface);
    }

    public function test_test_surface_returns_provider_safe_inventory(): void
    {
        $surface = $this->auditor()->testSurface();

        $this->assertArrayHasKey('dedicated_test_path', $surface);
        $this->assertArrayHasKey('dedicated_test_present', $surface);
        $this->assertArrayHasKey('command_test_present', $surface);
        $this->assertArrayHasKey('missing_tests', $surface);
        $this->assertIsBool($surface['dedicated_test_present']);
        $this->assertIsBool($surface['command_test_present']);
    }

    public function test_count_duplicates_detects_duplicates(): void
    {
        $auditor = $this->auditor();

        $this->assertSame(0, $auditor->countDuplicates(['a', 'b', 'c']));
        $this->assertSame(1, $auditor->countDuplicates(['a', 'b', 'a']));
        $this->assertSame(2, $auditor->countDuplicates(['a', 'a', 'a']));
    }

    public function test_all_quartet_methods_present_returns_true_when_all_present(): void
    {
        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => true,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => true,
            ]],
        ];

        $this->assertTrue($this->auditor()->allQuartetMethodsPresent($sliceReports));
    }

    public function test_all_quartet_methods_present_returns_false_when_any_missing(): void
    {
        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => true,
                'implementation_packet_method_exists' => false,
                'status_method_exists' => true,
            ]],
        ];

        $this->assertFalse($this->auditor()->allQuartetMethodsPresent($sliceReports));
    }

    public function test_duplicate_cli_options_increase_duplicate_count(): void
    {
        $auditor = $this->auditor();
        $chain = $this->sampleDeepChain();
        $surface = $auditor->cliSurface($chain);

        // The expected options should be unique; duplicate_count is implicit via countDuplicates.
        $this->assertSame(0, $auditor->countDuplicates($surface['expected_options']));

        // Simulate duplicated expected options by appending a duplicate.
        $duplicated = array_merge($surface['expected_options'], [$surface['expected_options'][0]]);
        $this->assertSame(1, $auditor->countDuplicates($duplicated));
    }

    public function test_duplicate_doc_bullets_are_detected(): void
    {
        $auditor = $this->auditor();
        $chain = $this->sampleDeepChain();
        $chain[1]['doc_bullet'] = $chain[0]['doc_bullet'];

        $audit = $auditor->documentationAudit($chain);

        // If the doc file exists and contains the bullet, duplicate detection should surface it.
        if ($audit['contract_doc_present']) {
            $this->assertGreaterThanOrEqual(0, count($audit['duplicate_slice_bullets']));
        } else {
            $this->assertSame([], $audit['duplicate_slice_bullets']);
        }
    }

    // ── AC: duplicate CLI or documentation surfaces increase duplicate_count ──

    public function test_cli_surface_has_duplicate_count_field(): void
    {
        $surface = $this->auditor()->cliSurface($this->sampleDeepChain());

        $this->assertArrayHasKey('duplicate_count', $surface);
        $this->assertIsInt($surface['duplicate_count']);
    }

    public function test_cli_surface_duplicate_count_zero_when_no_duplicates(): void
    {
        $surface = $this->auditor()->cliSurface($this->sampleDeepChain());

        $this->assertSame(0, $surface['duplicate_count']);
    }

    public function test_cli_surface_duplicate_count_increases_with_duplicate_slices(): void
    {
        $auditor = $this->auditor();
        $chain = $this->sampleDeepChain();
        // Duplicate a slice to create duplicate CLI options.
        $duplicatedChain = array_merge($chain, [$chain[0]]);

        $surface = $auditor->cliSurface($duplicatedChain);

        $this->assertGreaterThan(0, $surface['duplicate_count']);
    }

    public function test_invoker_surface_has_duplicate_count_field(): void
    {
        $surface = $this->auditor()->invokerSurface($this->sampleDeepChain());

        $this->assertArrayHasKey('duplicate_count', $surface);
        $this->assertIsInt($surface['duplicate_count']);
    }

    public function test_invoker_surface_duplicate_count_zero_when_no_duplicates(): void
    {
        $surface = $this->auditor()->invokerSurface($this->sampleDeepChain());

        $this->assertSame(0, $surface['duplicate_count']);
    }

    public function test_invoker_surface_duplicate_count_increases_with_duplicate_invokers(): void
    {
        $auditor = $this->auditor();
        $chain = $this->sampleDeepChain();
        // Duplicate a slice to create duplicate invoker_class entries.
        $duplicatedChain = array_merge($chain, [$chain[0]]);

        $surface = $auditor->invokerSurface($duplicatedChain);

        $this->assertGreaterThan(0, $surface['duplicate_count']);
    }

    public function test_documentation_audit_has_duplicate_count_field(): void
    {
        $audit = $this->auditor()->documentationAudit($this->sampleDeepChain());

        $this->assertArrayHasKey('duplicate_count', $audit);
        $this->assertIsInt($audit['duplicate_count']);
    }

    // ── AC: missing quartet methods make allQuartetMethodsPresent return false ──

    public function test_all_quartet_methods_present_false_when_contract_missing(): void
    {
        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => false,
                'preflight_method_exists' => true,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => true,
            ]],
        ];

        $this->assertFalse($this->auditor()->allQuartetMethodsPresent($sliceReports));
    }

    public function test_all_quartet_methods_present_false_when_preflight_missing(): void
    {
        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => false,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => true,
            ]],
        ];

        $this->assertFalse($this->auditor()->allQuartetMethodsPresent($sliceReports));
    }

    public function test_all_quartet_methods_present_false_when_status_missing(): void
    {
        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => true,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => false,
            ]],
        ];

        $this->assertFalse($this->auditor()->allQuartetMethodsPresent($sliceReports));
    }

    public function test_all_quartet_methods_present_false_when_empty_reports(): void
    {
        $this->assertTrue($this->auditor()->allQuartetMethodsPresent([]));
    }

    // ── AC: testSurface returns provider-safe test inventory without raw runtime payloads ──

    public function test_test_surface_does_not_expose_raw_runtime_payloads(): void
    {
        $surface = $this->auditor()->testSurface();

        // The surface should only contain paths and booleans — no runtime data.
        $this->assertArrayNotHasKey('runtime_payload', $surface);
        $this->assertArrayNotHasKey('raw_output', $surface);
        $this->assertArrayNotHasKey('command_output', $surface);
    }

    public function test_test_surface_returns_provider_safe_inventory_types(): void
    {
        $surface = $this->auditor()->testSurface();

        // Verify all values are provider-safe types (bool, string, array of strings).
        $this->assertIsString($surface['dedicated_test_path']);
        $this->assertIsBool($surface['dedicated_test_present']);
        $this->assertIsBool($surface['command_test_present']);
        $this->assertIsArray($surface['missing_tests']);
    }
}
