<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPatternDesignFailureModeMiner;
use Tests\TestCase;

final class AtlasExternalBrainPatternDesignFailureModeMinerTest extends TestCase
{
    private function fullCandidate(array $overrides = []): array
    {
        return array_merge([
            'failure_id' => 'f-1',
            'root_cause_label' => 'missing_scope_check',
            'task_count' => 2,
            'description' => 'worker edited files outside allowed_files',
            'prevention_rule' => 'reject packets missing scope guard',
            'enforcement_hook' => 'scope_guard_gate',
            'affected_task_family' => 'wiring',
            'falsification_check' => 'run scope_guard_gate against known-bad packet',
        ], $overrides);
    }

    public function test_one_off_anecdote_is_rejected(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['task_count' => 1])],
        ]);

        $this->assertSame([], $result['promoted_patterns']);
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_ONE_OFF_ANECDOTE,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_duplicate_label_is_rejected(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [
                $this->fullCandidate(['failure_id' => 'f-1']),
                $this->fullCandidate(['failure_id' => 'f-2']),
            ],
        ]);

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_DUPLICATE_LABEL,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_missing_prevention_rule_is_rejected(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['prevention_rule' => ''])],
        ]);

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_PREVENTION_RULE,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_missing_enforcement_hook_is_rejected(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['enforcement_hook' => ''])],
        ]);

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_ENFORCEMENT_HOOK,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_missing_affected_task_family_is_rejected(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['affected_task_family' => ''])],
        ]);

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_AFFECTED_FAMILY,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_missing_falsification_check_is_rejected(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['falsification_check' => ''])],
        ]);

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_NO_FALSIFICATION_CHECK,
            $result['rejected_candidates'][0]['rejection_reason'],
        );
    }

    public function test_task_count_two_promotes_with_medium_confidence(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['task_count' => 2])],
        ]);

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::CONFIDENCE_MEDIUM,
            $result['promoted_patterns'][0]['confidence'],
        );
    }

    public function test_task_count_five_promotes_with_high_confidence(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate(['task_count' => 5])],
        ]);

        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::CONFIDENCE_HIGH,
            $result['promoted_patterns'][0]['confidence'],
        );
    }

    public function test_promoted_patterns_emit_enforcement_hooks_and_task_fabric_patch_hints(): void
    {
        $result = (new AtlasExternalBrainPatternDesignFailureModeMiner)->mine([
            'failure_candidates' => [$this->fullCandidate()],
        ]);

        $this->assertContains('scope_guard_gate', $result['enforcement_hooks']);
        $this->assertCount(1, $result['task_fabric_patch_hints']);
        $this->assertSame('scope_guard_gate', $result['task_fabric_patch_hints'][0]['hook']);
        $this->assertSame('wiring', $result['task_fabric_patch_hints'][0]['family']);
    }
}
