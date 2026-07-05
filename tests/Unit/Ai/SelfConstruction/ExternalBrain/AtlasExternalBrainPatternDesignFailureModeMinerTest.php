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

    public function test_promoted_pattern_has_acceptance_criteria_vocabulary_fields(): void
    {
        $result = $this->miner->mine($this->input($this->good()));
        $pattern = $result['promoted_patterns'][0];

        foreach (['task_fabric_patch', 'enforcement_surface', 'falsification_check', 'affected_task_families', 'confidence'] as $k) {
            $this->assertArrayHasKey($k, $pattern, "Promoted pattern missing: {$k}");
        }
        $this->assertSame($pattern['enforcement_hook'], $pattern['enforcement_surface']);
        $this->assertSame($pattern['affected_families'], $pattern['affected_task_families']);
        $this->assertArrayHasKey('hook', $pattern['task_fabric_patch']);
        $this->assertArrayHasKey('prevention_rule', $pattern['task_fabric_patch']);
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

    // ── affected_families / repair_hint (AC3) ───────────────────────────────────

    public function test_promoted_pattern_emits_affected_families_list(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [$this->good()]]);

        $this->assertArrayHasKey('affected_families', $result['promoted_patterns'][0]);
        $this->assertContains('task_fabric:origination_quality', $result['promoted_patterns'][0]['affected_families']);
    }

    public function test_affected_families_merges_extra_families_input(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [
            $this->good(['affected_task_families' => ['task_fabric:scope_repair', 'task_fabric:dedup']]),
        ]]);

        $families = $result['promoted_patterns'][0]['affected_families'];
        $this->assertContains('task_fabric:origination_quality', $families);
        $this->assertContains('task_fabric:scope_repair', $families);
        $this->assertContains('task_fabric:dedup', $families);
    }

    public function test_promoted_pattern_emits_non_empty_repair_hint(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [$this->good()]]);

        $this->assertNotEmpty($result['promoted_patterns'][0]['repair_hint']);
        $this->assertStringContainsString('require at least one artisan/phpunit command', $result['promoted_patterns'][0]['repair_hint']);
    }

    public function test_avoids_overgeneralizing_from_isolated_failure(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [$this->good(['task_count' => 1])]]);

        $this->assertSame([], $result['promoted_patterns']);
        $this->assertSame(AtlasExternalBrainPatternDesignFailureModeMiner::REJECTION_ONE_OFF_ANECDOTE, $result['rejected_candidates'][0]['rejection_reason']);
    }

    // ── AC: repeated scope repair failures produce missing_scope_closure ───────

    public function test_repeated_scope_repair_failures_produce_missing_scope_closure_mode(): void
    {
        $result = $this->miner->mine($this->input($this->good([
            'failure_id'           => 'f-scope-1',
            'root_cause_label'     => 'missing_scope_closure',
            'task_count'           => 4,
            'description'          => 'Scope repair repeatedly leaves allowed_files without a matching test file',
            'prevention_rule'      => 'require scope repair to close both impl and test file pairs',
            'enforcement_hook'     => 'AtlasTaskFabricScopeMinimalityAuditor::audit',
            'affected_task_family' => 'task_fabric:scope_repair',
            'falsification_check'  => 'passes_if_all_repaired_scopes_have_impl_and_test',
        ])));

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertSame('missing_scope_closure', $result['promoted_patterns'][0]['root_cause_label']);
    }

    // ── AC: repeated proxy specs produce proxy_value_pattern ────────────────────

    public function test_repeated_proxy_specs_produce_proxy_value_pattern_mode(): void
    {
        $result = $this->miner->mine($this->input($this->good([
            'failure_id'           => 'f-proxy-1',
            'root_cause_label'     => 'proxy_value_pattern',
            'task_count'           => 6,
            'description'          => 'Specs repeatedly claim value via test_count/task_count deltas alone',
            'prevention_rule'      => 'require capability_lift_refs or failure_removal_refs alongside any proxy signal',
            'enforcement_hook'     => 'AtlasGoalValueAntiProxyGate::evaluate',
            'affected_task_family' => 'task_fabric:origination_quality',
            'falsification_check'  => 'passes_if_no_promoted_spec_relies_on_proxy_signal_alone',
        ])));

        $this->assertCount(1, $result['promoted_patterns']);
        $this->assertSame('proxy_value_pattern', $result['promoted_patterns'][0]['root_cause_label']);
        $this->assertSame(
            AtlasExternalBrainPatternDesignFailureModeMiner::CONFIDENCE_HIGH,
            $result['promoted_patterns'][0]['confidence'],
        );
    }

    // ── AC: each mined mode includes guardrail_task_hint and evidence_count ────

    public function test_promoted_pattern_has_guardrail_task_hint_and_evidence_count(): void
    {
        $result = $this->miner->mine($this->input($this->good(['task_count' => 4])));
        $pattern = $result['promoted_patterns'][0];

        $this->assertArrayHasKey('guardrail_task_hint', $pattern);
        $this->assertArrayHasKey('evidence_count', $pattern);
        $this->assertNotEmpty($pattern['guardrail_task_hint']);
        $this->assertSame(4, $pattern['evidence_count']);
    }

    public function test_guardrail_task_hint_names_prevention_rule_and_enforcement_hook(): void
    {
        $result = $this->miner->mine($this->input($this->good([
            'prevention_rule'  => 'require runnable acceptance command',
            'enforcement_hook' => 'SomeGate::check',
        ])));

        $hint = $result['promoted_patterns'][0]['guardrail_task_hint'];
        $this->assertStringContainsString('require runnable acceptance command', $hint);
        $this->assertStringContainsString('SomeGate::check', $hint);
    }

    // ── AC2: repeated failure candidates emit repair task hints with target, enforcement, falsification ──

    public function test_repeated_failures_emit_repair_task_hints_with_all_fields(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [
            [
                'root_cause_label' => 'missing_scope_validation',
                'task_count' => 3,
                'prevention_rule' => 'validate scope before enqueue',
                'enforcement_hook' => 'ScopeGuard::check',
                'affected_task_family' => 'scope_repair',
                'falsification_check' => 'assert ScopeGuard rejects invalid scope',
                'repair_hint' => 'Add scope validation gate before task enqueue',
            ],
        ]]);

        $this->assertNotEmpty($result['promoted_patterns']);
        $pattern = $result['promoted_patterns'][0];
        $this->assertArrayHasKey('repair_hint', $pattern);
        $this->assertNotEmpty($pattern['repair_hint']);
        $this->assertArrayHasKey('enforcement_hook', $pattern);
        $this->assertArrayHasKey('falsification_check', $pattern);
    }

    // ── AC3: one-off anecdotes and duplicate labels remain rejected ──

    public function test_one_off_anecdote_rejected(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [
            [
                'root_cause_label' => 'rare_bug',
                'task_count' => 1,
                'prevention_rule' => 'test more',
                'enforcement_hook' => 'TestGuard::check',
                'affected_task_family' => 'testing',
                'falsification_check' => 'assert tests pass',
                'repair_hint' => 'Add more tests',
            ],
        ]]);

        $this->assertEmpty($result['promoted_patterns']);
    }

    public function test_duplicate_label_rejected(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [
            [
                'root_cause_label' => 'duplicate_cause',
                'task_count' => 3,
                'prevention_rule' => 'rule1',
                'enforcement_hook' => 'Guard1::check',
                'affected_task_family' => 'family1',
                'falsification_check' => 'check1',
                'repair_hint' => 'fix1',
            ],
            [
                'root_cause_label' => 'duplicate_cause',
                'task_count' => 2,
                'prevention_rule' => 'rule2',
                'enforcement_hook' => 'Guard2::check',
                'affected_task_family' => 'family2',
                'falsification_check' => 'check2',
                'repair_hint' => 'fix2',
            ],
        ]]);

        // Second duplicate should be rejected
        $this->assertLessThanOrEqual(1, count($result['promoted_patterns']));
    }

    // ── AC4: promoted failure modes expose Task Fabric patch hints ──

    public function test_promoted_modes_expose_task_fabric_patch_hints(): void
    {
        $result = $this->miner->mine(['failure_candidates' => [
            [
                'root_cause_label' => 'missing_gate',
                'task_count' => 3,
                'prevention_rule' => 'add gate',
                'enforcement_hook' => 'Gate::check',
                'affected_task_family' => 'certification',
                'falsification_check' => 'assert gate blocks',
                'repair_hint' => 'Add certification gate',
            ],
        ]]);

        $this->assertNotEmpty($result['task_fabric_patch_hints'] ?? $result['promoted_patterns']);
    }
}
