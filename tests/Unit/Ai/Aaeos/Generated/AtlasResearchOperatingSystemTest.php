<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasResearchOperatingSystemService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Research Operating System rules: the ordered
 * pipeline, the Core Rule (verified claims, not knowledge) and the
 * Implementation-Phase required gates.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
 */
class AtlasResearchOperatingSystemTest extends TestCase
{
    private function service(): AtlasResearchOperatingSystemService
    {
        return new AtlasResearchOperatingSystemService();
    }

    /**
     * The full canonical pipeline, in order, is valid; the next stage after a
     * complete run is null.
     */
    public function test_full_pipeline_in_order_is_valid(): void
    {
        $d = $this->service()->validatePipeline(AtlasResearchOperatingSystemService::PIPELINE);

        $this->assertTrue($d['valid']);
        $this->assertSame([], $d['violations']);
        $this->assertSame([], $d['missing_mandatory']);
        $this->assertNull($d['next_stage'], 'a complete pipeline has no next stage');
    }

    /**
     * Architecture invariant: stages presented out of order are rejected with an
     * explicit out_of_order violation (evidence_lake cannot precede its source).
     */
    public function test_out_of_order_stage_is_rejected(): void
    {
        // evidence_lake appears before source_registry / collectors.
        $d = $this->service()->validatePipeline([
            'objective',
            'scheduler',
            'evidence_lake',
            'source_registry',
        ]);

        $this->assertFalse($d['valid']);
        $this->assertFalse($d['order_ok']);
        $this->assertContains('out_of_order_stage:source_registry', $d['violations']);
    }

    /**
     * "No phase may skip evidence, citation health or promotion gates" — a run
     * that omits a mandatory stage is invalid and names the skipped stage. Here a
     * partial in-order prefix that never reaches claim_verification is missing
     * the downstream mandatory gates.
     */
    public function test_skipping_mandatory_stage_is_rejected(): void
    {
        $d = $this->service()->validatePipeline([
            'objective',
            'scheduler',
            'source_registry',
            'collectors',
            'evidence_lake',
        ]);

        $this->assertFalse($d['valid']);
        $this->assertContains('claim_verification', $d['missing_mandatory']);
        $this->assertContains('citation_health', $d['missing_mandatory']);
        $this->assertContains('promotion', $d['missing_mandatory']);
        $this->assertContains('skipped_mandatory_stage:promotion', $d['violations']);
        // The in-order prefix is itself clean, so the next stage is hybrid_index.
        $this->assertSame('hybrid_index', $d['next_stage']);
    }

    /**
     * Core Rule: a report whose every claim is verified, evidence-linked and
     * citation-healthy may publish.
     */
    public function test_core_rule_allows_fully_supported_report(): void
    {
        $d = $this->service()->gateReport([
            ['verified' => true, 'has_evidence' => true, 'citation_healthy' => true],
            ['verified' => true, 'has_evidence' => true, 'citation_healthy' => true],
        ]);

        $this->assertSame(AtlasResearchOperatingSystemService::ACTION_PROMOTE, $d['action']);
        $this->assertTrue($d['may_publish']);
        $this->assertSame(2, $d['verified_claims']);
        $this->assertSame([], $d['unsupported_claims']);
        $this->assertNull($d['block_reason']);
    }

    /**
     * Core Rule: "Atlas must not publish knowledge." A single unverified /
     * evidence-less claim blocks the whole report, with the offending index and
     * reasons surfaced.
     */
    public function test_core_rule_blocks_report_with_unsupported_claim(): void
    {
        $d = $this->service()->gateReport([
            ['verified' => true, 'has_evidence' => true, 'citation_healthy' => true],
            ['verified' => false, 'has_evidence' => false, 'citation_healthy' => true],
        ]);

        $this->assertSame(AtlasResearchOperatingSystemService::ACTION_BLOCK, $d['action']);
        $this->assertFalse($d['may_publish']);
        $this->assertSame('unsupported_claims_present', $d['block_reason']);
        $this->assertSame(1, $d['unsupported_claims'][0]['index']);
        $this->assertContains('unverified', $d['unsupported_claims'][0]['reasons']);
        $this->assertContains('no_evidence_link', $d['unsupported_claims'][0]['reasons']);
    }

    /**
     * Core Rule edge case: an empty report has nothing verified to publish, so it
     * is blocked rather than treated as a vacuously-true publish.
     */
    public function test_core_rule_blocks_empty_report(): void
    {
        $d = $this->service()->gateReport([]);

        $this->assertSame(AtlasResearchOperatingSystemService::ACTION_BLOCK, $d['action']);
        $this->assertSame('no_verified_claims', $d['block_reason']);
    }

    /**
     * Implementation Phase: a known phase with all three required gates green is
     * ready; dropping any one gate flips it to not-ready and names the gap.
     */
    public function test_phase_requires_all_three_gates(): void
    {
        $ready = $this->service()->evaluatePhase([
            'phase' => 3,
            'gates' => ['evidence' => true, 'citation_health' => true, 'promotion' => true],
        ]);
        $this->assertTrue($ready['ready']);
        $this->assertSame('claim_extraction_and_citation_health', $ready['phase_name']);
        $this->assertSame([], $ready['missing_gates']);

        $missing = $this->service()->evaluatePhase([
            'phase' => 3,
            'gates' => ['evidence' => true, 'citation_health' => false, 'promotion' => true],
        ]);
        $this->assertFalse($missing['ready']);
        $this->assertSame(AtlasResearchOperatingSystemService::ACTION_BLOCK, $missing['action']);
        $this->assertSame(['citation_health'], $missing['missing_gates']);
    }

    /**
     * The Promotion Gate component owns the docs/AP/code/memory/report routing
     * decision (doc "Components").
     */
    public function test_promotion_gate_component_responsibility(): void
    {
        $d = $this->service()->describeComponent('promotion_gate');

        $this->assertTrue($d['known']);
        $this->assertSame('Decides docs/AP/code/memory/report action.', $d['responsibility']);
    }
}
