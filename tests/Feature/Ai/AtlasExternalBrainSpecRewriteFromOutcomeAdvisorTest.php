<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecRewriteFromOutcomeAdvisor;
use Tests\TestCase;

final class AtlasExternalBrainSpecRewriteFromOutcomeAdvisorTest extends TestCase
{
    private AtlasExternalBrainSpecRewriteFromOutcomeAdvisor $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advisor = new AtlasExternalBrainSpecRewriteFromOutcomeAdvisor;
    }

    public function test_capability_already_exists_yields_give_back_or_retire(): void
    {
        $result = $this->advisor->advise(['give_back_root_cause' => 'capability_already_exists']);

        $this->assertSame(AtlasExternalBrainSpecRewriteFromOutcomeAdvisor::SCHEMA, $result['schema']);
        $this->assertSame('give_back_or_retire', $result['recommendation']);
        $this->assertSame('capability_already_exists', $result['reason']);
        foreach ($result['rewrite_actions'] as $action) {
            $this->assertNull($action);
        }
    }

    public function test_forbidden_target_yields_give_back_or_retire(): void
    {
        $result = $this->advisor->advise(['give_back_root_cause' => 'forbidden_target']);

        $this->assertSame('give_back_or_retire', $result['recommendation']);
    }

    public function test_contradictory_acceptance_yields_give_back_or_retire(): void
    {
        $result = $this->advisor->advise(['give_back_root_cause' => 'contradictory_acceptance']);

        $this->assertSame('give_back_or_retire', $result['recommendation']);
    }

    public function test_unclear_spec_lesson_recommends_objective_rewrite(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['give_back_due_to_unclear_spec']]);

        $this->assertSame('rewrite', $result['recommendation']);
        $this->assertSame('clarify_objective_with_concrete_acceptance_examples', $result['rewrite_actions']['objective']);
    }

    public function test_scope_mismatch_lesson_recommends_allowed_files_rewrite(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['give_back_due_to_scope_mismatch']]);

        $this->assertSame('expand_allowed_files_to_cover_required_implementation', $result['rewrite_actions']['allowed_files']);
    }

    public function test_attempt_failed_lesson_recommends_acceptance_criteria_rewrite(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['attempt_failed']]);

        $this->assertSame('add_explicit_failure_mode_coverage_to_acceptance_criteria', $result['rewrite_actions']['acceptance_criteria']);
    }

    public function test_repair_lesson_recommends_acceptance_criteria_rewrite(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['required_repair_after_initial_attempt']]);

        $this->assertSame('add_explicit_failure_mode_coverage_to_acceptance_criteria', $result['rewrite_actions']['acceptance_criteria']);
    }

    public function test_self_reported_success_lesson_recommends_required_evidence_rewrite(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['self_reported_success_without_evidence']]);

        $this->assertSame('require_runnable_test_command_in_acceptance_criteria', $result['rewrite_actions']['required_evidence']);
    }

    public function test_tests_not_authored_recommends_required_evidence_rewrite(): void
    {
        $result = $this->advisor->advise(['test_coverage_facts' => ['tests_authored' => false]]);

        $this->assertSame('require_runnable_test_command_in_acceptance_criteria', $result['rewrite_actions']['required_evidence']);
    }

    public function test_duplicate_signal_with_unclassified_give_back_recommends_dependency_dedup_check(): void
    {
        $result = $this->advisor->advise([
            'outcome_lessons' => ['give_back_unclassified_reason'],
            'give_back_root_cause' => 'looks like a duplicate of an existing capability',
        ]);

        $this->assertSame('add_dedup_check_against_existing_capability', $result['rewrite_actions']['dependencies']);
    }

    public function test_unclassified_give_back_without_duplicate_signal_does_not_recommend_dedup(): void
    {
        $result = $this->advisor->advise([
            'outcome_lessons' => ['give_back_unclassified_reason'],
            'give_back_root_cause' => 'unrelated reason',
        ]);

        $this->assertNull($result['rewrite_actions']['dependencies']);
    }

    public function test_large_changed_file_evidence_recommends_split(): void
    {
        $manyFiles = array_map(static fn (int $i): string => "app/File{$i}.php", range(1, 15));
        $result = $this->advisor->advise(['changed_file_evidence' => $manyFiles]);

        $this->assertSame('split', $result['rewrite_actions']['task_split_or_merge']);
    }

    public function test_large_changed_file_count_lesson_recommends_split(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['large_changed_file_count_review_scope']]);

        $this->assertSame('split', $result['rewrite_actions']['task_split_or_merge']);
    }

    public function test_no_signals_yields_all_null_rewrite_actions(): void
    {
        $result = $this->advisor->advise([]);

        $this->assertSame('rewrite', $result['recommendation']);
        foreach ($result['rewrite_actions'] as $key => $action) {
            $this->assertNull($action, "{$key} should be null with no signals");
        }
    }
}
