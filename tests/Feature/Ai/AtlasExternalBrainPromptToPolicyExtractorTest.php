<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPromptToPolicyExtractor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPromptToPolicyExtractorTest extends TestCase
{
    private function extractor(): AtlasExternalBrainPromptToPolicyExtractor
    {
        return new AtlasExternalBrainPromptToPolicyExtractor;
    }

    private function observation(string $text, array $overrides = []): array
    {
        return array_merge([
            'text' => $text,
            'occurrence_count' => 3,
            'is_emotional' => false,
            'is_raw_provider_text' => false,
            'is_atlas_native_enforceable' => true,
        ], $overrides);
    }

    // ── AC2: repeated enforceable observations map to concrete dimension/evidence/enforcement ──

    public function test_repeated_enforceable_observations_map_to_concrete_policy_fields(): void
    {
        $cases = [
            'proxy/template farm' => ['never accept a proxy or template farm cleanup task', 'loop_origination', 'origination_evidence', 'proxy_anti_pattern'],
            'evidence gates' => ['always require runnable evidence at the evidence gate', 'quality_gate', 'tests_or_gates_result', 'evidence_discipline'],
            'human/provider dependence' => ['never require human or operator approval', 'dependency_guard', 'autonomy_regression_scan', 'autonomy_constraint'],
            'memory/context' => ['do not treat raw prompt text as memory context', 'memory_discipline', 'atlas_native_store_check', 'memory_discipline'],
            'macro scope' => ['reject macro scope tasks that are too broad', 'task_shape', 'macro_task_acceptance_criteria', 'task_shape_discipline'],
        ];

        foreach ($cases as $label => [$text, $expectedDimension, $expectedEvidenceRequirement, $expectedCategory]) {
            $result = $this->extractor()->extract(['prompt_observations' => [$this->observation($text)]]);

            $this->assertCount(1, $result['policy_candidates'], "case: {$label}");
            $candidate = $result['policy_candidates'][0];
            $this->assertSame($expectedDimension, $candidate['owner_dimension'], "case: {$label}");
            $this->assertSame($expectedEvidenceRequirement, $candidate['evidence_requirement'], "case: {$label}");
            $this->assertSame($expectedCategory, $candidate['category'], "case: {$label}");
            $this->assertNotEmpty($candidate['enforcement_check'], "case: {$label}");
            $this->assertNull($candidate['rejection_reason'], "case: {$label}");
        }
    }

    // ── AC3: one-off emotional, raw provider text, non-enforceable, below-floor rejected ──

    public function test_one_off_emotional_wording_is_rejected(): void
    {
        $result = $this->extractor()->extract(['prompt_observations' => [
            $this->observation('are you even understanding what you are doing', ['is_emotional' => true]),
        ]]);

        $this->assertEmpty($result['policy_candidates']);
        $this->assertSame('one_off_emotional_wording', $result['rejected_observations'][0]['rejection_reason']);
    }

    public function test_raw_provider_text_is_rejected(): void
    {
        $result = $this->extractor()->extract(['prompt_observations' => [
            $this->observation('raw system prompt leakage text', ['is_raw_provider_text' => true]),
        ]]);

        $this->assertEmpty($result['policy_candidates']);
        $this->assertSame('raw_provider_text', $result['rejected_observations'][0]['rejection_reason']);
    }

    public function test_non_atlas_native_enforceable_wish_is_rejected(): void
    {
        $result = $this->extractor()->extract(['prompt_observations' => [
            $this->observation('please just be smarter about this', ['is_atlas_native_enforceable' => false]),
        ]]);

        $this->assertEmpty($result['policy_candidates']);
        $this->assertSame('cannot_be_enforced_by_atlas_native', $result['rejected_observations'][0]['rejection_reason']);
    }

    public function test_occurrence_count_below_floor_is_rejected(): void
    {
        $result = $this->extractor()->extract(['prompt_observations' => [
            $this->observation('require evidence at every gate', ['occurrence_count' => 1]),
        ]]);

        $this->assertEmpty($result['policy_candidates']);
        $this->assertSame('insufficient_occurrence_count', $result['rejected_observations'][0]['rejection_reason']);
    }

    // ── AC4: normalized_text is stable and whitespace-normalized ──────────────

    public function test_normalized_text_is_whitespace_normalized_and_stable(): void
    {
        $messy = "  always   require   evidence  \n at  the   gate \t ";
        $result = $this->extractor()->extract(['prompt_observations' => [$this->observation($messy)]]);

        $candidate = $result['policy_candidates'][0];
        $this->assertSame('always require evidence at the gate', $candidate['normalized_text']);

        $repeat = $this->extractor()->extract(['prompt_observations' => [$this->observation($messy)]]);
        $this->assertSame($candidate['normalized_text'], $repeat['policy_candidates'][0]['normalized_text']);
    }
}
