<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Operator Battery v2 Certification contract tests.
 *
 * Slice 0 invariant: the certification ships with all 18 canonical invariants
 * registered. Eight are evaluable now and must be ok=true. The remaining ten
 * carry `status = pending_slice_N` (1 <= N <= 5). Aggregate status is
 * `pending_implementation` and overall `ok` is false.
 *
 * The certification must keep `separated_from = 'external_rivals_certification'`
 * — Operator Battery v2 NEVER unlocks the external rivals claim by itself.
 */
final class AtlasForgeRivalsOperatorBatteryCertificationTest extends TestCase
{
    public function test_cert_declares_all_eighteen_canonical_invariants(): void
    {
        $cert = new AtlasForgeRivalsOperatorBatteryCertification;
        $payload = $cert->evaluate();

        $this->assertCount(18, AtlasForgeRivalsOperatorBatteryCertification::REQUIRED_INVARIANTS);
        $this->assertCount(18, $payload['invariants']);
        $this->assertSame(
            'atlas.forge_rivals_operator_battery_certification.v1',
            $payload['schema_version']
        );
        $this->assertSame(
            'atlas_forge_rivals_operator_battery_certification',
            $payload['certification_key']
        );
        $this->assertSame('external_rivals_certification', $payload['separated_from']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertContains($payload['status'], [
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_AVAILABLE,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_PENDING_IMPLEMENTATION,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_BLOCKED,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_MISSING_ARTIFACTS,
        ]);
    }

    public function test_cert_reaches_available_when_full_flow_is_wired(): void
    {
        $payload = (new AtlasForgeRivalsOperatorBatteryCertification)->evaluate();

        $this->assertSame(
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_AVAILABLE,
            $payload['status'],
            'with slices 1-5 wired, cert must report available'
        );
        $this->assertTrue($payload['ok']);
        foreach ($payload['invariants'] as $name => $row) {
            $this->assertTrue(
                (bool) $row['ok'],
                "invariant '{$name}' must be ok=true with the full v2 flow wired. status='".(string) $row['status']."'"
            );
        }
    }

    public function test_canonical_invariants_evaluable_via_v2_artifacts(): void
    {
        $payload = (new AtlasForgeRivalsOperatorBatteryCertification)->evaluate();
        // Slice-0 invariants always evaluate against v2-canonical sources.
        foreach ([
            'canonical_forge_only_entrypoint',
            'old_rivals_paths_deprecated_or_wrapped',
            'tracked_python_bytecode_blocked',
            'sonnet_opus_codex_supported',
            'fair_mode_same_model_enforced',
            'zero_case_preset_blocks',
            'invalid_never_claims',
            'external_rivals_remains_blocked',
        ] as $name) {
            $row = $payload['invariants'][$name];
            $this->assertTrue((bool) $row['ok'], "Canonical invariant '{$name}' must be green");
        }
    }

    public function test_external_rivals_certification_invariant_is_green(): void
    {
        $payload = (new AtlasForgeRivalsOperatorBatteryCertification)->evaluate();

        $this->assertSame('external_rivals_certification', $payload['separated_from']);
        $this->assertTrue(
            (bool) $payload['invariants']['external_rivals_remains_blocked']['ok'],
            'external_rivals_remains_blocked invariant must be green'
        );
        // The cert itself reaching `available` does NOT unlock external rivals.
        $this->assertSame(
            'external_rivals_certification',
            $payload['separated_from'],
            'separated_from must remain external_rivals_certification regardless of status'
        );
    }
}
