<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Context\EpistemicEvidenceBundleComposer;
use Tests\TestCase;

/**
 * ESP-12 — five-layer epistemic evidence bundle.
 */
final class Esp12EpistemicEvidenceBundleComposerTest extends TestCase
{
    public function test_composes_five_layers_with_policy_separate_and_content_versioned_citations(): void
    {
        $bundle = (new EpistemicEvidenceBundleComposer)->compose([
            'retrieval_agenda' => [
                'claims' => [
                    ['claim' => 'The policy must enforce scoped commits only'],
                ],
                'counter_evidence_slots' => [
                    [
                        'claim' => 'The policy must enforce scoped commits only',
                        'source' => 'memory',
                        'query' => 'counter evidence against claim: The policy must enforce scoped commits only',
                        'refs_against' => [],
                        'refs_found' => 0,
                        'status' => 'empty_honest',
                    ],
                ],
            ],
            'span_level_retrieval' => [
                'claims' => [
                    [
                        'claim' => 'The policy must enforce scoped commits only',
                        'claim_hash' => hash('sha256', 'The policy must enforce scoped commits only'),
                        'status' => 'resolved',
                        'spans' => [
                            [
                                'span_ref' => 'memory:11111111111111111111111111111111:span:2222222222222222:v:333333333333',
                                'parent_ref' => 'memory:11111111111111111111111111111111',
                                'source_type' => 'memory',
                                'content_version' => hash('sha256', 'provider-safe content v1'),
                                'start' => 10,
                                'end' => 58,
                                'span_excerpt' => 'The policy must enforce scoped commits only.',
                                'score' => 0.91,
                            ],
                        ],
                    ],
                ],
            ],
            'context_delivery_policy' => [
                'session_working_set' => [
                    'lineage' => ['obra_id' => 'obra-17', 'decision_id' => 'D-1'],
                ],
            ],
            'obra_working_set' => [
                'items' => [
                    ['ref' => 'memory:99999999999999999999999999999999'],
                ],
            ],
            'memory' => [
                ['content_hash' => 'provider-safe content v1', 'type' => 'decision', 'title' => 'Policy note'],
            ],
        ]);

        $this->assertTrue($bundle['present']);
        $this->assertSame('atlas.aobg.epistemic_evidence_bundle.v1', $bundle['schema_version']);
        $this->assertSame(
            ['must_carry', 'novelty_pool', 'operator_policy', 'counter_evidence', 'claim_citations'],
            array_keys($bundle['layers']),
        );
        $this->assertLessThanOrEqual($bundle['source']['max_must_carry_refs'], count($bundle['must_carry']['refs']));
        $this->assertSame(['memory:11111111111111111111111111111111:span:2222222222222222:v:333333333333'], $bundle['must_carry']['refs']);
        $this->assertSame('obra-17', $bundle['novelty_pool']['lineage']['obra_id']);
        $this->assertSame('report_only', $bundle['operator_policy']['mode']);
        $this->assertFalse($bundle['operator_policy']['evidence_layer']);
        $this->assertSame('CONTRAEVIDÊNCIA', $bundle['counter_evidence']['label']);
        $this->assertSame('empty_honest', $bundle['counter_evidence']['slots'][0]['status']);
        $this->assertSame(
            'memory:11111111111111111111111111111111:span:2222222222222222:v:333333333333',
            $bundle['claim_citations']['items'][0]['citations'][0]['span_ref'],
        );
        $this->assertSame(hash('sha256', 'provider-safe content v1'), $bundle['claim_citations']['items'][0]['citations'][0]['content_version']);
    }

    public function test_must_carry_has_hard_cap_and_bundle_degrades_honestly_without_spans(): void
    {
        $claims = [];
        for ($i = 0; $i < 6; $i++) {
            $claims[] = [
                'claim' => 'claim '.$i,
                'claim_hash' => hash('sha256', 'claim '.$i),
                'status' => 'resolved',
                'spans' => [[
                    'span_ref' => 'memory:'.str_repeat((string) $i, 32).':span:'.str_repeat((string) $i, 16).':v:'.str_repeat((string) $i, 12),
                    'parent_ref' => 'memory:'.str_repeat((string) $i, 32),
                    'source_type' => 'memory',
                    'content_version' => 'version-'.$i,
                    'start' => 0,
                    'end' => 8,
                    'span_excerpt' => 'claim '.$i,
                    'score' => 1.0,
                ]],
            ];
        }

        $capped = (new EpistemicEvidenceBundleComposer)->compose([
            'retrieval_agenda' => ['claims' => [['claim' => 'claim 0']]],
            'span_level_retrieval' => ['claims' => $claims],
        ]);
        $withoutSpans = (new EpistemicEvidenceBundleComposer)->compose([
            'retrieval_agenda' => [
                'claims' => [['claim' => 'The policy must enforce scoped commits only']],
                'counter_evidence_slots' => [],
            ],
        ]);

        $this->assertCount(EpistemicEvidenceBundleComposer::MAX_MUST_CARRY_REFS, $capped['must_carry']['refs']);
        $this->assertTrue($withoutSpans['present']);
        $this->assertSame('no_claim_spans', $withoutSpans['must_carry']['status']);
        $this->assertSame('span_level_retrieval_absent', $withoutSpans['claim_citations']['status']);
        $this->assertSame([], $withoutSpans['claim_citations']['items']);
    }
}
