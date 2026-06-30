<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompoundingOutcomeRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompoundingOutcomeRouterTest extends TestCase
{
    private AtlasExternalBrainCompoundingOutcomeRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasExternalBrainCompoundingOutcomeRouter;
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->router->route([]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::SCHEMA, $r['schema_version']);
        foreach (['bucket', 'compounding_score', 'classification_reason', 'requires_downstream_proof', 'has_downstream_proof', 'recommended_followup', 'risk_reduction_credit'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    // ── Regression bucket ─────────────────────────────────────────────────────

    public function test_regression_detected_flag_routes_to_regression(): void
    {
        $r = $this->router->route(['regression_detected' => true]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_REGRESSION, $r['bucket']);
        $this->assertSame(0.0, $r['compounding_score']);
        $this->assertSame('revert_and_investigate', $r['recommended_followup']);
    }

    public function test_outcome_type_regression_routes_to_regression(): void
    {
        $r = $this->router->route(['outcome_type' => 'regression']);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_REGRESSION, $r['bucket']);
    }

    public function test_regression_beats_maintenance_type(): void
    {
        $r = $this->router->route(['outcome_type' => 'doc_update', 'regression_detected' => true]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_REGRESSION, $r['bucket']);
    }

    // ── Maintenance bucket ────────────────────────────────────────────────────

    public function test_doc_update_routes_to_maintenance(): void
    {
        $r = $this->router->route(['outcome_type' => 'doc_update']);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_MAINTENANCE, $r['bucket']);
    }

    public function test_formatting_routes_to_maintenance(): void
    {
        $r = $this->router->route(['outcome_type' => 'formatting']);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_MAINTENANCE, $r['bucket']);
    }

    public function test_maintenance_recommended_followup_is_none(): void
    {
        $r = $this->router->route(['outcome_type' => 'doc_update']);

        $this->assertSame('none_required', $r['recommended_followup']);
    }

    // ── Proxy bucket (cosmetic) ───────────────────────────────────────────────

    public function test_cosmetic_flag_routes_to_proxy(): void
    {
        $r = $this->router->route([
            'outcome_type'        => 'new_service',
            'is_cosmetic'         => true,
            'wired_callers_count' => 5,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_PROXY, $r['bucket']);
        $this->assertSame('cosmetic_outcome', $r['classification_reason']);
    }

    public function test_cosmetic_wrapper_type_routes_to_proxy(): void
    {
        $r = $this->router->route(['outcome_type' => 'cosmetic_wrapper']);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_PROXY, $r['bucket']);
    }

    public function test_naming_refactor_routes_to_proxy(): void
    {
        $r = $this->router->route(['outcome_type' => 'naming_refactor']);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_PROXY, $r['bucket']);
    }

    public function test_proxy_has_zero_risk_reduction_credit(): void
    {
        $r = $this->router->route(['outcome_type' => 'style_fix']);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_PROXY, $r['bucket']);
        $this->assertSame(0.0, $r['risk_reduction_credit']);
        $this->assertSame('eliminate_proxy_or_prove_impact', $r['recommended_followup']);
    }

    // ── AC3: low_compounding classification ───────────────────────────────────

    public function test_schema_change_without_wired_callers_is_low_compounding(): void
    {
        $r = $this->router->route([
            'outcome_type'        => 'schema_change',
            'wired_callers_count' => 0,
            'evidence_refs'       => ['phpunit:t1'],
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_LOW, $r['bucket']);
        $this->assertSame('schema_only_no_wired_callers', $r['classification_reason']);
        $this->assertTrue($r['requires_downstream_proof']);
    }

    public function test_unit_tests_only_without_downstream_proof_is_low_compounding(): void
    {
        $r = $this->router->route([
            'outcome_type'  => 'new_service',
            'evidence_refs' => ['phpunit:t1', 'phpunit:t2'],
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_LOW, $r['bucket']);
        $this->assertSame('no_downstream_evidence_or_wired_callers', $r['classification_reason']);
        $this->assertTrue($r['requires_downstream_proof']);
        $this->assertSame('add_downstream_proof', $r['recommended_followup']);
    }

    // ── AC2: compounding requires explicit downstream evidence ────────────────

    public function test_integration_evidence_enables_unlocks_new_capability(): void
    {
        $r = $this->router->route([
            'outcome_type'        => 'new_service',
            'evidence_refs'       => ['integration:e2e_suite_green'],
            'wired_callers_count' => 2,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_UNLOCKS_NEW, $r['bucket']);
        $this->assertTrue($r['has_downstream_proof']);
        $this->assertFalse($r['requires_downstream_proof']);
        $this->assertSame('wire_downstream_consumers', $r['recommended_followup']);
    }

    public function test_wired_callers_alone_enable_compounds_existing(): void
    {
        $r = $this->router->route([
            'outcome_type'        => 'refactor',
            'evidence_refs'       => ['phpunit:t1'],
            'wired_callers_count' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_COMPOUNDS, $r['bucket']);
        $this->assertTrue($r['has_downstream_proof']);
        $this->assertSame('measure_compounding_impact', $r['recommended_followup']);
    }

    public function test_downstream_services_enabled_satisfies_downstream_proof(): void
    {
        $r = $this->router->route([
            'outcome_type'                => 'new_organ',
            'downstream_services_enabled' => 2,
            'wired_callers_count'         => 0,
            'evidence_refs'               => ['phpunit:t1'],
        ]);

        $this->assertTrue($r['has_downstream_proof']);
    }

    public function test_new_capability_with_wired_callers_unlocks_new_capability(): void
    {
        $r = $this->router->route([
            'outcome_type'        => 'new_capability',
            'evidence_refs'       => ['integration:smoke'],
            'wired_callers_count' => 4,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_UNLOCKS_NEW, $r['bucket']);
        $this->assertGreaterThan(0.8, $r['compounding_score']);
    }

    public function test_compounds_existing_when_downstream_proof_but_not_new_capability(): void
    {
        $r = $this->router->route([
            'outcome_type'        => 'enhancement',
            'evidence_refs'       => ['wired_callers:atlas_service'],
            'wired_callers_count' => 1,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_COMPOUNDS, $r['bucket']);
        $this->assertGreaterThan(0.0, $r['risk_reduction_credit']);
    }

    // ── risk_reduction_credit ranges ─────────────────────────────────────────

    public function test_compounds_existing_has_higher_risk_credit_than_low_compounding(): void
    {
        $low = $this->router->route(['outcome_type' => 'new_service', 'evidence_refs' => ['phpunit:t']]);
        $cmp = $this->router->route(['outcome_type' => 'enhancement', 'wired_callers_count' => 1]);

        $this->assertGreaterThan($low['risk_reduction_credit'], $cmp['risk_reduction_credit']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $outcome = [
            'outcome_type'        => 'new_service',
            'evidence_refs'       => ['integration:full_suite'],
            'wired_callers_count' => 3,
        ];

        $this->assertSame(json_encode($this->router->route($outcome)), json_encode($this->router->route($outcome)));
    }
}
