<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDesktopService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Desktop surface-root rules: macOS install
 * guardrail, desktop parent-resolution and the anti-mock / not-Kernel contract.
 *
 * @see docs/engineering-knowledge-base/atlas-desktop.md
 */
class AtlasDesktopTest extends TestCase
{
    private function service(): AtlasDesktopService
    {
        return new AtlasDesktopService();
    }

    /**
     * Doc "Exemplos": exactly one `Atlas Code.app`, the backend-contract and
     * code-surface parented to atlas-desktop under the world root, canonical
     * data origin => zero violations, gate passes.
     */
    public function test_healthy_desktop_surface_passes_gate(): void
    {
        $audit = $this->service()->audit(
            ['Atlas Code.app', 'Safari.app'],
            [
                ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
                ['id' => 'atlas-desktop', 'parent' => 'atlas', 'status' => 'active'],
                ['id' => 'atlas-desktop-backend-contract', 'parent' => 'atlas-desktop', 'status' => 'active'],
                ['id' => 'atlas-desktop-code-surface', 'parent' => 'atlas-desktop', 'status' => 'active'],
            ],
            ['claims_kernel_authority' => false, 'data_origin' => 'canonical'],
        );

        $this->assertTrue($audit['gate_pass']);
        $this->assertSame('cartography-orphan-count-zero', $audit['gate']);
        $this->assertTrue($audit['install']['install_ok']);
        $this->assertTrue($audit['install']['canonical_present']);
        $this->assertSame(0, $audit['cartography']['orphan_count']);
        $this->assertTrue($audit['anti_mock']['anti_mock_ok']);
    }

    /**
     * Doc "macOS Build / Install Guardrail" + forbidden_changes: a timestamped
     * backup bundle `Atlas Code.app.backup-2026` is a forbidden install and
     * fails the gate even though the canonical bundle is also present.
     */
    public function test_timestamped_backup_bundle_is_forbidden_install(): void
    {
        $install = $this->service()->auditMacInstall([
            'Atlas Code.app',
            'Atlas Code.app.backup-2026-05-31',
        ]);

        $this->assertFalse($install['install_ok']);
        $this->assertTrue($install['canonical_present']);
        $this->assertSame('Atlas Code.app.backup-2026-05-31', $install['violations'][0]['name']);
        $this->assertSame('timestamped_backup_bundle', $install['violations'][0]['reason']);
    }

    /**
     * Doc guardrail: a hyphen-date-suffixed bundle `Atlas Code.app-2026...`
     * and a dotted-suffix bundle `Atlas Code.app...` are both classified as
     * forbidden polluting copies.
     */
    public function test_suffixed_and_dotted_bundles_are_classified_forbidden(): void
    {
        $install = $this->service()->auditMacInstall([
            'Atlas Code.app-2026-05-31',
            'Atlas Code.app...',
        ]);

        $reasons = array_column($install['violations'], 'reason');
        $this->assertContains('timestamped_suffixed_bundle', $reasons);
        $this->assertContains('dotted_suffixed_bundle', $reasons);
        // No canonical bundle present at all -> install cannot be ok.
        $this->assertFalse($install['canonical_present']);
        $this->assertFalse($install['install_ok']);
    }

    /**
     * Doc R1: /Applications must hold EXACTLY one canonical bundle; a duplicate
     * `Atlas Code.app` is itself a violation.
     */
    public function test_duplicate_canonical_bundle_is_violation(): void
    {
        $install = $this->service()->auditMacInstall([
            'Atlas Code.app',
            'Atlas Code.app',
        ]);

        $this->assertFalse($install['install_ok']);
        $this->assertSame('duplicate_canonical_bundle', $install['violations'][0]['reason']);
    }

    /**
     * Doc "Riscos": "Sem este parent, backend desktop e Atlas Code podem
     * aparecer como docs orfaos." Remove the atlas-desktop root and every active
     * child parented to it orphans (desktop_root_missing); cartography fails.
     */
    public function test_missing_desktop_root_orphans_children(): void
    {
        $cartography = $this->service()->auditDesktopCartography([
            ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
            // atlas-desktop root deliberately absent.
            ['id' => 'atlas-desktop-backend-contract', 'parent' => 'atlas-desktop', 'status' => 'active'],
            ['id' => 'atlas-desktop-code-surface', 'parent' => 'atlas-desktop', 'status' => 'active'],
        ]);

        $this->assertFalse($cartography['root_present']);
        $this->assertSame(2, $cartography['orphan_count']);
        $this->assertFalse($cartography['cartography_ok']);
        $reasons = array_column($cartography['orphans'], 'reason');
        $this->assertSame(['desktop_root_missing', 'desktop_root_missing'], $reasons);
    }

    /**
     * Doc "Regras para IA" + "Contratos": Desktop nao decide como Kernel, e
     * dados exibidos precisam vir de fontes canonicas/receipts. A payload that
     * claims kernel authority and presents mock data is rejected.
     */
    public function test_kernel_claim_and_mock_origin_violate_anti_mock(): void
    {
        $antiMock = $this->service()->auditSurfacePayload([
            'claims_kernel_authority' => true,
            'data_origin' => 'mock',
        ]);

        $this->assertFalse($antiMock['anti_mock_ok']);
        $reasons = array_column($antiMock['violations'], 'reason');
        $this->assertContains('desktop_claims_kernel_authority', $reasons);
        $this->assertContains('non_canonical_data_origin', $reasons);
    }

    /**
     * Doc scopes the contract to "documento ativo": an inactive desktop child
     * with a dangling parent is NOT an orphan. And a receipt-sourced surface is
     * accepted. Stable receipt schema is always present.
     */
    public function test_inactive_child_ignored_and_receipt_origin_accepted(): void
    {
        $audit = $this->service()->audit(
            ['Atlas Code.app'],
            [
                ['id' => 'atlas-desktop', 'parent' => 'atlas', 'status' => 'active'],
                ['id' => 'retired-desktop-surface', 'parent' => 'ghost', 'status' => 'archived'],
            ],
            ['data_origin' => 'receipt'],
        );

        $this->assertSame(0, $audit['cartography']['orphan_count']);
        $this->assertTrue($audit['anti_mock']['anti_mock_ok']);
        $this->assertTrue($audit['gate_pass']);
        $this->assertSame('atlas.aaeos.surface_root.atlas_desktop.v1', $audit['schema']);
        $this->assertTrue($audit['auditable']);
    }
}
