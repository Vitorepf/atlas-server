<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Context\RetrievalAgendaComposer;
use Tests\TestCase;

/**
 * ESP-11 — Retrieval Agenda epistemológica (claims, unknowns, counter-evidence).
 */
final class Esp11RetrievalAgendaComposerTest extends TestCase
{
    public function test_explicit_claim_lists_claim_and_expected_source(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose(
            'The command atlas:context:pack must stay provider-safe.',
        );

        $this->assertTrue($agenda['present']);
        $this->assertNotEmpty($agenda['claims']);
        $this->assertSame('must', $agenda['claims'][0]['verb']);
        $this->assertSame('normative', $agenda['claims'][0]['kind']);
        $this->assertSame('code_graph', $agenda['claims'][0]['expected_source']);
        $this->assertSame('code_graph', $agenda['claims'][0]['source_query']['source']);
        $this->assertStringContainsString('provider-safe', $agenda['claims'][0]['source_query']['query']);
        $this->assertStringContainsString('provider-safe', $agenda['claims'][0]['claim']);
    }

    public function test_essential_unknown_without_facet_hit_sets_named_gap(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose(
            'How does the rollback verifier prove negative search?',
            ['facets' => []],
        );

        $this->assertTrue($agenda['present']);
        $this->assertTrue($agenda['not_enough_context']);
        $this->assertNotEmpty($agenda['named_unknown_gaps']);
        $this->assertSame('mechanism', $agenda['named_unknown_gaps'][0]['taxonomy']);
        $this->assertSame('rollback verifier prove negative search', $agenda['named_unknown_gaps'][0]['name']);
        $this->assertSame(
            'expand:unknown:mechanism:rollback verifier prove negative search',
            $agenda['named_unknown_gaps'][0]['handle'],
        );
        $this->assertStringContainsString(
            'rollback verifier',
            (string) $agenda['named_unknown_gaps'][0]['unknown'],
        );
    }

    public function test_claim_emits_honest_empty_counter_evidence_slot(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose('The policy must enforce scoped commits only.');

        $this->assertCount(1, $agenda['counter_evidence_slots']);
        $this->assertSame($agenda['claims'][0]['claim'], $agenda['counter_evidence_slots'][0]['claim']);
        $this->assertSame('anti_confirmation', $agenda['counter_evidence_slots'][0]['origin']);
        $this->assertSame('empty_honest', $agenda['counter_evidence_slots'][0]['status']);
        $this->assertSame(1, $agenda['counter_evidence_slots'][0]['sources_expected']);
        $this->assertSame('memory', $agenda['counter_evidence_slots'][0]['source']);
        $this->assertStringContainsString('counter evidence', $agenda['counter_evidence_slots'][0]['query']);
        $this->assertSame([], $agenda['counter_evidence_slots'][0]['refs_against']);
    }

    public function test_task_without_claims_or_unknowns_returns_empty_agenda(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose('Investigate MissingSymbolXYZ implementation');

        $this->assertFalse($agenda['present']);
        $this->assertSame([], $agenda['claims']);
        $this->assertSame([], $agenda['unknowns']);
        $this->assertSame([], $agenda['counter_evidence_slots']);
        $this->assertFalse($agenda['not_enough_context']);
        $this->assertFalse($agenda['source']['wired_into_packfor']);
    }

    public function test_phrase_quoted_claim_is_preserved_verbatim(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose('Verify "facet retrieval stays behind atlas.aobg.facet_retrieval" today.');

        $this->assertTrue($agenda['present']);
        $this->assertSame('quoted', $agenda['claims'][0]['verb']);
        $this->assertSame(
            'facet retrieval stays behind atlas.aobg.facet_retrieval',
            $agenda['claims'][0]['claim'],
        );
    }

    public function test_essential_unknown_with_facet_hit_is_not_a_gap(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose(
            'How does TaskFacetExtractor extract symbols?',
            ['facets' => [
                ['type' => 'symbol', 'value' => 'TaskFacetExtractor', 'essential' => true],
            ]],
        );

        $this->assertTrue($agenda['present']);
        $this->assertFalse($agenda['not_enough_context']);
        $this->assertSame([], $agenda['named_unknown_gaps']);
    }

    public function test_essential_unknown_with_only_missing_facet_coverage_stays_named_gap(): void
    {
        $composer = new RetrievalAgendaComposer;

        $agenda = $composer->compose(
            'How does TaskFacetExtractor extract symbols?',
            ['facet_coverage' => [
                ['type' => 'symbol', 'value' => 'TaskFacetExtractor', 'essential' => true, 'refs_delivered' => 0],
            ]],
        );

        $this->assertTrue($agenda['not_enough_context']);
        $this->assertSame('mechanism', $agenda['named_unknown_gaps'][0]['taxonomy']);
        $this->assertSame('TaskFacetExtractor extract symbols', $agenda['named_unknown_gaps'][0]['name']);
    }
}
