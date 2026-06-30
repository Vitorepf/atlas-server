<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompoundingOutcomeRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompoundingOutcomeRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainCompoundingOutcomeRouter
    {
        return new AtlasExternalBrainCompoundingOutcomeRouter;
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->router()->route([]);
        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('bucket', $r);
        $this->assertArrayHasKey('compounding_score', $r);
        $this->assertArrayHasKey('classification_reason', $r);
        $this->assertArrayHasKey('requires_downstream_proof', $r);
        $this->assertArrayHasKey('has_downstream_proof', $r);
    }

    // ── Maintenance bucket ────────────────────────────────────────────────────

    public function test_doc_update_routes_to_maintenance(): void
    {
        $r = $this->router()->route(['outcome_type' => 'doc_update']);
        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_MAINTENANCE, $r['bucket']);
    }

    public function test_formatting_routes_to_maintenance(): void
    {
        $r = $this->router()->route(['outcome_type' => 'formatting']);
        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_MAINTENANCE, $r['bucket']);
    }

    // ── AC3: low_compounding classification ───────────────────────────────────

    public function test_cosmetic_flag_routes_to_low_compounding(): void
    {
        $r = $this->router()->route([
            'outcome_type' => 'new_service',
            'is_cosmetic'  => true,
            'wired_callers_count' => 5,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_LOW, $r['bucket']);
        $this->assertSame('cosmetic_outcome', $r['classification_reason']);
    }

    public function test_cosmetic_wrapper_type_routes_to_low_compounding(): void
    {
        $r = $this->router()->route(['outcome_type' => 'cosmetic_wrapper']);
        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_LOW, $r['bucket']);
    }

    public function test_schema_change_without_wired_callers_is_low_compounding(): void
    {
        $r = $this->router()->route([
            'outcome_type'         => 'schema_change',
            'wired_callers_count'  => 0,
            'evidence_refs'        => ['phpunit:t1'],
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_LOW, $r['bucket']);
        $this->assertSame('schema_only_no_wired_callers', $r['classification_reason']);
        $this->assertTrue($r['requires_downstream_proof']);
    }

    public function test_unit_tests_only_without_downstream_proof_is_low_compounding(): void
    {
        // AC2: unit tests alone are not sufficient for compounding.
        $r = $this->router()->route([
            'outcome_type'  => 'new_service',
            'evidence_refs' => ['phpunit:t1', 'phpunit:t2'],
            // no wired_callers, no integration refs
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_LOW, $r['bucket']);
        $this->assertSame('no_downstream_evidence_or_wired_callers', $r['classification_reason']);
        $this->assertTrue($r['requires_downstream_proof']);
    }

    // ── AC2: compounding requires explicit downstream evidence ────────────────

    public function test_integration_evidence_enables_compounding_bucket(): void
    {
        $r = $this->router()->route([
            'outcome_type'  => 'new_service',
            'evidence_refs' => ['integration:e2e_suite_green'],
            'wired_callers_count' => 2,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_UNLOCKS_NEW, $r['bucket']);
        $this->assertTrue($r['has_downstream_proof']);
        $this->assertFalse($r['requires_downstream_proof']);
    }

    public function test_wired_callers_alone_enable_compounds_existing(): void
    {
        $r = $this->router()->route([
            'outcome_type'        => 'refactor',
            'evidence_refs'       => ['phpunit:t1'],
            'wired_callers_count' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_COMPOUNDS, $r['bucket']);
        $this->assertTrue($r['has_downstream_proof']);
    }

    public function test_downstream_services_enabled_satisfies_downstream_proof(): void
    {
        $r = $this->router()->route([
            'outcome_type'               => 'new_organ',
            'downstream_services_enabled' => 2,
            'wired_callers_count'         => 0,
            'evidence_refs'               => ['phpunit:t1'],
        ]);

        // downstream_services_enabled > 0 → has_downstream_proof = true.
        $this->assertTrue($r['has_downstream_proof']);
    }

    public function test_new_capability_with_wired_callers_unlocks_new_capability(): void
    {
        $r = $this->router()->route([
            'outcome_type'        => 'new_capability',
            'evidence_refs'       => ['integration:smoke'],
            'wired_callers_count' => 4,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_UNLOCKS_NEW, $r['bucket']);
        $this->assertGreaterThan(0.8, $r['compounding_score']);
    }

    public function test_compounds_existing_when_downstream_proof_but_not_new_capability(): void
    {
        $r = $this->router()->route([
            'outcome_type'        => 'enhancement',
            'evidence_refs'       => ['wired_callers:atlas_service'],
            'wired_callers_count' => 1,
        ]);

        $this->assertSame(AtlasExternalBrainCompoundingOutcomeRouter::BUCKET_COMPOUNDS, $r['bucket']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $outcome = [
            'outcome_type'        => 'new_service',
            'evidence_refs'       => ['integration:full_suite'],
            'wired_callers_count' => 3,
        ];
        $a = $this->router()->route($outcome);
        $b = $this->router()->route($outcome);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
