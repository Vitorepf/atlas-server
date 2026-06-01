<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasKernelStaticScansService;
use Tests\TestCase;

/**
 * Pins the documented Kernel Static Scans rules: the "Required Scan Behavior"
 * 8-field envelope, the "Scan Families" classification (including AP-numbered
 * ids), the merge gate from a failing merge-blocking family, and the doctrine
 * that compliance needs at least one negative regression case.
 *
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/kernel/static-scans.md
 */
class AtlasKernelStaticScansTest extends TestCase
{
    private function service(): AtlasKernelStaticScansService
    {
        return new AtlasKernelStaticScansService();
    }

    /**
     * Required Scan Behavior: the normalized envelope must surface every
     * documented field, and violation_count must be DERIVED from the violations
     * list (not trusted from input). A passing scan never blocks merge.
     */
    public function test_normalize_scan_surfaces_required_behavior_fields(): void
    {
        $scan = $this->service()->normalizeScan([
            'scan_id' => 'ap1_surface_provider_bypass',
            'violations' => [],
        ]);

        // All seven documented fields present.
        foreach (['scan_id', 'pass', 'violation_count', 'paths', 'symbols', 'owner_area', 'remediation_hint', 'blocks_merge'] as $key) {
            $this->assertArrayHasKey($key, $scan, "missing documented field: {$key}");
        }

        $this->assertTrue($scan['pass']);
        $this->assertSame(AtlasKernelStaticScansService::STATUS_PASS, $scan['status']);
        $this->assertSame(0, $scan['violation_count']);
        // surface_provider_bypass is a merge-blocking family, but a PASSING
        // scan must never block merge.
        $this->assertFalse($scan['blocks_merge']);
        $this->assertSame('surface_provider_bypass', $scan['family']);
        $this->assertSame('kernel', $scan['owner_area']);
    }

    /**
     * violation_count is recomputed from the list and a failing scan in a
     * merge-blocking family is reported as blocks_merge=true with a remediation
     * hint and the involved paths.
     */
    public function test_failing_blocking_family_blocks_merge_and_counts_violations(): void
    {
        $scan = $this->service()->normalizeScan([
            'scan_id' => 'ap201_runtime_language_boundary_contract',
            // Input deliberately omits any count; service derives it (here: 2).
            'violations' => ['EmbeddingService imports a RAG library', 'controller shells out via Process'],
            'paths' => ['app/Services/EmbeddingService.php'],
        ]);

        $this->assertFalse($scan['pass']);
        $this->assertSame(AtlasKernelStaticScansService::STATUS_FAIL, $scan['status']);
        $this->assertSame(2, $scan['violation_count']);
        $this->assertSame('runtime_language_boundary', $scan['family']);
        // Runtime language boundary is a merge-blocking family per the doctrine.
        $this->assertTrue($scan['blocks_merge']);
        $this->assertNotSame('', $scan['remediation_hint']);
        $this->assertTrue($scan['contract_complete']);
    }

    /**
     * A failing scan in a NON-blocking family (capability parity / docs health)
     * surfaces the violation but must NOT block merge.
     */
    public function test_failing_non_blocking_family_does_not_block_merge(): void
    {
        $scan = $this->service()->normalizeScan([
            'scan_id' => 'ap33_surface_capability_parity',
            'violations' => ['Paste-image only in atlas ask'],
            'symbols' => ['SurfaceCapabilityParityService'],
        ]);

        $this->assertFalse($scan['pass']);
        $this->assertSame('capability_parity', $scan['family']);
        $this->assertFalse($scan['blocks_merge']);
    }

    /**
     * Scan Families classification: AP-numbered ids documented in the doc table
     * map onto their canonical family with the right merge severity.
     */
    public function test_classify_family_maps_documented_ap_ids(): void
    {
        $svc = $this->service();

        $voice = $svc->classifyFamily('ap687_voice_realtime_production_promotion_gate');
        $this->assertSame('voice_production_promotion', $voice['family']);
        $this->assertTrue($voice['known']);
        $this->assertTrue($voice['blocks_merge']);

        $lens = $svc->classifyFamily('ap685_constelacao_lens1_usage_review_contract');
        $this->assertSame('constelacao_lens1_usage_review', $lens['family']);
        $this->assertFalse($lens['blocks_merge']);

        $unknown = $svc->classifyFamily('ap999_not_a_real_scan');
        $this->assertSame('unknown', $unknown['family']);
        $this->assertFalse($unknown['known']);
    }

    /**
     * Doctrine: "Compliance tests must include at least one negative regression
     * case when possible." A run with no negative regression is flagged as a
     * doctrine gap and is NOT compliant, even when every scan passes.
     */
    public function test_summary_flags_missing_negative_regression(): void
    {
        $svc = $this->service();

        $without = $svc->summarize([
            ['scan_id' => 'ap1_surface_provider_bypass', 'violations' => []],
        ], false);

        $this->assertContains('no_negative_regression_case', $without['doctrine_gaps']);
        $this->assertFalse($without['compliant']);
        // All scans pass, so the merge gate itself is open.
        $this->assertSame(AtlasKernelStaticScansService::MERGE_GATE_OPEN, $without['merge_gate']);

        $with = $svc->summarize([
            ['scan_id' => 'ap1_surface_provider_bypass', 'violations' => []],
        ], true);

        $this->assertNotContains('no_negative_regression_case', $with['doctrine_gaps']);
        $this->assertTrue($with['compliant']);
    }

    /**
     * Doctrine: "Static scans prevent bypasses before runtime." A single
     * failing scan in a merge-blocking family flips the overall merge gate to
     * blocked and lists the offending scan id.
     */
    public function test_summary_merge_gate_blocks_on_blocking_failure(): void
    {
        $summary = $this->service()->summarize([
            ['scan_id' => 'ap6_decision_receipt_propagation', 'violations' => ['atlas dev provider call without receipt'], 'paths' => ['app/Foo.php']],
            ['scan_id' => 'ap33_surface_capability_parity', 'violations' => []],
        ], true);

        $this->assertSame(AtlasKernelStaticScansService::MERGE_GATE_BLOCKED, $summary['merge_gate']);
        $this->assertContains('ap6_decision_receipt_propagation', $summary['blocking_failures']);
        $this->assertFalse($summary['compliant']);
        $this->assertSame(1, $summary['failed_count']);
        $this->assertSame(1, $summary['passed_count']);
        $this->assertSame(1, $summary['violation_count']);
    }
}
