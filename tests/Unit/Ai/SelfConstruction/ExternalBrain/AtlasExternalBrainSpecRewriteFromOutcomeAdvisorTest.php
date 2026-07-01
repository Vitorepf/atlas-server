<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecRewriteFromOutcomeAdvisor;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainSpecRewriteFromOutcomeAdvisor turns real muscle outcomes (weak success, give_back
 * due to insufficient allowed_files, repeated poison/quarantine, shallow acceptance criteria) into concrete,
 * evidence-STRENGTHENING rewrite_actions — never a wrapper-only or operator-dependent spec — while terminal
 * root causes (capability already exists / forbidden target) stay give_back_or_retire with no fake rewrite.
 */
final class AtlasExternalBrainSpecRewriteFromOutcomeAdvisorTest extends TestCase
{
    private AtlasExternalBrainSpecRewriteFromOutcomeAdvisor $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advisor = new AtlasExternalBrainSpecRewriteFromOutcomeAdvisor;
    }

    public function test_weak_success_recommends_measurable_capability_delta_evidence(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['weak_success']]);

        $this->assertSame('rewrite', $result['recommendation']);
        $this->assertSame(
            'require_measurable_capability_delta_via_runnable_test_command',
            $result['rewrite_actions']['required_evidence'],
        );
    }

    public function test_give_back_due_insufficient_allowed_files_expands_allowed_files(): void
    {
        $byLesson = $this->advisor->advise(['outcome_lessons' => ['give_back_due_to_insufficient_allowed_files']]);
        $byRootCause = $this->advisor->advise(['give_back_root_cause' => 'insufficient_allowed_files']);

        foreach ([$byLesson, $byRootCause] as $result) {
            $this->assertSame('rewrite', $result['recommendation']);
            $this->assertSame(
                'expand_allowed_files_to_cover_required_implementation',
                $result['rewrite_actions']['allowed_files'],
            );
        }
    }

    public function test_repeated_poison_or_quarantine_by_count_tightens_acceptance_criteria(): void
    {
        $result = $this->advisor->advise(['poison_or_quarantine_count' => 3]);

        $this->assertSame('rewrite', $result['recommendation']);
        $this->assertSame(
            'tighten_acceptance_criteria_with_concrete_implementation_and_test_file_requirements',
            $result['rewrite_actions']['acceptance_criteria'],
        );
    }

    public function test_repeated_poison_or_quarantine_by_lesson_tightens_acceptance_criteria(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['repeated_poison_or_quarantine']]);

        $this->assertSame(
            'tighten_acceptance_criteria_with_concrete_implementation_and_test_file_requirements',
            $result['rewrite_actions']['acceptance_criteria'],
        );
    }

    public function test_a_single_poison_or_quarantine_is_not_yet_repeated(): void
    {
        $result = $this->advisor->advise(['poison_or_quarantine_count' => 1]);

        $this->assertNull($result['rewrite_actions']['acceptance_criteria']);
    }

    public function test_shallow_acceptance_criteria_adds_concrete_runnable_gate_examples(): void
    {
        $result = $this->advisor->advise(['outcome_lessons' => ['shallow_acceptance_criteria']]);

        $this->assertSame(
            'add_concrete_runnable_gate_examples_to_shallow_acceptance_criteria',
            $result['rewrite_actions']['acceptance_criteria'],
        );
    }

    public function test_attempt_failed_still_wins_over_repeated_poison_for_acceptance_criteria(): void
    {
        // A concrete repair signal is more specific than the broader poison/shallow signals — it must win.
        $result = $this->advisor->advise([
            'outcome_lessons' => ['attempt_failed', 'repeated_poison_or_quarantine', 'shallow_acceptance_criteria'],
        ]);

        $this->assertSame(
            'add_explicit_failure_mode_coverage_to_acceptance_criteria',
            $result['rewrite_actions']['acceptance_criteria'],
        );
    }

    public function test_terminal_capability_already_exists_yields_give_back_or_retire_with_no_fake_rewrite(): void
    {
        $result = $this->advisor->advise([
            'give_back_root_cause' => 'capability_already_exists',
            'outcome_lessons' => ['weak_success', 'shallow_acceptance_criteria'],
            'poison_or_quarantine_count' => 5,
        ]);

        $this->assertSame('give_back_or_retire', $result['recommendation']);
        $this->assertSame(['retire_or_give_back'], $result['prioritized_actions']);
        foreach ($result['rewrite_actions'] as $action) {
            $this->assertNull($action, 'a terminal root cause never produces a fake rewrite action');
        }
    }

    public function test_terminal_forbidden_target_yields_give_back_or_retire(): void
    {
        $result = $this->advisor->advise(['give_back_root_cause' => 'forbidden_target']);

        $this->assertSame('give_back_or_retire', $result['recommendation']);
        $this->assertSame('forbidden_target', $result['reason']);
    }

    public function test_all_new_signals_together_are_deterministic_and_never_wrapper_or_operator_dependent(): void
    {
        $input = [
            'outcome_lessons' => ['weak_success', 'give_back_due_to_insufficient_allowed_files', 'shallow_acceptance_criteria'],
            'poison_or_quarantine_count' => 2,
        ];

        $run1 = $this->advisor->advise($input);
        $run2 = $this->advisor->advise($input);
        $this->assertSame($run1, $run2, 'rewrite advice is deterministic for identical outcome input');

        $actions = array_filter($run1['rewrite_actions'], static fn ($a) => $a !== null);
        $this->assertNotEmpty($actions, 'concrete rewrite actions must be emitted for weak success + insufficient scope + shallow acceptance');

        $forbiddenVocabulary = ['wrapper', 'stub_only', 'manual_operator', 'skip_test', 'remove_test', 'operator_must', 'proxy'];
        foreach ($actions as $field => $action) {
            foreach ($forbiddenVocabulary as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $action, "rewrite_actions[{$field}] must not weaken into a wrapper/operator-dependent spec");
            }
        }

        // Evidence + implementation/test-file strengthening is preserved, not dropped.
        $this->assertStringContainsString('runnable_test_command', $run1['rewrite_actions']['required_evidence']);
        $this->assertStringContainsString('implementation', $run1['rewrite_actions']['allowed_files']);
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
