<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegritySurfaceAuditor;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneChainIntegritySurfaceAuditorTest extends TestCase
{
    public function test_documentation_audit_reports_contract_doc_present(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $out = $auditor->documentationAudit([]);

        $this->assertArrayHasKey('contract_doc_present', $out);
        $this->assertIsBool($out['contract_doc_present']);
        $this->assertSame('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md', $out['contract_doc_path']);
        $this->assertSame([], $out['slice_bullets_found']);
        $this->assertSame([], $out['duplicate_slice_bullets']);
        $this->assertGreaterThan(0, $out['contract_doc_byte_size']);
    }

    public function test_cli_surface_returns_expected_envelope_with_aligned_flag(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $out = $auditor->cliSurface([]);

        $this->assertSame('atlas:ai:self-construction', $out['command_name']);
        $this->assertIsArray($out['expected_options']);
        $this->assertIsArray($out['present_options']);
        $this->assertIsArray($out['missing_options']);
        $this->assertIsBool($out['handlers_aligned']);
        // Baseline options always present even when deepChain is empty.
        $this->assertContains('agent-control-plane', $out['expected_options']);
        $this->assertContains('agent-control-plane-chain-integrity-certification', $out['expected_options']);
    }

    public function test_all_quartet_methods_present_returns_true_when_all_checks_pass(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => true,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => true,
            ]],
        ];

        $this->assertTrue($auditor->allQuartetMethodsPresent($sliceReports));
    }

    public function test_all_quartet_methods_present_returns_false_when_any_check_fails(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $sliceReports = [
            ['checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => false,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => true,
            ]],
        ];

        $this->assertFalse($auditor->allQuartetMethodsPresent($sliceReports));
    }

    public function test_invoker_surface_reports_missing_when_invoker_class_does_not_exist(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $deepChain = [
            ['slice_key' => 'fake_slice', 'invoker_class' => 'NonExistent\\Class\\Here', 'prepare_method' => 'prepare'],
        ];

        $out = $auditor->invokerSurface($deepChain);

        $this->assertSame(1, $out['deep_checked']);
        $this->assertSame(['fake_slice'], $out['missing']);
    }

    public function test_invoker_surface_reports_missing_when_prepare_method_missing(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $deepChain = [
            ['slice_key' => 'fake_slice', 'invoker_class' => self::class, 'prepare_method' => 'thisMethodDoesNotExist'],
        ];

        $out = $auditor->invokerSurface($deepChain);

        $this->assertSame(1, $out['deep_checked']);
        $this->assertSame(['fake_slice'], $out['missing']);
    }

    public function test_invoker_surface_reports_no_missing_for_real_class_with_real_method(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $deepChain = [
            ['slice_key' => 'real_slice', 'invoker_class' => self::class, 'prepare_method' => 'test_invoker_surface_reports_no_missing_for_real_class_with_real_method'],
        ];

        $out = $auditor->invokerSurface($deepChain);

        $this->assertSame(1, $out['deep_checked']);
        $this->assertSame([], $out['missing']);
    }

    public function test_test_surface_envelope_reports_both_test_paths(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $out = $auditor->testSurface();

        $this->assertArrayHasKey('dedicated_test_present', $out);
        $this->assertArrayHasKey('command_test_present', $out);
        $this->assertIsBool($out['dedicated_test_present']);
        $this->assertIsBool($out['command_test_present']);
    }

    public function test_count_duplicates_returns_zero_for_unique_values(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $this->assertSame(0, $auditor->countDuplicates(['a', 'b', 'c']));
    }

    public function test_count_duplicates_returns_positive_for_repeated_values(): void
    {
        $auditor = new AgentControlPlaneChainIntegritySurfaceAuditor;

        $this->assertSame(3, $auditor->countDuplicates(['a', 'b', 'a', 'c', 'a', 'b']));
    }
}