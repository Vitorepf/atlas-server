<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPatternDesignFailureModeMiner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPatternDesignFailureModeMinerTest extends TestCase
{
    private AtlasExternalBrainPatternDesignFailureModeMiner $miner;

    protected function setUp(): void
    {
        $this->miner = new AtlasExternalBrainPatternDesignFailureModeMiner;
    }

    private function good(array $overrides = []): array
    {
        return array_merge([
            'failure_id'           => 'f-001',
            'root_cause_label'     => 'missing_acceptance_command',
            'task_count'           => 3,
            'description'          => 'Tasks repeatedly lack runnable acceptance criteria',
            'prevention_rule'      => 'require at least one artisan/phpunit command in acceptance_criteria',
            'enforcement_hook'     => 'AtlasExternalBrainAcceptanceReplayCoverageMatrix::audit',
            'affected_task_family' => 'task_fabric:origination_quality',
            'falsification_check'  => 'passes_if_all_tasks_have_acceptance_command',
        ], $overrides);
    }

    private function input(array ...$candidates): array
    {
        return ['failure_candidates' => $candidates];
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->miner->mine($this->input($this->good()));

        foreach (['schema', 'promoted_patterns', 'rejected_candidates', 'enforcement_hooks', 'confidence_reasons', 'task_fabric_patch_hints'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainPatternDesignFailureModeMiner::SCHEMA, $result['schema']);
    }

    // ── AC2: promote when all six conditions pass ─────────────────────────────

    public function test_candidate_with_two_or_more_failures_and_all_fields_is_promoted(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 2])));

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertCount(0, $result['rejected_candidates']);
    }

    public function test_promoted_pattern_has_all_fields(): void
    {
        $result = $this->miner->mine($this->input($this->good()));
        $pattern = $result['promoted_patterns'][0];

        foreach ([
            'failure_id', 'root_cause_label', 'task_count', 'description',
            'prevention_rule', 'enforcement_hook', 'affected_task_family',
            'falsification_check', 'confidence',
        ] as $k) {
            $this->assertArrayHasKey($k, $pattern, "Promoted pattern missing: {$k}");
        }
    }

    public function test_promoted_pattern_stores_affected_task_family(): void
    {
        $result = $this->miner->mine($this->input($this->good(['affected_task_family' => 'task_fabric:give_back_prevention'])));
        $this->assertSame('task_fabric:give_back_prevention', $result['promoted_patterns'][0]['affected_task_family']);
    }

    public function test_promoted_pattern_stores_falsification_check(): void
    {
        $result = $this->miner->mine($this->input($this->good(['falsification_check' => 'fails_if_any_task_has_no_allowed_files'])));
        $this->assertSame('fails_if_any_task_has_no_allowed_files', $result['promoted_patterns'][0]['falsification_check']);
    }

    // ── AC3: rejection — one_off_anecdote ─────────────────────────────────────

    public function test_rejects_one_off_anecdote_when_task_count_is_one(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 1])));

        $this->assertCount(0, $result['promoted_patterns']);
        $this->assertCount(1, $result['rejected_candidates']);
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_ONE_OFF_ANECDOTE,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_rejects_zero_count_as_one_off(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 0])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_ONE_OFF_ANECDOTE,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: rejection — duplicate_label ──────────────────────────────────────

    public function test_rejects_second_candidate_with_same_root_cause_label(): void
    {
        $result = $this->miner->mine($this->input(
            $this->good(['failure_id' => 'f-001']),
            $this->good(['failure_id' => 'f-002']),
        ));

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertCount(1, $result['rejected_candidates']);
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_DUPLICATE_LABEL,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: rejection — no_prevention_rule ──────────────────────────────────

    public function test_rejects_candidate_with_no_prevention_rule(): void
    {
        $result = $this->miner->mine($this->input($this->good(['prevention_rule' => ''])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_PREVENTION_RULE,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: rejection — no_enforcement_hook ─────────────────────────────────

    public function test_rejects_candidate_with_no_enforcement_hook(): void
    {
        $result = $this->miner->mine($this->input($this->good(['enforcement_hook' => ''])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_ENFORCEMENT_HOOK,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: rejection — no_affected_family ──────────────────────────────────

    public function test_rejects_candidate_with_no_affected_family(): void
    {
        $result = $this->miner->mine($this->input($this->good(['affected_task_family' => ''])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_AFFECTED_FAMILY,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: rejection — no_falsification_check ──────────────────────────────

    public function test_rejects_candidate_with_no_falsification_check(): void
    {
        $result = $this->miner->mine($this->input($this->good(['falsification_check' => ''])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_FALSIFICATION_CHECK,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC4: enforcement_hooks list ───────────────────────────────────────────

    public function test_enforcement_hooks_collected_from_promoted_patterns(): void
    {
        $result = $this->miner->mine($this->input(
            $this->good(['failure_id' => 'f-1', 'root_cause_label' => 'cause_a', 'enforcement_hook' => 'HookA::check']),
            $this->good(['failure_id' => 'f-2', 'root_cause_label' => 'cause_b', 'enforcement_hook' => 'HookB::check']),
        ));

        $this->assertContains('HookA::check', $result['enforcement_hooks']);
        $this->assertContains('HookB::check', $result['enforcement_hooks']);
    }

    public function test_duplicate_enforcement_hooks_deduped(): void
    {
        $result = $this->miner->mine($this->input(
            $this->good(['failure_id' => 'f-1', 'root_cause_label' => 'cause_a', 'enforcement_hook' => 'SharedHook::check']),
            $this->good(['failure_id' => 'f-2', 'root_cause_label' => 'cause_b', 'enforcement_hook' => 'SharedHook::check']),
        ));

        $this->assertCount(1, $result['enforcement_hooks']);
    }

    // ── AC4: task_fabric_patch_hints ─────────────────────────────────────────

    public function test_task_fabric_patch_hints_populated_for_each_promoted(): void
    {
        $result = $this->miner->mine($this->input($this->good()));

        $this->assertCount(1, $result['task_fabric_patch_hints']);
        $hint = $result['task_fabric_patch_hints'][0];

        foreach (['family', 'hook', 'prevention_rule', 'failure_id'] as $k) {
            $this->assertArrayHasKey($k, $hint, "Patch hint missing: {$k}");
        }
    }

    public function test_patch_hint_family_matches_affected_task_family(): void
    {
        $result = $this->miner->mine($this->input(
            $this->good(['affected_task_family' => 'task_fabric:poison_packet_guard']),
        ));

        $this->assertSame('task_fabric:poison_packet_guard', $result['task_fabric_patch_hints'][0]['family']);
    }

    public function test_patch_hints_empty_when_nothing_promoted(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 1])));

        $this->assertSame([], $result['task_fabric_patch_hints']);
    }

    // ── confidence_reasons ────────────────────────────────────────────────────

    public function test_confidence_high_for_task_count_at_least_five(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 5])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::CONFIDENCE_HIGH,
            $result['promoted_patterns'][0]['confidence'],
        );
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::CONFIDENCE_HIGH,
            $result['confidence_reasons'][0]['confidence'],
        );
    }

    public function test_confidence_medium_for_task_count_between_two_and_four(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 3])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::CONFIDENCE_MEDIUM,
            $result['promoted_patterns'][0]['confidence'],
        );
    }

    public function test_confidence_reason_is_non_empty_string(): void
    {
        $result = $this->miner->mine($this->input($this->good()));

        $this->assertIsString($result['confidence_reasons'][0]['reason']);
        $this->assertNotEmpty($result['confidence_reasons'][0]['reason']);
    }

    // ── Empty batch ───────────────────────────────────────────────────────────

    public function test_empty_batch_returns_empty_collections(): void
    {
        $result = $this->miner->mine(['failure_candidates' => []]);

        $this->assertSame([], $result['promoted_patterns']);
        $this->assertSame([], $result['rejected_candidates']);
        $this->assertSame([], $result['enforcement_hooks']);
        $this->assertSame([], $result['confidence_reasons']);
        $this->assertSame([], $result['task_fabric_patch_hints']);
    }
}
