<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationQualityGovernor;
use Tests\TestCase;

final class AtlasExternalBrainQueueSaturationQualityGovernorTest extends TestCase
{
    private function svc(): AtlasExternalBrainQueueSaturationQualityGovernor
    {
        return new AtlasExternalBrainQueueSaturationQualityGovernor;
    }

    private function healthyShallowFacts(array $overrides = []): array
    {
        return array_merge([
            'servable_depth' => 2,
            'active_workers' => 3,
            'malformed_rate' => 0.0,
            'give_back_rate' => 0.0,
            'target_diversity' => 0.9,
        ], $overrides);
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts());

        foreach (['schema', 'decision', 'reason', 'required_next_evidence', 'max_new_tasks'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::SCHEMA, $r['schema']);
    }

    // ── AC1: deep healthy queue with low diversity → quality_review_or_pause ────

    public function test_deep_queue_with_low_diversity_recommends_quality_review_or_pause(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts([
            'servable_depth' => 30,
            'active_workers' => 3,
            'target_diversity' => 0.1,
        ]));

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_QUALITY_REVIEW_OR_PAUSE, $r['decision']);
        $this->assertSame(0, $r['max_new_tasks']);
    }

    public function test_deep_queue_with_high_diversity_does_not_recommend_pause(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts([
            'servable_depth' => 30,
            'active_workers' => 3,
            'target_diversity' => 0.9,
        ]));

        $this->assertNotSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_QUALITY_REVIEW_OR_PAUSE, $r['decision']);
    }

    // ── AC2: shallow queue, low malformed, low give_back → create_high_value_batch ──

    public function test_shallow_healthy_queue_recommends_create_high_value_batch(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts());

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_CREATE_HIGH_VALUE_BATCH, $r['decision']);
        $this->assertGreaterThan(0, $r['max_new_tasks']);
    }

    // ── AC3: high give_back or malformed rate → repair_specs_before_creation ───

    public function test_high_malformed_rate_recommends_repair_specs_before_creation(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts(['malformed_rate' => 0.5]));

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $r['decision']);
        $this->assertSame(0, $r['max_new_tasks']);
    }

    public function test_high_give_back_rate_recommends_repair_specs_before_creation(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts(['give_back_rate' => 0.4]));

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $r['decision']);
    }

    public function test_sick_queue_overrides_deep_low_diversity_signal(): void
    {
        // Both sickness AND deep+low-diversity are present — sickness must win (highest priority).
        $r = $this->svc()->decide($this->healthyShallowFacts([
            'servable_depth' => 30,
            'active_workers' => 3,
            'target_diversity' => 0.1,
            'malformed_rate' => 0.5,
        ]));

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $r['decision']);
    }

    public function test_low_malformed_and_give_back_do_not_trigger_repair(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts(['malformed_rate' => 0.05, 'give_back_rate' => 0.1]));

        $this->assertNotSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $r['decision']);
    }

    // ── required_next_evidence varies per decision ──────────────────────────────

    public function test_repair_decision_required_evidence_names_rate_thresholds(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts(['malformed_rate' => 0.5]));

        $this->assertContains('malformed_rate_below_threshold', $r['required_next_evidence']);
    }

    public function test_pause_decision_required_evidence_names_diversity(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts([
            'servable_depth' => 40,
            'active_workers' => 2,
            'target_diversity' => 0.05,
        ]));

        $this->assertContains('target_diversity_above_threshold', $r['required_next_evidence']);
    }

    // ── max_new_tasks sizing ──────────────────────────────────────────────────

    public function test_max_new_tasks_is_zero_for_pause_and_repair_decisions(): void
    {
        $repair = $this->svc()->decide($this->healthyShallowFacts(['malformed_rate' => 0.9]));
        $pause = $this->svc()->decide($this->healthyShallowFacts(['servable_depth' => 50, 'active_workers' => 2, 'target_diversity' => 0.0]));

        $this->assertSame(0, $repair['max_new_tasks']);
        $this->assertSame(0, $pause['max_new_tasks']);
    }

    public function test_max_new_tasks_is_capped(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts(['servable_depth' => 0, 'active_workers' => 100]));

        $this->assertLessThanOrEqual(10, $r['max_new_tasks']);
    }

    public function test_max_new_tasks_is_at_least_one_when_creating(): void
    {
        $r = $this->svc()->decide($this->healthyShallowFacts());

        $this->assertGreaterThanOrEqual(1, $r['max_new_tasks']);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_zero_active_workers_with_zero_depth_does_not_error(): void
    {
        $r = $this->svc()->decide([
            'servable_depth' => 0,
            'active_workers' => 0,
            'malformed_rate' => 0.0,
            'give_back_rate' => 0.0,
            'target_diversity' => 1.0,
        ]);

        $this->assertIsString($r['decision']);
    }

    public function test_negative_inputs_are_clamped(): void
    {
        $r = $this->svc()->decide([
            'servable_depth' => -5,
            'active_workers' => -2,
            'malformed_rate' => -0.5,
            'give_back_rate' => -0.5,
            'target_diversity' => -0.5,
        ]);

        $this->assertIsString($r['decision']);
        $this->assertGreaterThanOrEqual(0, $r['max_new_tasks']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_decide_is_deterministic(): void
    {
        $facts = $this->healthyShallowFacts();
        $a = $this->svc()->decide($facts);
        $b = $this->svc()->decide($facts);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }
}
