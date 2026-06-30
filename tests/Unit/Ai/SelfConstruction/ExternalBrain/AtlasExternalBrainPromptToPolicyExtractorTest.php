<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPromptToPolicyExtractor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPromptToPolicyExtractorTest extends TestCase
{
    private AtlasExternalBrainPromptToPolicyExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new AtlasExternalBrainPromptToPolicyExtractor;
    }

    private function obs(string $text, array $overrides = []): array
    {
        return array_merge([
            'text'                      => $text,
            'occurrence_count'          => 3,
            'is_emotional'              => false,
            'is_raw_provider_text'      => false,
            'is_atlas_native_enforceable' => true,
        ], $overrides);
    }

    private function extract(array ...$observations): array
    {
        return $this->extractor->extract(['prompt_observations' => $observations]);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->extract($this->obs('no proxy tasks'));

        foreach (['schema', 'policy_candidates', 'rejected_observations'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainPromptToPolicyExtractor::SCHEMA, $result['schema']);
    }

    // ── AC1: recurring observations → policy candidates ───────────────────────

    public function test_repeated_valid_observation_becomes_policy_candidate(): void
    {
        $result = $this->extract($this->obs('do not do proxy work', ['occurrence_count' => 5]));

        $this->assertCount(1, $result['policy_candidates']);
        $candidate = $result['policy_candidates'][0];

        $this->assertArrayHasKey('owner_dimension', $candidate);
        $this->assertArrayHasKey('evidence_requirement', $candidate);
        $this->assertArrayHasKey('suggested_enforcement_point', $candidate);
        $this->assertSame(5, $candidate['occurrence_count']);
    }

    // ── AC1: policy candidate dimensions by keyword ───────────────────────────

    public function test_proxy_keyword_maps_to_loop_origination(): void
    {
        $result = $this->extract($this->obs('avoid proxy tasks'));

        $this->assertSame('loop_origination', $result['policy_candidates'][0]['owner_dimension']);
    }

    public function test_evidence_keyword_maps_to_quality_gate(): void
    {
        $result = $this->extract($this->obs('require evidence before accepting'));

        $this->assertSame('quality_gate', $result['policy_candidates'][0]['owner_dimension']);
    }

    public function test_human_keyword_maps_to_dependency_guard(): void
    {
        $result = $this->extract($this->obs('no human approval in steady state'));

        $this->assertSame('dependency_guard', $result['policy_candidates'][0]['owner_dimension']);
    }

    public function test_memory_keyword_maps_to_memory_discipline(): void
    {
        $result = $this->extract($this->obs('do not use memory outside atlas native store'));

        $this->assertSame('memory_discipline', $result['policy_candidates'][0]['owner_dimension']);
    }

    public function test_macro_keyword_maps_to_task_shape(): void
    {
        $result = $this->extract($this->obs('tasks must have macro scope'));

        $this->assertSame('task_shape', $result['policy_candidates'][0]['owner_dimension']);
    }

    public function test_unmatched_text_falls_back_to_general_policy(): void
    {
        $result = $this->extract($this->obs('always run in a clean workspace'));

        $this->assertSame('general_policy', $result['policy_candidates'][0]['owner_dimension']);
    }

    // ── AC2: rejection — emotional wording ───────────────────────────────────

    public function test_emotional_observation_is_rejected(): void
    {
        $result = $this->extract($this->obs('WHY ARE YOU DOING THIS!!!', ['is_emotional' => true]));

        $this->assertCount(0, $result['policy_candidates']);
        $this->assertCount(1, $result['rejected_observations']);
        $this->assertSame('one_off_emotional_wording', $result['rejected_observations'][0]['rejection_reason']);
    }

    // ── AC2: rejection — raw provider text ───────────────────────────────────

    public function test_raw_provider_text_is_rejected(): void
    {
        $result = $this->extract($this->obs('As an AI language model...', ['is_raw_provider_text' => true]));

        $this->assertCount(0, $result['policy_candidates']);
        $this->assertSame('raw_provider_text', $result['rejected_observations'][0]['rejection_reason']);
    }

    // ── AC2: rejection — not atlas-native enforceable ─────────────────────────

    public function test_non_enforceable_candidate_is_rejected(): void
    {
        $result = $this->extract($this->obs('always check vibes', ['is_atlas_native_enforceable' => false]));

        $this->assertCount(0, $result['policy_candidates']);
        $this->assertSame('cannot_be_enforced_by_atlas_native', $result['rejected_observations'][0]['rejection_reason']);
    }

    // ── Rejection — insufficient occurrences ──────────────────────────────────

    public function test_single_occurrence_is_rejected(): void
    {
        $result = $this->extract($this->obs('no proxy', ['occurrence_count' => 1]));

        $this->assertCount(0, $result['policy_candidates']);
        $this->assertSame('insufficient_occurrence_count', $result['rejected_observations'][0]['rejection_reason']);
    }

    public function test_exactly_two_occurrences_is_accepted(): void
    {
        $result = $this->extract($this->obs('no proxy', ['occurrence_count' => 2]));

        $this->assertCount(1, $result['policy_candidates']);
    }

    // ── Rejection priority (emotional beats insufficient count) ───────────────

    public function test_emotional_rejection_takes_priority_over_count(): void
    {
        $result = $this->extract($this->obs('NOOOO', [
            'is_emotional'     => true,
            'occurrence_count' => 0,
        ]));

        $this->assertSame('one_off_emotional_wording', $result['rejected_observations'][0]['rejection_reason']);
    }

    // ── Mixed input ───────────────────────────────────────────────────────────

    public function test_mixed_input_separates_accepted_and_rejected(): void
    {
        $result = $this->extract(
            $this->obs('no proxy tasks', ['occurrence_count' => 4]),           // accepted
            $this->obs('ugh stop it', ['is_emotional' => true]),               // rejected
            $this->obs('no human approval', ['occurrence_count' => 3]),        // accepted
        );

        $this->assertCount(2, $result['policy_candidates']);
        $this->assertCount(1, $result['rejected_observations']);
    }
}
