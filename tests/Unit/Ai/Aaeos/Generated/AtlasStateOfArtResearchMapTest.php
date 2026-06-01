<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasStateOfArtResearchMapService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cognitive Runtime State Of Art Research Map
 * contracts: the posture classification table, the seven-item Research Promotion
 * Rule, and the six Guardrails.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/state-of-art-research-map.md
 */
class AtlasStateOfArtResearchMapTest extends TestCase
{
    private function service(): AtlasStateOfArtResearchMapService
    {
        return new AtlasStateOfArtResearchMapService();
    }

    /**
     * Posture table: the doc marks Hybrid RAG (and Agentic memory) as `aligned`
     * — implementable now as governed memory/retrieval — while KV compression is
     * `research_only` and therefore NOT implementable on its own.
     */
    public function test_posture_table_maps_aligned_vs_research_only_areas(): void
    {
        $svc = $this->service();

        $hybrid = $svc->classifyArea('hybrid_rag');
        $this->assertSame(AtlasStateOfArtResearchMapService::POSTURE_ALIGNED, $hybrid['posture']);
        $this->assertTrue($hybrid['implementable_now']);
        $this->assertFalse($hybrid['requires_promotion_rule']);

        $kv = $svc->classifyArea('kv_compression');
        $this->assertSame(AtlasStateOfArtResearchMapService::POSTURE_RESEARCH_ONLY, $kv['posture']);
        $this->assertFalse($kv['implementable_now']);
        $this->assertTrue($kv['requires_promotion_rule']);
    }

    /**
     * Posture table is closed: an area not on the research map fails closed —
     * unknown, not implementable, and routed back to research.
     */
    public function test_unknown_area_fails_closed(): void
    {
        $verdict = $this->service()->classifyArea('some_unlisted_technique');

        $this->assertFalse($verdict['known']);
        $this->assertNull($verdict['posture']);
        $this->assertFalse($verdict['implementable_now']);
        $this->assertTrue($verdict['requires_promotion_rule']);
    }

    /**
     * Research Promotion Rule: all SEVEN items must be present. A checklist with
     * the full seven promotes; dropping exactly one (here the internal measurement)
     * fails closed and names that one missing item.
     */
    public function test_promotion_rule_requires_all_seven_items(): void
    {
        $svc = $this->service();

        $full = array_fill_keys(AtlasStateOfArtResearchMapService::PROMOTION_REQUIREMENTS, true);
        $allIn = $svc->evaluatePromotion($full);
        $this->assertTrue($allIn['promotable']);
        $this->assertSame(7, $allIn['required_count']);
        $this->assertSame(7, $allIn['satisfied_count']);
        $this->assertSame([], $allIn['missing']);

        $missingMeasure = $full;
        $missingMeasure['internal_measurement'] = false;
        $blocked = $svc->evaluatePromotion($missingMeasure);
        $this->assertFalse($blocked['promotable']);
        $this->assertSame(['internal_measurement'], $blocked['missing']);
        $this->assertSame(6, $blocked['satisfied_count']);
    }

    /**
     * Guardrails: using a huge window to mask bad retrieval, and indexing a raw
     * transcript as memory, are both prohibited — screenAction rejects the action
     * and names both violated guardrails. A clean action passes.
     */
    public function test_guardrails_reject_prohibited_actions(): void
    {
        $svc = $this->service();

        $bad = $svc->screenAction([
            'huge_window_masks_retrieval' => true,
            'raw_transcript_as_memory' => true,
        ]);
        $this->assertFalse($bad['allowed']);
        $this->assertContains('huge_window_masks_retrieval', $bad['violations']);
        $this->assertContains('raw_transcript_as_memory', $bad['violations']);
        $this->assertCount(2, $bad['violations']);

        $clean = $svc->screenAction([]);
        $this->assertTrue($clean['allowed']);
        $this->assertSame([], $clean['violations']);
    }

    /**
     * Composition: a `candidate`-posture technique (long_contextual_rag) that is
     * missing one promotion item is blocked even with clean guardrails; supplying
     * the missing item and keeping guardrails clean flips it to implementable.
     */
    public function test_promote_composes_posture_gate_and_guardrails(): void
    {
        $svc = $this->service();

        $full = array_fill_keys(AtlasStateOfArtResearchMapService::PROMOTION_REQUIREMENTS, true);

        $missingOne = $full;
        $missingOne['internal_measurement'] = false;
        $blocked = $svc->promote('long_contextual_rag', $missingOne, []);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertFalse($blocked['implementable']);
        $this->assertContains('promotion:internal_measurement', $blocked['blocking_reasons']);

        $ok = $svc->promote('long_contextual_rag', $full, []);
        $this->assertSame('implementable', $ok['status']);
        $this->assertTrue($ok['implementable']);
        $this->assertSame([], $ok['blocking_reasons']);
    }

    /**
     * Composition: a guardrail violation always blocks, even when the area is
     * already `aligned` (which would not otherwise need the promotion gate).
     */
    public function test_guardrail_violation_blocks_even_aligned_area(): void
    {
        $svc = $this->service();

        $verdict = $svc->promote(
            'hybrid_rag',
            array_fill_keys(AtlasStateOfArtResearchMapService::PROMOTION_REQUIREMENTS, true),
            ['vector_before_privacy_filters' => true],
        );

        $this->assertSame('blocked', $verdict['status']);
        $this->assertFalse($verdict['implementable']);
        $this->assertContains('guardrail:vector_before_privacy_filters', $verdict['blocking_reasons']);
    }
}
