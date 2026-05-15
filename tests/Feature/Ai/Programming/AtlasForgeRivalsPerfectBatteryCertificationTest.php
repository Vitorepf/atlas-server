<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsPerfectBatteryCertification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Perfect Battery & Adjudicator v1 cert contract tests.
 *
 * Asserts the new 12-invariant certification ships available end-to-end
 * once the perfect-battery slice is wired, and verifies the audit action
 * exposes the dual cert payload.
 */
final class AtlasForgeRivalsPerfectBatteryCertificationTest extends TestCase
{
    public function test_cert_declares_all_twelve_canonical_invariants(): void
    {
        $cert = new AtlasForgeRivalsPerfectBatteryCertification;
        $payload = $cert->evaluate();

        $this->assertCount(12, AtlasForgeRivalsPerfectBatteryCertification::REQUIRED_INVARIANTS);
        $this->assertCount(12, $payload['invariants']);
        $this->assertSame(
            'atlas.forge_rivals_perfect_battery_certification.v1',
            $payload['schema_version']
        );
        $this->assertSame(
            'atlas_forge_rivals_perfect_battery_certification',
            $payload['certification_key']
        );
        $this->assertSame('external_rivals_certification', $payload['separated_from']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_cert_reaches_available_when_perfect_battery_is_wired(): void
    {
        $payload = (new AtlasForgeRivalsPerfectBatteryCertification)->evaluate();

        $this->assertSame(
            AtlasForgeRivalsPerfectBatteryCertification::STATUS_AVAILABLE,
            $payload['status'],
            'with perfect battery slice 7 wired, cert must report available'
        );
        $this->assertTrue($payload['ok']);
        foreach ($payload['invariants'] as $name => $row) {
            $this->assertTrue(
                (bool) $row['ok'],
                "invariant '{$name}' must be ok=true with perfect battery wired. status='".(string) $row['status']."'"
            );
        }
    }

    public function test_external_rivals_never_unlocked_invariant_green(): void
    {
        $payload = (new AtlasForgeRivalsPerfectBatteryCertification)->evaluate();

        $this->assertTrue(
            (bool) $payload['invariants']['no_external_rivals_unlock']['ok'],
            'external_rivals_certification MUST remain blocked by this cert'
        );
    }

    public function test_audit_action_exposes_dual_certification_payload(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'audit',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('certification', $payload, 'audit must keep legacy operator-battery cert for back-compat');
        $this->assertArrayHasKey('perfect_battery_certification', $payload, 'audit must expose perfect battery cert');
        $this->assertSame(
            'atlas.forge_rivals_perfect_battery_certification.v1',
            $payload['perfect_battery_certification']['schema_version']
        );
        $this->assertArrayHasKey(
            'atlas_forge_rivals_perfect_battery_certification',
            $payload['certifications']
        );
        $this->assertArrayHasKey(
            'atlas_forge_rivals_operator_battery_certification',
            $payload['certifications']
        );
    }

    public function test_existing_operator_battery_certification_still_available(): void
    {
        // We must NOT regress the v2 cert by adding the v1-perfect cert.
        $payload = (new \App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification)->evaluate();
        $this->assertSame(
            \App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification::STATUS_AVAILABLE,
            $payload['status'],
        );
    }
}
