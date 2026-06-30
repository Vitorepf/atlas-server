<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBlindSpotCurriculum;
use Tests\TestCase;

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
}
