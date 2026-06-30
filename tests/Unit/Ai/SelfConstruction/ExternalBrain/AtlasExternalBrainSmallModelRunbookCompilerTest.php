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

        foreach (['schema', 'mission', 'scope', 'runbook_steps', 'mandatory_artifacts',
                  'stop_conditions', 'frontier_optional_deepening_steps'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── mission and scope ─────────────────────────────────────────────────────

    public function test_mission_and_scope_passed_through(): void
    {
        $result = $this->compiler()->compile([
            'mission' => 'Evolve the external brain',
            'scope'   => 'atlas-server',
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

    public function test_six_steps_emitted(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertCount(6, $result['runbook_steps']);
    }

    public function test_steps_ordered_sequentially(): void
    {
        $result = $this->compiler()->compile([]);

        $orders = array_column($result['runbook_steps'], 'order');
        $this->assertSame([1, 2, 3, 4, 5, 6], $orders);
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

    public function test_correct_step_ids_in_order(): void
    {
        $result  = $this->compiler()->compile([]);
        $stepIds = array_column($result['runbook_steps'], 'step_id');

        $this->assertSame([
            'context_read',
            'duplicate_search',
            'candidate_drafting',
            'anti_goodhart_critique',
            'evidence_proof',
            'final_enqueue_readiness',
        ], $stepIds);
    }

    // ── mandatory_artifacts ───────────────────────────────────────────────────

    public function test_mandatory_artifacts_lists_all_six_artifact_names(): void
    {
        $result = $this->compiler()->compile([]);

        $expected = ['evidence_list', 'dedup_proof', 'candidate_list', 'critique_report', 'grep_evidence', 'final_batch'];
        $this->assertSame($expected, $result['mandatory_artifacts']);
    }

    // ── stop_conditions ───────────────────────────────────────────────────────

    public function test_context_read_has_no_evidence_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('context_read', $result['stop_conditions']);
        $this->assertContains('no_evidence_available', $result['stop_conditions']['context_read']);
    }

    public function test_duplicate_search_has_all_duplicates_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('duplicate_search', $result['stop_conditions']);
        $this->assertContains('all_candidates_duplicate_existing_work', $result['stop_conditions']['duplicate_search']);
    }

    public function test_evidence_proof_has_no_files_match_stop_condition(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertArrayHasKey('evidence_proof', $result['stop_conditions']);
        $this->assertContains('no_files_match_grep_pattern', $result['stop_conditions']['evidence_proof']);
    }

    public function test_steps_without_stop_conditions_are_not_in_stop_conditions_map(): void
    {
        $result = $this->compiler()->compile([]);

        // candidate_drafting, anti_goodhart_critique, final_enqueue_readiness have no stop conditions
        $this->assertArrayNotHasKey('candidate_drafting', $result['stop_conditions']);
        $this->assertArrayNotHasKey('anti_goodhart_critique', $result['stop_conditions']);
        $this->assertArrayNotHasKey('final_enqueue_readiness', $result['stop_conditions']);
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

    public function test_anti_goodhart_critique_step_mentions_proxy(): void
    {
        $result = $this->compiler()->compile([]);

        $step = current(array_filter(
            $result['runbook_steps'],
            static fn (array $s): bool => $s['step_id'] === 'anti_goodhart_critique',
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

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['mission' => 'Evolve brain', 'scope' => 'atlas-server'];

        $this->assertSame($this->compiler()->compile($input), $this->compiler()->compile($input));
    }
}
