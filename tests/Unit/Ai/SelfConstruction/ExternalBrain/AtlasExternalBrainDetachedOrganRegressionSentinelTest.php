<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDetachedOrganRegressionSentinel;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDetachedOrganRegressionSentinelTest extends TestCase
{
    private function svc(): AtlasExternalBrainDetachedOrganRegressionSentinel
    {
        return new AtlasExternalBrainDetachedOrganRegressionSentinel;
    }

    // ── standalone-only batches are flagged ───────────────────────────────────

    public function test_batch_with_only_detector_organs_is_flagged_detached_organ_risk(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
            ['organ_id' => 'BarDetector', 'kind' => 'detector'],
        ]]);

        $this->assertTrue($result['detached_organ_risk']);
        $this->assertContains('FooDetector', $result['flagged_organs']);
        $this->assertContains('BarDetector', $result['flagged_organs']);
    }

    public function test_batch_with_only_report_organs_is_flagged_detached_organ_risk(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'HealthReport', 'kind' => 'report'],
        ]]);

        $this->assertTrue($result['detached_organ_risk']);
    }

    public function test_mixed_detector_and_report_with_no_consumer_is_flagged(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
            ['organ_id' => 'HealthReport', 'kind' => 'report'],
        ]]);

        $this->assertTrue($result['detached_organ_risk']);
    }

    public function test_unrecognised_kind_alone_is_flagged_as_standalone_risk(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'MysteryOrgan', 'kind' => 'future_unknown_kind'],
        ]]);

        $this->assertTrue($result['detached_organ_risk']);
    }

    // ── batch with a real consumer is not flagged ─────────────────────────────

    public function test_batch_with_a_bridge_consumer_is_not_flagged(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
            ['organ_id' => 'FooBridge', 'kind' => 'bridge'],
        ]]);

        $this->assertFalse($result['detached_organ_risk']);
        $this->assertSame([], $result['flagged_organs']);
    }

    public function test_batch_with_a_control_plane_consumer_is_not_flagged(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
            ['organ_id' => 'FooControlPlane', 'kind' => 'control_plane'],
        ]]);

        $this->assertFalse($result['detached_organ_risk']);
    }

    public function test_batch_with_a_readiness_consumer_is_not_flagged(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooReport', 'kind' => 'report'],
            ['organ_id' => 'FooReadiness', 'kind' => 'readiness'],
        ]]);

        $this->assertFalse($result['detached_organ_risk']);
    }

    public function test_batch_with_an_outcome_learning_consumer_is_not_flagged(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
            ['organ_id' => 'FooOutcomeLearner', 'kind' => 'outcome_learning'],
        ]]);

        $this->assertFalse($result['detached_organ_risk']);
    }

    // ── repair guidance names the missing consumer type ───────────────────────

    public function test_repair_guidance_names_the_missing_consumer_types(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
        ]]);

        $this->assertNotNull($result['repair_guidance']);
        foreach (AtlasExternalBrainDetachedOrganRegressionSentinel::CONSUMER_KINDS as $kind) {
            $this->assertStringContainsString($kind, $result['repair_guidance']);
        }
        $this->assertSame(AtlasExternalBrainDetachedOrganRegressionSentinel::CONSUMER_KINDS, $result['missing_consumer_types']);
    }

    public function test_repair_guidance_is_null_when_not_flagged(): void
    {
        $result = $this->svc()->scan(['organs' => [
            ['organ_id' => 'FooBridge', 'kind' => 'bridge'],
        ]]);

        $this->assertNull($result['repair_guidance']);
        $this->assertSame([], $result['missing_consumer_types']);
    }

    // ── empty batch ────────────────────────────────────────────────────────────

    public function test_empty_batch_is_vacuously_safe(): void
    {
        $result = $this->svc()->scan(['organs' => []]);

        $this->assertFalse($result['detached_organ_risk']);
        $this->assertSame([], $result['flagged_organs']);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.detached_organ_regression_sentinel.v1', AtlasExternalBrainDetachedOrganRegressionSentinel::SCHEMA);
    }

    public function test_result_is_deterministic(): void
    {
        $svc = $this->svc();
        $batch = ['organs' => [
            ['organ_id' => 'FooDetector', 'kind' => 'detector'],
            ['organ_id' => 'BarReport', 'kind' => 'report'],
        ]];

        $this->assertSame(json_encode($svc->scan($batch)), json_encode($svc->scan($batch)));
    }
}
