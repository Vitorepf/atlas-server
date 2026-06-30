<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainReasoningScaffoldCompiler;
use Tests\TestCase;

final class AtlasExternalBrainReasoningScaffoldCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainReasoningScaffoldCompiler
    {
        return new AtlasExternalBrainReasoningScaffoldCompiler();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasExternalBrainReasoningScaffoldCompiler::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler()->compile([]);

        foreach (['schema', 'is_valid', 'rejection_reason', 'scaffold_sections',
                  'required_artifacts', 'stop_conditions', 'frontier_deepening_prompts'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── valid scaffold ────────────────────────────────────────────────────────

    public function test_valid_when_no_skip_and_no_direct_final(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertTrue($result['is_valid']);
        $this->assertNull($result['rejection_reason']);
    }

    public function test_all_seven_sections_emitted_in_order(): void
    {
        $result = $this->compiler()->compile([]);

        $expectedOrder = [
            'queue_state_read',
            'evidence_intake', 'explored_surfaces', 'candidate_tasks',
            'anti_duplication_proof', 'semantic_dedup',
            'adversarial_critique', 'implementability_check',
            'leverage_ranking', 'impact_ranking',
            'final_batch_selection',
        ];

        $actualIds = array_column($result['scaffold_sections'], 'section_id');
        $this->assertSame($expectedOrder, $actualIds);
    }

    public function test_section_order_field_matches_position(): void
    {
        $result = $this->compiler()->compile([]);

        foreach ($result['scaffold_sections'] as $i => $section) {
            $this->assertSame($i + 1, $section['order']);
        }
    }

    public function test_each_section_has_required_fields(): void
    {
        $result = $this->compiler()->compile([]);

        foreach ($result['scaffold_sections'] as $section) {
            $this->assertArrayHasKey('section_id', $section);
            $this->assertArrayHasKey('order', $section);
            $this->assertArrayHasKey('required', $section);
            $this->assertArrayHasKey('prompt_template', $section);
            $this->assertNotEmpty($section['prompt_template']);
        }
    }

    // ── required_artifacts ────────────────────────────────────────────────────

    public function test_required_artifacts_present_for_all_sections(): void
    {
        $result = $this->compiler()->compile([]);

        $expectedArtifacts = [
            'evidence_intake'        => ['evidence_list'],
            'explored_surfaces'      => ['surface_map'],
            'candidate_tasks'        => ['candidate_list'],
            'anti_duplication_proof' => ['dedup_proof'],
            'adversarial_critique'   => ['critique_report'],
            'leverage_ranking'       => ['ranked_candidates'],
            'final_batch_selection'  => ['final_batch'],
        ];

        foreach ($expectedArtifacts as $section => $artifacts) {
            $this->assertSame($artifacts, $result['required_artifacts'][$section]);
        }
    }

    // ── stop_conditions ───────────────────────────────────────────────────────

    public function test_evidence_intake_has_zero_evidence_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertContains('zero_evidence_available', $result['stop_conditions']['evidence_intake']);
    }

    public function test_anti_duplication_proof_has_all_duplicates_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertContains('all_candidates_are_duplicates', $result['stop_conditions']['anti_duplication_proof']);
    }

    public function test_adversarial_critique_has_all_critiqued_out_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertContains('all_candidates_critiqued_out', $result['stop_conditions']['adversarial_critique']);
    }

    // ── frontier_deepening_prompts ────────────────────────────────────────────

    public function test_frontier_deepening_prompts_is_non_empty_list(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertNotEmpty($result['frontier_deepening_prompts']);
        foreach ($result['frontier_deepening_prompts'] as $prompt) {
            $this->assertIsString($prompt);
            $this->assertNotEmpty($prompt);
        }
    }

    // ── validation: skip evidence_intake ──────────────────────────────────────

    public function test_reject_when_evidence_intake_skipped(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['evidence_intake']]);

        $this->assertFalse($result['is_valid']);
        $this->assertSame('must_include_evidence_intake', $result['rejection_reason']);
    }

    public function test_rejected_scaffold_has_empty_sections(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['evidence_intake']]);

        $this->assertSame([], $result['scaffold_sections']);
    }

    // ── validation: allow_direct_final_answer ─────────────────────────────────

    public function test_reject_when_allow_direct_final_answer_true(): void
    {
        $result = $this->compiler()->compile(['allow_direct_final_answer' => true]);

        $this->assertFalse($result['is_valid']);
        $this->assertSame('direct_final_answer_not_allowed', $result['rejection_reason']);
    }

    public function test_direct_final_rejected_with_empty_artifacts(): void
    {
        $result = $this->compiler()->compile(['allow_direct_final_answer' => true]);

        $this->assertSame([], $result['required_artifacts']);
    }

    // ── critique before final ─────────────────────────────────────────────────

    public function test_adversarial_critique_comes_before_final_batch_selection(): void
    {
        $result = $this->compiler()->compile([]);

        $ids = array_column($result['scaffold_sections'], 'section_id');
        $critiquePos = array_search('adversarial_critique', $ids, true);
        $finalPos    = array_search('final_batch_selection', $ids, true);

        $this->assertLessThan($finalPos, $critiquePos);
    }

    // ── AC1: new required guardrail sections ─────────────────────────────────

    public function test_queue_state_read_is_first_section(): void
    {
        $result = $this->compiler()->compile([]);
        $this->assertSame('queue_state_read', $result['scaffold_sections'][0]['section_id']);
    }

    public function test_queue_state_read_has_required_artifact(): void
    {
        $result = $this->compiler()->compile([]);
        $this->assertSame(['queue_snapshot'], $result['required_artifacts']['queue_state_read']);
    }

    public function test_semantic_dedup_comes_after_anti_duplication_proof(): void
    {
        $result = $this->compiler()->compile([]);
        $ids = array_column($result['scaffold_sections'], 'section_id');
        $exactDedup  = array_search('anti_duplication_proof', $ids, true);
        $semanticDup = array_search('semantic_dedup', $ids, true);
        $this->assertLessThan($semanticDup, $exactDedup);
    }

    public function test_semantic_dedup_has_required_artifact(): void
    {
        $result = $this->compiler()->compile([]);
        $this->assertSame(['semantic_dedup_report'], $result['required_artifacts']['semantic_dedup']);
    }

    public function test_implementability_check_comes_after_adversarial_critique(): void
    {
        $result = $this->compiler()->compile([]);
        $ids = array_column($result['scaffold_sections'], 'section_id');
        $critiquePos = array_search('adversarial_critique', $ids, true);
        $implPos     = array_search('implementability_check', $ids, true);
        $this->assertLessThan($implPos, $critiquePos);
    }

    public function test_implementability_check_has_required_artifact(): void
    {
        $result = $this->compiler()->compile([]);
        $this->assertSame(['implementability_report'], $result['required_artifacts']['implementability_check']);
    }

    public function test_impact_ranking_comes_before_final_batch_selection(): void
    {
        $result = $this->compiler()->compile([]);
        $ids = array_column($result['scaffold_sections'], 'section_id');
        $impactPos = array_search('impact_ranking', $ids, true);
        $finalPos  = array_search('final_batch_selection', $ids, true);
        $this->assertLessThan($finalPos, $impactPos);
    }

    public function test_impact_ranking_has_required_artifact(): void
    {
        $result = $this->compiler()->compile([]);
        $this->assertSame(['impact_ranked_list'], $result['required_artifacts']['impact_ranking']);
    }

    // ── AC2: fail closed when required section skipped ────────────────────────

    public function test_reject_when_queue_state_read_skipped(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['queue_state_read']]);
        $this->assertFalse($result['is_valid']);
        $this->assertStringStartsWith('must_not_skip_required_section', $result['rejection_reason']);
    }

    public function test_reject_when_semantic_dedup_skipped(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['semantic_dedup']]);
        $this->assertFalse($result['is_valid']);
        $this->assertSame('must_not_skip_required_section:semantic_dedup', $result['rejection_reason']);
    }

    public function test_reject_when_implementability_check_skipped(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['implementability_check']]);
        $this->assertFalse($result['is_valid']);
        $this->assertSame('must_not_skip_required_section:implementability_check', $result['rejection_reason']);
    }

    public function test_reject_when_impact_ranking_skipped(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['impact_ranking']]);
        $this->assertFalse($result['is_valid']);
        $this->assertSame('must_not_skip_required_section:impact_ranking', $result['rejection_reason']);
    }

    public function test_rejected_required_section_has_empty_scaffold(): void
    {
        $result = $this->compiler()->compile(['skip_sections' => ['semantic_dedup']]);
        $this->assertSame([], $result['scaffold_sections']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['skip_sections' => []];

        $this->assertSame($this->compiler()->compile($input), $this->compiler()->compile($input));
    }
}
