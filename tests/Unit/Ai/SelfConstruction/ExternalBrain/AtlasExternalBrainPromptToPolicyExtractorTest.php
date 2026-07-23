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
            'text'                        => $text,
            'occurrence_count'            => 3,
            'is_emotional'                => false,
            'is_raw_provider_text'        => false,
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

        foreach (['owner_dimension', 'evidence_requirement', 'suggested_enforcement_point',
                  'category', 'trigger', 'enforcement_check', 'minimum_occurrences', 'rejection_reason'] as $k) {
            $this->assertArrayHasKey($k, $candidate);
        }
        $this->assertSame(5, $candidate['occurrence_count']);
    }

    // ── AC1: new policy fields ────────────────────────────────────────────────

    public function test_candidate_has_category_field(): void
    {
        $result = $this->extract($this->obs('no proxy tasks'));

        $this->assertArrayHasKey('category', $result['policy_candidates'][0]);
        $this->assertNotEmpty($result['policy_candidates'][0]['category']);
    }

    public function test_candidate_has_trigger_field(): void
    {
        $result = $this->extract($this->obs('no proxy tasks'));

        $this->assertArrayHasKey('trigger', $result['policy_candidates'][0]);
        $this->assertNotEmpty($result['policy_candidates'][0]['trigger']);
    }

    public function test_candidate_has_enforcement_check_field(): void
    {
        $result = $this->extract($this->obs('no proxy tasks'));

        $this->assertArrayHasKey('enforcement_check', $result['policy_candidates'][0]);
        $this->assertNotEmpty($result['policy_candidates'][0]['enforcement_check']);
    }

    public function test_candidate_has_minimum_occurrences_equal_to_two(): void
    {
        $result = $this->extract($this->obs('no proxy tasks'));

        $this->assertSame(2, $result['policy_candidates'][0]['minimum_occurrences']);
    }

    public function test_accepted_candidate_rejection_reason_is_null(): void
    {
        $result = $this->extract($this->obs('no proxy tasks'));

        $this->assertNull($result['policy_candidates'][0]['rejection_reason']);
    }

    // ── AC1: category classification by keyword ───────────────────────────────

    // ── AC1: wait/queue-depth-as-completion → continuity_or_origination_discipline ──

    public function test_wait_because_queue_depth_sufficient_maps_to_continuity_discipline(): void
    {
        $result = $this->extract($this->obs('we are waiting because queue depth is sufficient', ['occurrence_count' => 4]));

        $candidate = $result['policy_candidates'][0];
        $this->assertSame('continuity_or_origination_discipline', $candidate['category']);
        $this->assertSame('wait_as_progress', $candidate['blocked_behavior']);
    }

    // ── AC2: template farming / proxy progress → enforceable candidate ────────

    public function test_template_farming_observation_has_non_empty_trigger_and_enforcement_check(): void
    {
        $result = $this->extract($this->obs('this looks like template farming', ['occurrence_count' => 3]));

        $candidate = $result['policy_candidates'][0];
        $this->assertNotEmpty($candidate['trigger']);
        $this->assertNotEmpty($candidate['enforcement_check']);
        $this->assertSame('template_farming', $candidate['blocked_behavior']);
    }

    public function test_proxy_progress_observation_has_non_empty_trigger_and_enforcement_check(): void
    {
        $result = $this->extract($this->obs('reports proxy progress without real delivery', ['occurrence_count' => 3]));

        $candidate = $result['policy_candidates'][0];
        $this->assertNotEmpty($candidate['trigger']);
        $this->assertNotEmpty($candidate['enforcement_check']);
    }

    public function test_proxy_keyword_maps_to_proxy_anti_pattern_category(): void
    {
        $result = $this->extract($this->obs('avoid proxy tasks'));

        $this->assertSame('proxy_anti_pattern', $result['policy_candidates'][0]['category']);
    }

    public function test_evidence_keyword_maps_to_evidence_discipline_category(): void
    {
        $result = $this->extract($this->obs('require evidence before accepting'));

        $this->assertSame('evidence_discipline', $result['policy_candidates'][0]['category']);
    }

    public function test_human_keyword_maps_to_autonomy_constraint_category(): void
    {
        $result = $this->extract($this->obs('no human approval in steady state'));

        $this->assertSame('autonomy_constraint', $result['policy_candidates'][0]['category']);
    }

    public function test_macro_keyword_maps_to_task_shape_discipline_category(): void
    {
        $result = $this->extract($this->obs('tasks must have macro scope'));

        $this->assertSame('task_shape_discipline', $result['policy_candidates'][0]['category']);
    }

    public function test_memory_keyword_maps_to_memory_discipline_category(): void
    {
        $result = $this->extract($this->obs('do not use memory outside atlas native store'));

        $this->assertSame('memory_discipline', $result['policy_candidates'][0]['category']);
    }

    public function test_unmatched_text_falls_back_to_general_policy_rule_category(): void
    {
        $result = $this->extract($this->obs('always run in a clean workspace'));

        $this->assertSame('general_policy_rule', $result['policy_candidates'][0]['category']);
    }

    // ── AC1: trigger values ───────────────────────────────────────────────────

    public function test_proxy_keyword_has_at_task_origination_trigger(): void
    {
        $result = $this->extract($this->obs('avoid proxy tasks'));

        $this->assertSame('at_task_origination', $result['policy_candidates'][0]['trigger']);
    }

    public function test_evidence_keyword_has_at_evidence_validation_trigger(): void
    {
        $result = $this->extract($this->obs('require evidence before accepting'));

        $this->assertSame('at_evidence_validation', $result['policy_candidates'][0]['trigger']);
    }

    // ── AC1: owner_dimension classification (existing) ────────────────────────

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
            $this->obs('no proxy tasks', ['occurrence_count' => 4]),
            $this->obs('ugh stop it', ['is_emotional' => true]),
            $this->obs('no human approval', ['occurrence_count' => 3]),
        );

        $this->assertCount(2, $result['policy_candidates']);
        $this->assertCount(1, $result['rejected_observations']);
    }

    // ── padding_as_progress classification ──

    public function test_padding_keyword_maps_to_continuity_discipline(): void
    {
        $result = $this->extract($this->obs('do not use padding to fill quota'));

        $candidate = $result['policy_candidates'][0];
        $this->assertSame('continuity_or_origination_discipline', $candidate['category']);
        $this->assertSame('padding_as_progress', $candidate['blocked_behavior']);
    }
}
