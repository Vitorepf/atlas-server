<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBlindSpotCurriculum;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBlindSpotCurriculumTest extends TestCase
{
    private function svc(): AtlasExternalBrainBlindSpotCurriculum
    {
        return new AtlasExternalBrainBlindSpotCurriculum;
    }

    private function obs(string $type, string $runId, string $taskClass = 'default'): array
    {
        return ['failure_type' => $type, 'run_id' => $runId, 'task_class' => $taskClass];
    }

    private function build(array $observations): array
    {
        return $this->svc()->build(['failure_observations' => $observations]);
    }

    // ── promotion threshold ───────────────────────────────────────────────────

    public function test_single_observation_is_rejected(): void
    {
        $r = $this->build([$this->obs('proxy_risk', 'run-1', 'refactor')]);

        $this->assertSame([], $r['promoted_blind_spots']);
        $rejectedTypes = array_column($r['rejected_candidates'], 'type');
        $this->assertContains('proxy_risk', $rejectedTypes);
    }

    public function test_two_unique_runs_promotes_blind_spot(): void
    {
        $r = $this->build([
            $this->obs('proxy_risk', 'run-1'),
            $this->obs('proxy_risk', 'run-2'),
        ]);

        $promotedTypes = array_column($r['promoted_blind_spots'], 'type');
        $this->assertContains('proxy_risk', $promotedTypes);
    }

    public function test_two_unique_task_classes_promotes_blind_spot(): void
    {
        $r = $this->build([
            $this->obs('low_leverage', 'run-1', 'refactor'),
            $this->obs('low_leverage', 'run-1', 'feature'),  // same run, different class
        ]);

        $promotedTypes = array_column($r['promoted_blind_spots'], 'type');
        $this->assertContains('low_leverage', $promotedTypes);
    }

    public function test_same_run_and_same_task_class_twice_does_not_promote(): void
    {
        // Two identical observations (same run + same class) = 1 unique of each → rejected
        $r = $this->build([
            $this->obs('low_leverage', 'run-1', 'refactor'),
            $this->obs('low_leverage', 'run-1', 'refactor'),
        ]);

        $this->assertSame([], $r['promoted_blind_spots']);
    }

    // ── curriculum items ──────────────────────────────────────────────────────

    public function test_promoted_blind_spot_has_curriculum_item_with_required_keys(): void
    {
        $r = $this->build([
            $this->obs('proxy_risk', 'run-1'),
            $this->obs('proxy_risk', 'run-2'),
        ]);

        $this->assertCount(1, $r['curriculum_items']);
        $item = $r['curriculum_items'][0];
        $this->assertArrayHasKey('challenge_cases', $item);
        $this->assertArrayHasKey('runbook_reminders', $item);
        $this->assertArrayHasKey('preflight_checks', $item);
        $this->assertNotEmpty($item['challenge_cases']);
        $this->assertNotEmpty($item['runbook_reminders']);
        $this->assertNotEmpty($item['preflight_checks']);
    }

    public function test_known_type_uses_catalog_not_generic(): void
    {
        $r = $this->build([
            $this->obs('proxy_risk', 'run-1'),
            $this->obs('proxy_risk', 'run-2'),
        ]);

        $item = $r['curriculum_items'][0];
        // Catalog has 'objective_contains_no_proxy_keywords' in preflight_checks
        $this->assertContains('objective_contains_no_proxy_keywords', $item['preflight_checks']);
    }

    public function test_unknown_type_uses_generic_curriculum(): void
    {
        $r = $this->build([
            $this->obs('totally_new_failure', 'run-1'),
            $this->obs('totally_new_failure', 'run-2'),
        ]);

        $item = $r['curriculum_items'][0];
        $this->assertContains('manual_review_required_for_this_failure_type', $item['preflight_checks']);
    }

    // ── injection rules ───────────────────────────────────────────────────────

    public function test_promoted_blind_spot_has_injection_rule(): void
    {
        $r = $this->build([
            $this->obs('operator_dependency', 'run-1'),
            $this->obs('operator_dependency', 'run-2'),
        ]);

        $this->assertCount(1, $r['injection_rules']);
        $rule = $r['injection_rules'][0];
        $this->assertArrayHasKey('inject_before', $rule);
        $this->assertArrayHasKey('curriculum_type', $rule);
        $this->assertSame('operator_dependency', $rule['curriculum_type']);
    }

    // ── rejected candidates ───────────────────────────────────────────────────

    public function test_rejected_candidate_has_reason(): void
    {
        $r = $this->build([$this->obs('low_leverage', 'run-1')]);

        $candidate = $r['rejected_candidates'][0];
        $this->assertSame('below_threshold', $candidate['reason']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_observations_returns_all_empty(): void
    {
        $r = $this->svc()->build([]);

        $this->assertSame([], $r['promoted_blind_spots']);
        $this->assertSame([], $r['rejected_candidates']);
        $this->assertSame([], $r['curriculum_items']);
        $this->assertSame([], $r['injection_rules']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->build([]);

        $this->assertSame(AtlasExternalBrainBlindSpotCurriculum::SCHEMA, $r['schema_version']);
    }

    // ── preflight_contract ────────────────────────────────────────────────────

    public function test_output_has_preflight_contract_key(): void
    {
        $r = $this->svc()->build([]);

        $this->assertArrayHasKey('preflight_contract', $r);
        $this->assertArrayHasKey('checks', $r['preflight_contract']);
        $this->assertArrayHasKey('applied_types', $r['preflight_contract']);
    }

    public function test_preflight_contract_aggregates_checks_from_promoted_items(): void
    {
        $r = $this->build([
            $this->obs('proxy_risk', 'run-1'),
            $this->obs('proxy_risk', 'run-2'),
        ]);

        $checks = $r['preflight_contract']['checks'];
        $this->assertContains('objective_contains_no_proxy_keywords', $checks);
        $this->assertContains('acceptance_requires_behavior_test', $checks);
    }

    public function test_preflight_contract_is_empty_when_nothing_promoted(): void
    {
        $r = $this->build([$this->obs('proxy_risk', 'run-1')]);

        $this->assertSame([], $r['preflight_contract']['checks']);
        $this->assertSame([], $r['preflight_contract']['applied_types']);
    }

    public function test_preflight_contract_applied_types_matches_promoted(): void
    {
        $r = $this->build([
            $this->obs('operator_dependency', 'run-1'),
            $this->obs('operator_dependency', 'run-2'),
        ]);

        $this->assertContains('operator_dependency', $r['preflight_contract']['applied_types']);
    }

    public function test_preflight_contract_deduplicates_checks_across_types(): void
    {
        // Two types promoted — checks from both merged, no duplicates
        $r = $this->build([
            $this->obs('proxy_risk', 'run-1'),
            $this->obs('proxy_risk', 'run-2'),
            $this->obs('low_leverage', 'run-3'),
            $this->obs('low_leverage', 'run-4'),
        ]);

        $checks = $r['preflight_contract']['checks'];
        $this->assertSame(count($checks), count(array_unique($checks)));
    }

    // ── new catalog entries ───────────────────────────────────────────────────

    public function test_over_complexity_uses_catalog(): void
    {
        $r = $this->build([
            $this->obs('over_complexity', 'run-1'),
            $this->obs('over_complexity', 'run-2'),
        ]);

        $item = $r['curriculum_items'][0];
        $this->assertContains('no_speculative_abstractions', $item['preflight_checks']);
    }

    public function test_missed_dedup_uses_catalog(): void
    {
        $r = $this->build([
            $this->obs('missed_dedup', 'run-1'),
            $this->obs('missed_dedup', 'run-2'),
        ]);

        $item = $r['curriculum_items'][0];
        $this->assertContains('existing_utility_search_done', $item['preflight_checks']);
    }

    public function test_no_runnable_evidence_uses_catalog(): void
    {
        $r = $this->build([
            $this->obs('no_runnable_evidence', 'run-1'),
            $this->obs('no_runnable_evidence', 'run-2'),
        ]);

        $item = $r['curriculum_items'][0];
        $this->assertContains('all_acceptance_criteria_have_runnable_evidence_path', $item['preflight_checks']);
    }

    public function test_provider_dependency_uses_catalog(): void
    {
        $r = $this->build([
            $this->obs('provider_dependency', 'run-1'),
            $this->obs('provider_dependency', 'run-2'),
        ]);

        $item = $r['curriculum_items'][0];
        $this->assertContains('output_is_provider_safe', $item['preflight_checks']);
    }
}
