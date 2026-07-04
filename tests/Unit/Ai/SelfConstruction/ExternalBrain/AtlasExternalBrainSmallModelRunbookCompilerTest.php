<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSmallModelRunbookCompiler;
use Tests\TestCase;

final class AtlasExternalBrainSmallModelRunbookCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainSmallModelRunbookCompiler
    {
        return new AtlasExternalBrainSmallModelRunbookCompiler();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasExternalBrainSmallModelRunbookCompiler::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler()->compile([]);

        foreach ([
            'schema', 'mission', 'scope', 'risk_level', 'model_weaknesses', 'runbook_steps',
            'mandatory_artifacts', 'stop_conditions', 'escalate_mandatory', 'frontier_optional_deepening_steps',
        ] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── mission and scope ─────────────────────────────────────────────────────

    public function test_mission_and_scope_passed_through(): void
    {
        $result = $this->compiler()->compile([
            'mission' => 'Evolve the external brain',
            'scope' => 'atlas-server',
        ]);

        $this->assertSame('Evolve the external brain', $result['mission']);
        $this->assertSame('atlas-server', $result['scope']);
    }

    public function test_mission_defaults_to_empty_string(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame('', $result['mission']);
    }

    // ── runbook_steps ─────────────────────────────────────────────────────────

    public function test_eleven_steps_emitted(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertCount(11, $result['runbook_steps']);
    }

    public function test_steps_ordered_sequentially(): void
    {
        $result = $this->compiler()->compile([]);

        $orders = array_column($result['runbook_steps'], 'order');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], $orders);
    }

    public function test_steps_have_required_fields(): void
    {
        $result = $this->compiler()->compile([]);

        foreach ($result['runbook_steps'] as $step) {
            foreach (['step_id', 'order', 'instruction', 'mandatory_artifact', 'stop_if_missing'] as $f) {
                $this->assertArrayHasKey($f, $step);
            }
            $this->assertNotEmpty($step['instruction']);
        }
    }

    public function test_canonical_step_ids_present_in_order(): void
    {
        $result = $this->compiler()->compile([]);
        $stepIds = array_column($result['runbook_steps'], 'step_id');

        $this->assertSame([
            'read_state',
            'dedup',
            'propose',
            'design_path_selection',
            'critique',
            'anti_proxy_repair',
            'evidence_replay',
            'escalate',
            'research_deepening',
            'final_enqueue_readiness',
            'final_batch_self_audit',
        ], $stepIds);
    }

    public function test_required_canonical_step_names_are_all_present(): void
    {
        $result = $this->compiler()->compile([]);
        $stepIds = array_column($result['runbook_steps'], 'step_id');

        foreach ([
            'read_state', 'dedup', 'propose', 'design_path_selection', 'critique',
            'anti_proxy_repair', 'evidence_replay', 'escalate', 'research_deepening',
            'final_batch_self_audit',
        ] as $required) {
            $this->assertContains($required, $stepIds);
        }
    }

    // ── mandatory_artifacts ───────────────────────────────────────────────────

    public function test_mandatory_artifacts_lists_all_expected_artifact_names(): void
    {
        $result = $this->compiler()->compile([]);

        $expected = [
            'evidence_list', 'dedup_proof', 'candidate_list', 'design_path_selection_log',
            'critique_report', 'repair_log', 'grep_evidence', 'escalation_decision',
            'exhaustion_evidence', 'final_batch', 'final_batch_self_audit_log',
        ];
        $this->assertSame($expected, $result['mandatory_artifacts']);
    }

    // ── stop_conditions ───────────────────────────────────────────────────────

    public function test_read_state_has_no_evidence_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('read_state', $result['stop_conditions']);
        $this->assertContains('no_evidence_available', $result['stop_conditions']['read_state']);
    }

    public function test_dedup_has_all_duplicates_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('dedup', $result['stop_conditions']);
        $this->assertContains('all_candidates_duplicate_existing_work', $result['stop_conditions']['dedup']);
    }

    public function test_evidence_replay_has_no_files_match_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('evidence_replay', $result['stop_conditions']);
        $this->assertContains('no_files_match_grep_pattern', $result['stop_conditions']['evidence_replay']);
    }

    public function test_design_path_selection_distinguishes_lazy_no_more_ideas_from_honest_no_evidence(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('read_state', $result['stop_conditions']);
        $this->assertContains('no_evidence_available', $result['stop_conditions']['read_state']);

        $this->assertArrayHasKey('design_path_selection', $result['stop_conditions']);
        $this->assertContains('lazy_no_more_ideas_requires_another_search_or_design_path_pass', $result['stop_conditions']['design_path_selection']);
    }

    public function test_final_enqueue_readiness_has_anti_padding_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('final_enqueue_readiness', $result['stop_conditions']);
        $this->assertContains('no_candidates_passed_gates_do_not_pad_with_weak_work', $result['stop_conditions']['final_enqueue_readiness']);
    }

    public function test_steps_without_stop_conditions_are_not_in_stop_conditions_map(): void
    {
        $result = $this->compiler()->compile([]);

        foreach (['critique', 'anti_proxy_repair', 'escalate'] as $stepId) {
            $this->assertArrayNotHasKey($stepId, $result['stop_conditions']);
        }
    }

    // ── frontier_optional_deepening_steps ─────────────────────────────────────

    public function test_frontier_optional_deepening_steps_is_non_empty(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertNotEmpty($result['frontier_optional_deepening_steps']);
        foreach ($result['frontier_optional_deepening_steps'] as $step) {
            $this->assertIsString($step);
            $this->assertNotEmpty($step);
        }
    }

    // ── step content spot-checks ──────────────────────────────────────────────

    public function test_critique_step_mentions_proxy(): void
    {
        $result = $this->compiler()->compile([]);

        $step = current(array_filter(
            $result['runbook_steps'],
            static fn (array $s): bool => $s['step_id'] === 'critique',
        ));

        $this->assertStringContainsStringIgnoringCase('proxy', $step['instruction']);
    }

    public function test_final_enqueue_readiness_step_mentions_runnable(): void
    {
        $result = $this->compiler()->compile([]);

        $step = current(array_filter(
            $result['runbook_steps'],
            static fn (array $s): bool => $s['step_id'] === 'final_enqueue_readiness',
        ));

        $this->assertStringContainsStringIgnoringCase('runnable', $step['instruction']);
    }

    // ── risk_level adaptation (AC2) ────────────────────────────────────────────

    public function test_low_risk_does_not_make_escalation_mandatory(): void
    {
        $result = $this->compiler()->compile(['risk_level' => 'low']);

        $this->assertFalse($result['escalate_mandatory']);
    }

    public function test_high_risk_makes_escalation_mandatory(): void
    {
        $result = $this->compiler()->compile(['risk_level' => 'high']);

        $this->assertTrue($result['escalate_mandatory']);

        $escalateStep = current(array_filter(
            $result['runbook_steps'],
            static fn (array $s): bool => $s['step_id'] === 'escalate',
        ));
        $this->assertStringContainsStringIgnoringCase('mandatory', $escalateStep['instruction']);
    }

    public function test_medium_and_high_risk_critique_mentions_collision_sweep(): void
    {
        $low = $this->compiler()->compile(['risk_level' => 'low']);
        $high = $this->compiler()->compile(['risk_level' => 'high']);

        $lowCritique = current(array_filter($low['runbook_steps'], static fn (array $s): bool => $s['step_id'] === 'critique'));
        $highCritique = current(array_filter($high['runbook_steps'], static fn (array $s): bool => $s['step_id'] === 'critique'));

        $this->assertStringNotContainsStringIgnoringCase('collision sweep', $lowCritique['instruction']);
        $this->assertStringContainsStringIgnoringCase('collision sweep', $highCritique['instruction']);
    }

    public function test_runbook_is_not_one_generic_prompt_across_risk_levels(): void
    {
        $low = $this->compiler()->compile(['risk_level' => 'low']);
        $high = $this->compiler()->compile(['risk_level' => 'high']);

        $this->assertNotSame($low['runbook_steps'], $high['runbook_steps']);
    }

    // ── model_weaknesses adaptation (AC2) ──────────────────────────────────────

    public function test_known_model_weakness_injects_a_targeted_guard_into_critique(): void
    {
        $result = $this->compiler()->compile(['model_weaknesses' => ['template_farming']]);

        $critique = current(array_filter($result['runbook_steps'], static fn (array $s): bool => $s['step_id'] === 'critique'));
        $this->assertStringContainsStringIgnoringCase('template', $critique['instruction']);
        $this->assertSame(['template_farming'], $result['model_weaknesses']);
    }

    public function test_unknown_model_weakness_does_not_crash_and_is_still_recorded(): void
    {
        $result = $this->compiler()->compile(['model_weaknesses' => ['some_unmapped_weakness']]);

        $this->assertSame(['some_unmapped_weakness'], $result['model_weaknesses']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['mission' => 'Evolve brain', 'scope' => 'atlas-server', 'risk_level' => 'medium', 'model_weaknesses' => ['weak_acceptance']];

        $this->assertSame($this->compiler()->compile($input), $this->compiler()->compile($input));
    }

    // ── AC3: explicit escalation triggers ──────────────────────────────────────

    public function test_escalation_triggers_key_present(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('escalation_triggers', $result);
        $this->assertNotEmpty($result['escalation_triggers']);
    }

    public function test_escalation_triggers_include_ambiguous_architecture(): void
    {
        $result = $this->compiler()->compile([]);
        $triggerIds = array_column($result['escalation_triggers'], 'trigger_id');

        $this->assertContains('ambiguous_architecture', $triggerIds);
    }

    public function test_escalation_triggers_include_missing_evidence(): void
    {
        $result = $this->compiler()->compile([]);
        $triggerIds = array_column($result['escalation_triggers'], 'trigger_id');

        $this->assertContains('missing_evidence', $triggerIds);
    }

    public function test_escalation_triggers_include_repeated_low_yield_outputs(): void
    {
        $result = $this->compiler()->compile([]);
        $triggerIds = array_column($result['escalation_triggers'], 'trigger_id');

        $this->assertContains('repeated_low_yield_outputs', $triggerIds);
    }

    public function test_every_escalation_trigger_has_a_non_empty_description(): void
    {
        $result = $this->compiler()->compile([]);

        foreach ($result['escalation_triggers'] as $trigger) {
            $this->assertArrayHasKey('trigger_id', $trigger);
            $this->assertArrayHasKey('description', $trigger);
            $this->assertNotEmpty($trigger['description']);
        }
    }

    // ── AC2: research_deepening step before final_enqueue_readiness ───────────

    public function test_research_deepening_comes_before_final_enqueue_readiness(): void
    {
        $result = $this->compiler()->compile([]);
        $stepIds = array_column($result['runbook_steps'], 'step_id');

        $deepeningPos = array_search('research_deepening', $stepIds, true);
        $finalPos     = array_search('final_enqueue_readiness', $stepIds, true);

        $this->assertNotFalse($deepeningPos, 'research_deepening step must exist');
        $this->assertLessThan($finalPos, $deepeningPos, 'research_deepening must come before final_enqueue_readiness');
    }

    public function test_research_deepening_has_exhaustion_evidence_artifact(): void
    {
        $result = $this->compiler()->compile([]);

        $step = current(array_filter(
            $result['runbook_steps'],
            static fn (array $s): bool => $s['step_id'] === 'research_deepening',
        ));

        $this->assertSame('exhaustion_evidence', $step['mandatory_artifact']);
    }

    // ── AC3: exhaustion_evidence required before no-candidate stop ────────────

    public function test_research_deepening_stop_condition_requires_exhaustion_evidence(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('research_deepening', $result['stop_conditions']);
        $this->assertContains(
            'no_candidates_passed_gates_requires_exhaustion_evidence',
            $result['stop_conditions']['research_deepening'],
        );
    }

    // ── AC4: provider-independence ───────────────────────────────────────────

    public function test_provider_independent_flag_is_true(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertTrue($result['provider_independent']);
    }

    public function test_no_step_instruction_names_a_specific_external_paid_provider(): void
    {
        $result = $this->compiler()->compile(['risk_level' => 'high', 'model_weaknesses' => ['template_farming']]);

        foreach ($result['runbook_steps'] as $step) {
            foreach (['gpt-4', 'gpt-5', 'claude', 'openai', 'anthropic'] as $bannedName) {
                $this->assertStringNotContainsStringIgnoringCase($bannedName, $step['instruction']);
            }
        }
    }
}
