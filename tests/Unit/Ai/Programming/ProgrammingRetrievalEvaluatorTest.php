<?php

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingRetrievalEvaluator;
use Tests\TestCase;

class ProgrammingRetrievalEvaluatorTest extends TestCase
{
    private ProgrammingRetrievalEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(ProgrammingRetrievalEvaluator::class);
    }

    public function test_evaluate_returns_retrieval_eval_contract_with_passed_gap_critic(): void
    {
        $contextPackHash = str_repeat('a', 64);

        $result = $this->evaluator->evaluate(
            requiredSources: ['code_symbols', 'canonical_docs'],
            contextPack: [
                'ranked_refs' => [
                    ['source' => 'code_symbols', 'score' => 0.82],
                    ['source' => 'canonical_docs', 'score' => 0.71],
                    ['source' => 'related_tests', 'score' => 0.2],
                ],
                'source_counts' => [
                    'code_symbols' => 1,
                    'canonical_docs' => 1,
                    'related_tests' => 1,
                ],
                'context_pack_hash' => $contextPackHash,
                'provider_safe' => true,
            ],
            gapCritic: ['status' => 'passed'],
        );

        $this->assertSame('atlas.programming.retrieval_eval.v1', $result['schema_version']);
        $this->assertSame('online_proxy', $result['evaluation_mode']);
        $this->assertSame('passed', $result['status']);
        $this->assertSame(1.0, $result['recall_at_k_proxy']);
        $this->assertSame(0.3333, $result['context_waste_ratio']);
        $this->assertSame(0.6667, $result['precision_at_k_proxy']);
        $this->assertFalse($result['professional_promotion_allowed']);
        $this->assertSame('golden_set_retrieval_benchmark_not_attached', $result['promotion_blocker']);
        $this->assertSame($contextPackHash, $result['replayability']['context_pack_hash']);
        $this->assertSame(3, $result['replayability']['ranked_ref_count']);
        $this->assertTrue($result['replayability']['provider_safe']);
    }

    public function test_evaluate_maps_degraded_gap_critic_to_degraded_retrieval_status(): void
    {
        $result = $this->evaluator->evaluate(
            requiredSources: ['code_symbols'],
            contextPack: [
                'ranked_refs' => [
                    ['source' => 'code_symbols', 'score' => 0.9],
                ],
                'source_counts' => ['code_symbols' => 1],
            ],
            gapCritic: ['status' => 'degraded'],
        );

        $this->assertSame('degraded', $result['status']);
    }

    public function test_evaluate_maps_blocked_gap_critic_to_needs_review_retrieval_status(): void
    {
        $result = $this->evaluator->evaluate(
            requiredSources: ['stage_receipts', 'known_failures'],
            contextPack: [
                'ranked_refs' => [],
                'source_counts' => [],
            ],
            gapCritic: ['status' => 'blocked'],
        );

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame(0.0, $result['recall_at_k_proxy']);
        $this->assertSame(0.0, $result['context_waste_ratio']);
        $this->assertSame(1.0, $result['precision_at_k_proxy']);
    }

    public function test_evaluate_uses_one_point_zero_recall_when_no_required_sources(): void
    {
        $result = $this->evaluator->evaluate(
            requiredSources: [],
            contextPack: [
                'ranked_refs' => [
                    ['source' => 'code_symbols', 'score' => 0.1],
                ],
            ],
            gapCritic: ['status' => 'passed'],
        );

        $this->assertSame(1.0, $result['recall_at_k_proxy']);
        $this->assertSame(1.0, $result['context_waste_ratio']);
        $this->assertSame(0.0, $result['precision_at_k_proxy']);
    }

    public function test_evaluate_deduplicates_required_sources_for_recall_proxy(): void
    {
        $result = $this->evaluator->evaluate(
            requiredSources: ['code_symbols', 'code_symbols', 'canonical_docs'],
            contextPack: [
                'ranked_refs' => [
                    ['source' => 'code_symbols', 'score' => 0.9],
                ],
            ],
            gapCritic: ['status' => 'passed'],
        );

        $this->assertSame(0.5, $result['recall_at_k_proxy']);
    }
}
