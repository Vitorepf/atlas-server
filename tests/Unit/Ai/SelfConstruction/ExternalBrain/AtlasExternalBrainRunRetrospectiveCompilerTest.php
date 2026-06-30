<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRunRetrospectiveCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRunRetrospectiveCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainRunRetrospectiveCompiler
    {
        return new AtlasExternalBrainRunRetrospectiveCompiler;
    }

    private function outcome(string $specId, string $outcome, string $category = 'bug_fix', array $extra = []): array
    {
        return array_merge([
            'spec_id'      => $specId,
            'outcome'      => $outcome,
            'category'     => $category,
            'tokens_spent' => 500,
        ], $extra);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.run_retrospective_compiler.v1',
            AtlasExternalBrainRunRetrospectiveCompiler::SCHEMA,
        );
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->compiler()->compile([]);

        foreach (['schema', 'run_id', 'integrity_signal', 'summary', 'high_leverage_specs', 'wasted_specs', 'lessons', 'root_cause_map', 'policy_adjustments', 'next_cycle_hints'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SCHEMA, $result['schema']);
    }

    public function test_mixed_outcomes_compile_into_concise_summary(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success',     'architecture_unlock', ['leverage_score' => 0.9]),
            $this->outcome('t-2', 'success',     'architecture_unlock', ['leverage_score' => 0.8]),
            $this->outcome('t-3', 'give_back',   'docs_sync',          ['reason' => 'scope_too_large']),
            $this->outcome('t-4', 'rejected',    'docs_sync',          ['reason' => 'contradictory_acceptance']),
            $this->outcome('t-5', 'proxy_smell', 'docs_sync'),
        ];

        $result = $this->compiler()->compile($outcomes, ['run_id' => 'run-001']);

        $this->assertSame('run-001', $result['run_id']);
        $this->assertSame(5, $result['summary']['total_outcomes']);
        $this->assertSame(2, $result['summary']['success_count']);
        $this->assertSame(1, $result['summary']['give_back_count']);
        $this->assertSame(1, $result['summary']['rejected_count']);
        $this->assertSame(1, $result['summary']['proxy_smell_count']);
        $this->assertEqualsWithDelta(0.4, $result['summary']['yield_rate'], 0.001);
    }

    public function test_all_success_run_is_honest_signal(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success', 'architecture_unlock'),
            $this->outcome('t-2', 'success', 'architecture_unlock'),
            $this->outcome('t-3', 'success', 'bug_fix'),
        ];

        $result = $this->compiler()->compile($outcomes);

        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SIGNAL_HONEST, $result['integrity_signal']);
    }

    public function test_padding_pattern_triggers_padding_detected_integrity(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success',   'test_gate', ['poison_patterns' => ['test_count_padding']]),
            $this->outcome('t-2', 'give_back', 'test_gate'),
        ];

        $result = $this->compiler()->compile($outcomes);

        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SIGNAL_PADDING_DETECTED, $result['integrity_signal']);
    }

    public function test_proxy_smell_above_30_percent_triggers_padding_detected(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'proxy_smell', 'docs_sync'),
            $this->outcome('t-2', 'proxy_smell', 'docs_sync'),
            $this->outcome('t-3', 'success',     'architecture_unlock'),
            $this->outcome('t-4', 'success',     'bug_fix'),
            $this->outcome('t-5', 'proxy_smell', 'docs_sync'),
        ];

        // proxy_smell_count = 3 / 5 = 60% > 30%
        $result = $this->compiler()->compile($outcomes);

        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SIGNAL_PADDING_DETECTED, $result['integrity_signal']);
    }

    public function test_degraded_signal_when_yield_below_40_percent(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success',   'bug_fix'),
            $this->outcome('t-2', 'give_back', 'bug_fix', ['reason' => 'scope_too_large']),
            $this->outcome('t-3', 'give_back', 'bug_fix', ['reason' => 'insufficient_context']),
            $this->outcome('t-4', 'rejected',  'bug_fix', ['reason' => 'scope_too_large']),
        ];

        // 1/4 = 25% yield → degraded
        $result = $this->compiler()->compile($outcomes);

        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SIGNAL_DEGRADED, $result['integrity_signal']);
    }

    public function test_high_leverage_specs_listed_for_high_score_successes(): void
    {
        $outcomes = [
            $this->outcome('t-high',  'success', 'architecture_unlock', ['leverage_score' => 0.85]),
            $this->outcome('t-low',   'success', 'bug_fix',             ['leverage_score' => 0.50]),
            $this->outcome('t-below', 'success', 'docs_sync',           ['leverage_score' => 0.30]),
        ];

        $result = $this->compiler()->compile($outcomes);

        $specIds = array_column($result['high_leverage_specs'], 'spec_id');
        $this->assertContains('t-high', $specIds);
        $this->assertNotContains('t-below', $specIds);
    }

    public function test_wasted_specs_includes_proxy_smells(): void
    {
        $outcomes = [
            $this->outcome('t-smell', 'proxy_smell', 'docs_sync'),
            $this->outcome('t-ok',    'success',     'bug_fix'),
        ];

        $result = $this->compiler()->compile($outcomes);

        $wastedIds = array_column($result['wasted_specs'], 'spec_id');
        $this->assertContains('t-smell', $wastedIds);
        $this->assertNotContains('t-ok', $wastedIds);
    }

    public function test_bad_prompt_reason_generates_lesson_and_policy(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'rejected', 'architecture_unlock', ['reason' => 'contradictory_acceptance']),
            $this->outcome('t-2', 'success',  'bug_fix'),
        ];

        $result = $this->compiler()->compile($outcomes);

        $lessonsText = implode(' ', $result['lessons']);
        $this->assertStringContainsString('contradictory_acceptance', $lessonsText);

        $policyActions = array_column($result['policy_adjustments'], 'action');
        $this->assertContains('repair_prompt', $policyActions);
    }

    public function test_honest_give_back_does_not_trigger_bad_prompt_lesson(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'give_back', 'bug_fix', ['reason' => 'scope_too_large']),
            $this->outcome('t-2', 'give_back', 'bug_fix', ['reason' => 'insufficient_context']),
            $this->outcome('t-3', 'success',   'bug_fix'),
            $this->outcome('t-4', 'success',   'bug_fix'),
        ];

        $result = $this->compiler()->compile($outcomes);

        $lessonsText = implode(' ', $result['lessons']);
        $this->assertStringContainsString('Honest low-yield', $lessonsText);
        $this->assertStringNotContainsString('spec authoring failure', $lessonsText);
    }

    public function test_high_yield_category_generates_prefer_policy_and_hint(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success', 'architecture_unlock'),
            $this->outcome('t-2', 'success', 'architecture_unlock'),
            $this->outcome('t-3', 'success', 'architecture_unlock'),
        ];

        $result = $this->compiler()->compile($outcomes);

        $preferPolicies = array_filter($result['policy_adjustments'], fn (array $p): bool => $p['action'] === 'prefer');
        $this->assertNotEmpty($preferPolicies);
        $this->assertSame('category:architecture_unlock', array_values($preferPolicies)[0]['applies_to']);

        $hintFacts = array_column($result['next_cycle_hints'], 'fact');
        $combined  = implode(' ', $hintFacts);
        $this->assertStringContainsString('architecture_unlock', $combined);
    }

    public function test_low_yield_category_generates_avoid_policy(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'give_back', 'docs_sync', ['reason' => 'scope_too_large']),
            $this->outcome('t-2', 'give_back', 'docs_sync', ['reason' => 'insufficient_context']),
            $this->outcome('t-3', 'rejected',  'docs_sync', ['reason' => 'scope_too_large']),
            $this->outcome('t-4', 'success',   'docs_sync'),
        ];

        // docs_sync: 3 fail / 4 total = 75% fail rate > 60%
        $result = $this->compiler()->compile($outcomes);

        $avoidPolicies = array_filter($result['policy_adjustments'], fn (array $p): bool => $p['action'] === 'avoid');
        $avoidTargets  = array_column(array_values($avoidPolicies), 'applies_to');
        $this->assertContains('category:docs_sync', $avoidTargets);
    }

    public function test_next_cycle_hints_are_memory_writeback_compatible(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success',   'architecture_unlock'),
            $this->outcome('t-2', 'success',   'architecture_unlock'),
            $this->outcome('t-3', 'give_back', 'docs_sync', ['reason' => 'scope_too_large']),
            $this->outcome('t-4', 'give_back', 'docs_sync', ['reason' => 'scope_too_large']),
            $this->outcome('t-5', 'rejected',  'docs_sync', ['reason' => 'scope_too_large']),
        ];

        $result = $this->compiler()->compile($outcomes);

        $allowedTypes = ['next_cycle_hint', 'failed_pattern', 'task_family_yield'];
        foreach ($result['next_cycle_hints'] as $hint) {
            $this->assertArrayHasKey('type',             $hint);
            $this->assertArrayHasKey('fact',             $hint);
            $this->assertArrayHasKey('evidence_strength', $hint);
            $this->assertContains($hint['type'], $allowedTypes);
            $this->assertContains($hint['evidence_strength'], ['proven', 'observed', 'inferred']);
        }
    }

    public function test_empty_run_returns_valid_structure(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(0, $result['summary']['total_outcomes']);
        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SIGNAL_HONEST, $result['integrity_signal']);
        $this->assertIsArray($result['lessons']);
        $this->assertIsArray($result['policy_adjustments']);
        $this->assertIsArray($result['next_cycle_hints']);
    }

    public function test_wasted_token_ratio_reflects_failed_outcomes(): void
    {
        $outcomes = [
            $this->outcome('t-1', 'success',   'bug_fix', ['tokens_spent' => 1000]),
            $this->outcome('t-2', 'give_back', 'bug_fix', ['tokens_spent' => 2000]),
            $this->outcome('t-3', 'rejected',  'bug_fix', ['tokens_spent' => 1000]),
        ];

        // 3000 tokens wasted / 4000 total = 0.75
        $result = $this->compiler()->compile($outcomes);

        $this->assertEqualsWithDelta(0.75, $result['summary']['wasted_token_ratio'], 0.001);
    }

    // ── AC1: root_cause_map — five canonical buckets ──────────────────────────

    public function test_root_cause_map_has_five_canonical_buckets(): void
    {
        $result = $this->compiler()->compile([]);

        $rcm = $result['root_cause_map'];
        foreach (['bad_prompt', 'duplicate_target', 'weak_evidence', 'template_farm', 'worker_mismatch'] as $bucket) {
            $this->assertArrayHasKey($bucket, $rcm);
        }
    }

    public function test_bad_prompt_reason_is_classified_under_bad_prompt(): void
    {
        $outcomes = [
            $this->outcome('s1', 'rejected', 'arch', ['reason' => 'contradictory_acceptance']),
            $this->outcome('s2', 'rejected', 'arch', ['reason' => 'missing_impl_file']),
        ];
        $result = $this->compiler()->compile($outcomes);

        $this->assertCount(2, $result['root_cause_map']['bad_prompt']);
        $specIds = array_column($result['root_cause_map']['bad_prompt'], 'spec_id');
        $this->assertContains('s1', $specIds);
        $this->assertContains('s2', $specIds);
    }

    public function test_proxy_smell_is_classified_under_template_farm(): void
    {
        $result = $this->compiler()->compile([
            $this->outcome('p1', 'proxy_smell', 'docs_sync'),
        ]);

        $this->assertCount(1, $result['root_cause_map']['template_farm']);
        $this->assertSame('p1', $result['root_cause_map']['template_farm'][0]['spec_id']);
    }

    public function test_scope_too_large_is_classified_under_worker_mismatch(): void
    {
        $result = $this->compiler()->compile([
            $this->outcome('w1', 'give_back', 'arch', ['reason' => 'scope_too_large']),
        ]);

        $this->assertCount(1, $result['root_cause_map']['worker_mismatch']);
    }

    public function test_insufficient_context_is_classified_under_weak_evidence(): void
    {
        $result = $this->compiler()->compile([
            $this->outcome('e1', 'give_back', 'arch', ['reason' => 'insufficient_context']),
        ]);

        $this->assertCount(1, $result['root_cause_map']['weak_evidence']);
    }

    public function test_success_outcomes_do_not_appear_in_root_cause_map(): void
    {
        $result = $this->compiler()->compile([
            $this->outcome('ok1', 'success', 'arch'),
            $this->outcome('ok2', 'success', 'bug_fix'),
        ]);

        foreach ($result['root_cause_map'] as $bucket => $entries) {
            $this->assertSame([], $entries, "Bucket '{$bucket}' must be empty for all-success run");
        }
    }

    // ── AC2: measurable_acceptance_target on recurring root causes ────────────

    public function test_recurring_bad_prompt_root_cause_adds_measurable_acceptance_target(): void
    {
        $outcomes = [
            $this->outcome('s1', 'rejected', 'arch', ['reason' => 'contradictory_acceptance']),
            $this->outcome('s2', 'rejected', 'arch', ['reason' => 'missing_impl_file']),
        ];
        $result = $this->compiler()->compile($outcomes);

        $repairPolicies = array_filter($result['policy_adjustments'], fn(array $p): bool => $p['action'] === 'repair_prompt');
        foreach ($repairPolicies as $policy) {
            $this->assertArrayHasKey('measurable_acceptance_target', $policy);
            $this->assertStringContainsString('bad_prompt', $policy['measurable_acceptance_target']);
        }
    }

    public function test_single_occurrence_bad_prompt_has_no_measurable_acceptance_target(): void
    {
        $outcomes = [
            $this->outcome('s1', 'rejected', 'arch', ['reason' => 'contradictory_acceptance']),
            $this->outcome('s2', 'success',  'arch'),
        ];
        $result = $this->compiler()->compile($outcomes);

        $repairPolicies = array_filter($result['policy_adjustments'], fn(array $p): bool => $p['action'] === 'repair_prompt');
        foreach ($repairPolicies as $policy) {
            $this->assertArrayNotHasKey('measurable_acceptance_target', $policy);
        }
    }
}
