<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationFailureModeCatalog;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationFailureModeCatalogTest extends TestCase
{
    private function catalog(): AtlasExternalBrainSimplificationFailureModeCatalog
    {
        return new AtlasExternalBrainSimplificationFailureModeCatalog;
    }

    // ── AC: known_failure_prework_case ─────────────────────────────────────

    public function test_missing_public_contract_maps_to_contract_extraction_prework(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'missing_public_contract']);

        $this->assertSame('known_failure', $r['decision']);
        $this->assertSame(['contract_extraction'], $r['required_prework']);
    }

    public function test_replay_divergence_maps_to_replay_prework(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'replay_divergence']);

        $this->assertSame('known_failure', $r['decision']);
        $this->assertSame(['replay'], $r['required_prework']);
    }

    public function test_no_rollback_path_maps_to_rollback_prework(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'no_rollback_path']);

        $this->assertSame(['rollback'], $r['required_prework']);
    }

    public function test_unknown_consumer_maps_to_consumer_discovery_prework(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'unknown_consumer']);

        $this->assertSame(['consumer_discovery'], $r['required_prework']);
    }

    public function test_missing_test_coverage_maps_to_test_coverage_prework(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'missing_test_coverage']);

        $this->assertSame(['test_coverage_proof'], $r['required_prework']);
    }

    // ── AC: unknown_failure_investigate_case ────────────────────────────────

    public function test_unknown_failure_signature_returns_investigate_first_with_evidence_needed(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'completely_novel_failure']);

        $this->assertSame('investigate_first', $r['decision']);
        $this->assertSame([], $r['required_prework']);
        $this->assertNotEmpty($r['evidence_needed']);
    }

    public function test_missing_failure_signature_returns_investigate_first(): void
    {
        $r = $this->catalog()->catalog([]);

        $this->assertSame('investigate_first', $r['decision']);
    }

    public function test_investigate_first_is_never_a_default_retry(): void
    {
        $r = $this->catalog()->catalog(['failure_signature' => 'something_unrecognized']);

        $this->assertNotSame('retry', $r['decision']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_catalog_is_deterministic(): void
    {
        $facts = ['failure_signature' => 'missing_public_contract'];
        $a = $this->catalog()->catalog($facts);
        $b = $this->catalog()->catalog($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->catalog()->catalog([]);
        $this->assertSame(AtlasExternalBrainSimplificationFailureModeCatalog::SCHEMA, $r['schema']);
    }
}
