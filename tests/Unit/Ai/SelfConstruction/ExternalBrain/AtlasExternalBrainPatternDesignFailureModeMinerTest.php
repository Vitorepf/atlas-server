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
            'failure_id'       => 'f-001',
            'root_cause_label' => 'missing_acceptance_command',
            'task_count'       => 3,
            'description'      => 'Tasks repeatedly lack runnable acceptance criteria',
            'prevention_rule'  => 'require at least one artisan/phpunit command in acceptance_criteria',
            'enforcement_hook' => 'AtlasExternalBrainAcceptanceReplayCoverageMatrix::audit',
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

        foreach (['schema', 'promoted_patterns', 'rejected_candidates', 'enforcement_hooks', 'confidence_reasons'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainPatternDesignFailureModeMiner::SCHEMA, $result['schema']);
    }

    // ── AC2: promote when ≥2 failures share root cause + prevention rule ──────

    public function test_candidate_with_two_or_more_failures_and_prevention_rule_is_promoted(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 2])));

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertCount(0, $result['rejected_candidates']);
    }

    public function test_promoted_pattern_has_all_fields(): void
    {
        $result = $this->miner->mine($this->input($this->good()));
        $pattern = $result['promoted_patterns'][0];

        foreach (['failure_id', 'root_cause_label', 'task_count', 'description', 'prevention_rule', 'enforcement_hook', 'confidence'] as $k) {
            $this->assertArrayHasKey($k, $pattern, "Promoted pattern missing: {$k}");
        }
    }

    // ── AC3: reject one-off anecdotes (task_count < 2) ───────────────────────

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

    // ── AC3: reject duplicate labels ──────────────────────────────────────────

    public function test_rejects_second_candidate_with_same_root_cause_label(): void
    {
        $result = $this->miner->mine($this->input(
            $this->good(['failure_id' => 'f-001']),
            $this->good(['failure_id' => 'f-002']),  // same root_cause_label
        ));

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertCount(1, $result['rejected_candidates']);
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_DUPLICATE_LABEL,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: reject no prevention rule ────────────────────────────────────────

    public function test_rejects_candidate_with_no_prevention_rule(): void
    {
        $result = $this->miner->mine($this->input($this->good(['prevention_rule' => ''])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_PREVENTION_RULE,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC3: reject no enforcement hook ──────────────────────────────────────

    public function test_rejects_candidate_with_no_enforcement_hook(): void
    {
        $result = $this->miner->mine($this->input($this->good(['enforcement_hook' => ''])));

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_ENFORCEMENT_HOOK,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    // ── AC4: enforcement_hooks list populated ─────────────────────────────────

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

    // ── AC4: confidence_reasons populated ─────────────────────────────────────

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
    }
}
