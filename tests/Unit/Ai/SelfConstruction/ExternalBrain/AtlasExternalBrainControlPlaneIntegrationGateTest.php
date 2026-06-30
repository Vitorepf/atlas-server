<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneIntegrationGate;
use Tests\TestCase;

final class AtlasExternalBrainControlPlaneIntegrationGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainControlPlaneIntegrationGate
    {
        return new AtlasExternalBrainControlPlaneIntegrationGate();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->gate()->evaluate(['organ_id' => 'test-organ']);

        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate()->evaluate(['organ_id' => 'test-organ']);

        foreach (['schema', 'organ_id', 'count_as_delivered', 'exposure_path', 'reasons'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_organ_id_echoed_in_output(): void
    {
        $result = $this->gate()->evaluate(['organ_id' => 'my-organ-123']);

        $this->assertSame('my-organ-123', $result['organ_id']);
    }

    // ── rule 1: not important → free pass ────────────────────────────────────

    public function test_non_important_organ_always_counts_as_delivered(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'    => 'helper',
            'is_important' => false,
        ]);

        $this->assertTrue($result['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_NOT_IMPORTANT, $result['exposure_path']);
    }

    public function test_non_important_organ_delivers_even_with_no_exposure(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'utility-organ',
            'is_important'           => false,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => false,
        ]);

        $this->assertTrue($result['count_as_delivered']);
    }

    public function test_default_is_important_false_so_delivers(): void
    {
        // When is_important is missing it defaults to false → delivers.
        $result = $this->gate()->evaluate(['organ_id' => 'anything']);

        $this->assertTrue($result['count_as_delivered']);
    }

    // ── rule 2: control plane exposure ────────────────────────────────────────

    public function test_important_organ_with_control_plane_exposure_delivers(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'core-organ',
            'is_important'           => true,
            'control_plane_exposure' => true,
        ]);

        $this->assertTrue($result['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_CONTROL_PLANE, $result['exposure_path']);
    }

    public function test_control_plane_path_includes_consumer_links_in_reasons(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'core-organ',
            'is_important'           => true,
            'control_plane_exposure' => true,
            'consumer_links'         => ['originator', 'maestro'],
        ]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('originator', $reasonStr);
    }

    // ── rule 3: readiness map exposure ───────────────────────────────────────

    public function test_important_organ_with_readiness_map_exposure_delivers(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'core-organ',
            'is_important'           => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => true,
        ]);

        $this->assertTrue($result['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_READINESS_MAP, $result['exposure_path']);
    }

    // ── rule 4: standalone exception ─────────────────────────────────────────

    public function test_complete_standalone_exception_with_all_four_fields_delivers(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'                    => 'standalone-organ',
            'is_important'                => true,
            'control_plane_exposure'      => false,
            'readiness_map_exposure'      => false,
            'standalone_reason'           => 'this organ has no consumer yet but is architecturally sound',
            'evidence_floor'              => 'at_least_5_green_tests',
            'consumer_links'              => ['AtlasBrainOriginator'],
            'expiry_or_review_condition'  => 'review_after_2026-09-01_or_when_wired',
        ]);

        $this->assertTrue($result['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_STANDALONE, $result['exposure_path']);
    }

    public function test_standalone_reason_present_but_evidence_floor_missing_does_not_deliver(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'half-standalone',
            'is_important'           => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => false,
            'standalone_reason'      => 'has an architectural reason',
            'evidence_floor'         => '',
        ]);

        $this->assertFalse($result['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_NONE, $result['exposure_path']);
    }

    public function test_evidence_floor_present_but_standalone_reason_missing_does_not_deliver(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'half-standalone',
            'is_important'           => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => false,
            'standalone_reason'      => '',
            'evidence_floor'         => 'at_least_3_green_tests',
        ]);

        $this->assertFalse($result['count_as_delivered']);
    }

    // ── rule 5: important, unexposed, no standalone ────────────────────────────

    public function test_important_unexposed_organ_without_standalone_does_not_deliver(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'blocked-organ',
            'is_important'           => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => false,
        ]);

        $this->assertFalse($result['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_NONE, $result['exposure_path']);
    }

    public function test_blocked_organ_reasons_mention_missing_exposure(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'     => 'blocked-organ',
            'is_important' => true,
        ]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('no_control_plane_exposure', $reasonStr);
    }

    public function test_blocked_organ_reasons_mention_standalone_required(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'     => 'blocked-organ',
            'is_important' => true,
        ]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('standalone_exception_missing', $reasonStr);
        $this->assertStringContainsString('all_four_fields_required', $reasonStr);
    }

    public function test_standalone_missing_consumer_links_does_not_deliver(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'                   => 'half-standalone',
            'is_important'               => true,
            'control_plane_exposure'     => false,
            'readiness_map_exposure'     => false,
            'standalone_reason'          => 'justified reason',
            'evidence_floor'             => 'at_least_5_green_tests',
            'consumer_links'             => [],
            'expiry_or_review_condition' => 'review_2026-09-01',
        ]);

        $this->assertFalse($result['count_as_delivered']);
        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('missing_consumer_links', $reasonStr);
    }

    public function test_standalone_missing_expiry_does_not_deliver(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'                   => 'half-standalone',
            'is_important'               => true,
            'control_plane_exposure'     => false,
            'readiness_map_exposure'     => false,
            'standalone_reason'          => 'justified reason',
            'evidence_floor'             => 'at_least_5_green_tests',
            'consumer_links'             => ['AtlasBrainOriginator'],
            'expiry_or_review_condition' => '',
        ]);

        $this->assertFalse($result['count_as_delivered']);
        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('missing_expiry_or_review_condition', $reasonStr);
    }

    public function test_standalone_reasons_list_all_missing_fields(): void
    {
        // Provide only standalone_reason; other 3 missing.
        $result = $this->gate()->evaluate([
            'organ_id'          => 'partial-standalone',
            'is_important'      => true,
            'standalone_reason' => 'some reason',
        ]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('missing_evidence_floor', $reasonStr);
        $this->assertStringContainsString('missing_consumer_links', $reasonStr);
        $this->assertStringContainsString('missing_expiry_or_review_condition', $reasonStr);
    }

    // ── exposure path priority ────────────────────────────────────────────────

    public function test_control_plane_takes_priority_over_readiness_map(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'exposed-everywhere',
            'is_important'           => true,
            'control_plane_exposure' => true,
            'readiness_map_exposure' => true,
        ]);

        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_CONTROL_PLANE, $result['exposure_path']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $organ = [
            'organ_id'               => 'my-organ',
            'is_important'           => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => false,
        ];

        $this->assertSame(
            $this->gate()->evaluate($organ),
            $this->gate()->evaluate($organ),
        );
    }
}
