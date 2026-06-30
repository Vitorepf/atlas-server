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

        foreach (['schema', 'organ_id', 'count_as_delivered', 'exposure_path', 'reasons', 'integration_blockers'] as $k) {
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

    // ── integration_blockers (AC1) ────────────────────────────────────────────

    public function test_integration_blockers_empty_when_delivered_via_control_plane(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'wired-organ',
            'is_important'           => true,
            'control_plane_exposure' => true,
        ]);

        $this->assertSame([], $result['integration_blockers']);
    }

    public function test_integration_blockers_contains_exposure_codes_when_fully_blocked(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'     => 'blocked-organ',
            'is_important' => true,
        ]);

        $this->assertContains('no_control_plane_exposure', $result['integration_blockers']);
        $this->assertContains('no_readiness_map_exposure', $result['integration_blockers']);
        $this->assertContains('missing_standalone_reason', $result['integration_blockers']);
        $this->assertContains('missing_evidence_floor',    $result['integration_blockers']);
        $this->assertContains('missing_consumer_links',    $result['integration_blockers']);
    }

    public function test_integration_blockers_lists_only_missing_standalone_fields_for_partial_standalone(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'               => 'partial-standalone',
            'is_important'           => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => false,
            'standalone_reason'      => 'valid reason',
            'evidence_floor'         => 'at_least_3_tests',
            'consumer_links'         => ['AtlasBrainOriginator'],
            // missing expiry_or_review_condition only
        ]);

        $this->assertFalse($result['count_as_delivered']);
        $this->assertContains('missing_expiry_or_review_condition', $result['integration_blockers']);
        $this->assertNotContains('missing_standalone_reason', $result['integration_blockers']);
        $this->assertNotContains('missing_evidence_floor',    $result['integration_blockers']);
        $this->assertNotContains('missing_consumer_links',    $result['integration_blockers']);
    }

    public function test_integration_blockers_empty_when_standalone_complete(): void
    {
        $result = $this->gate()->evaluate([
            'organ_id'                   => 'standalone-organ',
            'is_important'               => true,
            'standalone_reason'          => 'no consumer yet but architecturally sound',
            'evidence_floor'             => 'at_least_5_green_tests',
            'consumer_links'             => ['AtlasBrainOriginator'],
            'expiry_or_review_condition' => 'review_after_2026-09-01',
        ]);

        $this->assertSame([], $result['integration_blockers']);
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

    // ── batch evaluation ─────────────────────────────────────────────────────────

    public function test_evaluate_batch_returns_required_keys(): void
    {
        $result = $this->gate()->evaluateBatch([]);

        foreach (['schema', 'total_organs', 'integration_coverage_percent', 'delivered_organs', 'blocked_organs', 'integration_blockers_by_organ'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
    }

    public function test_evaluate_batch_coverage_percent_reflects_delivered_ratio(): void
    {
        $organs = [
            ['organ_id' => 'wired-1', 'is_important' => true, 'control_plane_exposure' => true],
            ['organ_id' => 'unwired-1', 'is_important' => true, 'control_plane_exposure' => false, 'readiness_map_exposure' => false],
        ];

        $result = $this->gate()->evaluateBatch($organs);

        $this->assertSame(2, $result['total_organs']);
        $this->assertEqualsWithDelta(50.0, $result['integration_coverage_percent'], 0.01);
        $this->assertSame(['wired-1'], $result['delivered_organs']);
        $this->assertSame(['unwired-1'], $result['blocked_organs']);
    }

    public function test_evaluate_batch_full_coverage_is_100_percent(): void
    {
        $organs = [
            ['organ_id' => 'a', 'is_important' => true, 'control_plane_exposure' => true],
            ['organ_id' => 'b', 'is_important' => false],
        ];

        $result = $this->gate()->evaluateBatch($organs);

        $this->assertEqualsWithDelta(100.0, $result['integration_coverage_percent'], 0.01);
        $this->assertSame([], $result['blocked_organs']);
    }

    public function test_evaluate_batch_empty_input_has_zero_total_and_zero_coverage(): void
    {
        $result = $this->gate()->evaluateBatch([]);

        $this->assertSame(0, $result['total_organs']);
        $this->assertSame(0.0, $result['integration_coverage_percent']);
    }

    // ── AC3: read-only helper cannot count as final capability without control-plane exposure ──

    public function test_evaluate_batch_blocks_read_only_helper_without_control_plane_exposure(): void
    {
        $organs = [
            [
                'organ_id'                => 'helper-1',
                'is_important'             => true,
                'is_read_only_helper'      => true,
                'control_plane_exposure'   => false,
                'readiness_map_exposure'   => false,
            ],
        ];

        $result = $this->gate()->evaluateBatch($organs);

        $this->assertContains('helper-1', $result['blocked_organs']);
        $this->assertArrayNotHasKey('helper-1', array_flip($result['delivered_organs']));
        $this->assertArrayHasKey('helper-1', $result['integration_blockers_by_organ']);
        $this->assertContains('read_only_helper_requires_control_plane_exposure', $result['integration_blockers_by_organ']['helper-1']);
    }

    public function test_evaluate_batch_admits_read_only_helper_with_control_plane_exposure(): void
    {
        $organs = [
            [
                'organ_id'                => 'helper-2',
                'is_important'             => true,
                'is_read_only_helper'      => true,
                'control_plane_exposure'   => true,
            ],
        ];

        $result = $this->gate()->evaluateBatch($organs);

        $this->assertContains('helper-2', $result['delivered_organs']);
    }

    // ── AC4: standalone exception requires all four fields ──────────────────────

    public function test_evaluate_batch_blocks_standalone_exception_missing_fields(): void
    {
        $organs = [
            [
                'organ_id'                => 'standalone-incomplete',
                'is_important'             => true,
                'control_plane_exposure'   => false,
                'readiness_map_exposure'   => false,
                'standalone_reason'        => 'pure utility, no control-plane decision affected',
                // evidence_floor, consumer_links, expiry_or_review_condition all missing
            ],
        ];

        $result = $this->gate()->evaluateBatch($organs);

        $this->assertContains('standalone-incomplete', $result['blocked_organs']);
        $this->assertContains('missing_evidence_floor', $result['integration_blockers_by_organ']['standalone-incomplete']);
        $this->assertContains('missing_consumer_links', $result['integration_blockers_by_organ']['standalone-incomplete']);
        $this->assertContains('missing_expiry_or_review_condition', $result['integration_blockers_by_organ']['standalone-incomplete']);
    }

    public function test_evaluate_batch_admits_standalone_exception_with_all_four_fields(): void
    {
        $organs = [
            [
                'organ_id'                   => 'standalone-complete',
                'is_important'                => true,
                'control_plane_exposure'      => false,
                'readiness_map_exposure'      => false,
                'standalone_reason'           => 'governed read-only audit tool',
                'evidence_floor'              => 'tests_or_gates_result',
                'consumer_links'              => ['atlas:engineering-knowledge-sync'],
                'expiry_or_review_condition'  => 'review_after_90_days',
            ],
        ];

        $result = $this->gate()->evaluateBatch($organs);

        $this->assertContains('standalone-complete', $result['delivered_organs']);
        $this->assertArrayNotHasKey('standalone-complete', $result['integration_blockers_by_organ']);
    }

    public function test_evaluate_batch_is_deterministic(): void
    {
        $organs = [
            ['organ_id' => 'a', 'is_important' => true, 'control_plane_exposure' => true],
            ['organ_id' => 'b', 'is_important' => true, 'control_plane_exposure' => false, 'readiness_map_exposure' => false],
        ];

        $a = $this->gate()->evaluateBatch($organs);
        $b = $this->gate()->evaluateBatch($organs);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
