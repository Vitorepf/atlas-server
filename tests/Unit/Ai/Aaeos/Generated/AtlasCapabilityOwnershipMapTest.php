<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCapabilityOwnershipMapService;
use Tests\TestCase;

/**
 * Pins the documented Architecture Audit Capability Ownership Map contract:
 * the fifteen-capability ownership table (each capability has exactly one
 * canonical owner), the three-clause Placement Rule (executes -> Runtime/Tool,
 * multi-domain -> Core/Runtime, multi-surface -> not surface-owned), and the
 * duplication audit that rejects a surface claiming ownership of a cross-cutting
 * or executing capability. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md
 */
class AtlasCapabilityOwnershipMapTest extends TestCase
{
    private function service(): AtlasCapabilityOwnershipMapService
    {
        return new AtlasCapabilityOwnershipMapService();
    }

    /**
     * Doc ownership table: fifteen capabilities, each resolving to its single
     * canonical owner. Spot-checks the rows the doc is most opinionated about.
     */
    public function test_ownership_table_resolves_each_capability_to_its_single_owner(): void
    {
        $this->assertCount(15, AtlasCapabilityOwnershipMapService::OWNERSHIP);

        $this->assertSame('Atlas AI Core', $this->service()->ownerOf('Domain/intent resolution')['owner']);
        // Atlas Decide "Emits receipt; does not execute." — it never owns execution.
        $decide = $this->service()->ownerOf('Provider/model/policy choice');
        $this->assertSame('Atlas Decide', $decide['owner']);
        $this->assertSame('Emits receipt; does not execute.', $decide['notes']);
        $this->assertSame('Super Tool Runtime', $this->service()->ownerOf('Tools')['owner']);
        $this->assertSame('Self-Improvement/Curator', $this->service()->ownerOf('Evolution')['owner']);
    }

    /**
     * An unmapped capability is a gap, not a guess: found=false and a null owner.
     * The map never invents an owner (that is the duplication it prevents).
     */
    public function test_unmapped_capability_returns_no_owner_instead_of_guessing(): void
    {
        $r = $this->service()->ownerOf('some brand new capability');

        $this->assertFalse($r['found']);
        $this->assertNull($r['owner']);
    }

    /**
     * Placement Rule clause 3: "If more than one surface needs it, it is not
     * surface-owned." Two surfaces, one domain, no execution -> surface ownership
     * is forbidden and the allowed layers exclude `surface`.
     */
    public function test_multi_surface_capability_is_not_surface_owned(): void
    {
        $r = $this->service()->placeCapability([
            'surface_count' => 2,
            'domain_count' => 1,
            'executes_external_commands' => false,
        ]);

        $this->assertFalse($r['surface_ownable']);
        $this->assertNotContains(AtlasCapabilityOwnershipMapService::PLACEMENT_SURFACE, $r['allowed_layers']);
        $this->assertContains('multi_surface_is_not_surface_owned', $r['applied_rules']);
    }

    /**
     * Placement Rule clause 2: "If more than one domain needs it, it is Core or
     * Runtime." Multi-domain, single-surface, non-executing -> exactly Core or
     * Runtime, and not surface-ownable.
     */
    public function test_multi_domain_capability_is_core_or_runtime(): void
    {
        $r = $this->service()->placeCapability([
            'surface_count' => 1,
            'domain_count' => 2,
            'executes_external_commands' => false,
        ]);

        $this->assertSame(
            [
                AtlasCapabilityOwnershipMapService::PLACEMENT_CORE,
                AtlasCapabilityOwnershipMapService::PLACEMENT_RUNTIME,
            ],
            $r['allowed_layers'],
        );
        $this->assertFalse($r['surface_ownable']);
    }

    /**
     * Placement Rule clause 1 (strongest): "If it executes external commands, it
     * is Runtime or Tool Runtime." This holds even for a single surface and a
     * single domain — execution overrides the otherwise-surface-ownable default.
     */
    public function test_executing_capability_is_runtime_or_tool_runtime_even_when_single_surface(): void
    {
        $r = $this->service()->placeCapability([
            'surface_count' => 1,
            'domain_count' => 1,
            'executes_external_commands' => true,
        ]);

        $this->assertSame(
            [
                AtlasCapabilityOwnershipMapService::PLACEMENT_RUNTIME,
                AtlasCapabilityOwnershipMapService::PLACEMENT_TOOL_RUNTIME,
            ],
            $r['allowed_layers'],
        );
        $this->assertFalse($r['surface_ownable']);
    }

    /**
     * The only surface-ownable case: a single surface, a single domain, no
     * execution. This is the lone path where `surface` is allowed.
     */
    public function test_single_surface_single_domain_non_executing_may_be_surface_owned(): void
    {
        $r = $this->service()->placeCapability([
            'surface_count' => 1,
            'domain_count' => 1,
            'executes_external_commands' => false,
        ]);

        $this->assertTrue($r['surface_ownable']);
        $this->assertSame([AtlasCapabilityOwnershipMapService::PLACEMENT_SURFACE], $r['allowed_layers']);
    }

    /**
     * Duplication audit (doc Resumo): a surface claiming ownership of a
     * cross-cutting, executing capability is a violation; the same surface
     * claiming a single-surface non-executing capability is valid.
     */
    public function test_surface_ownership_of_cross_cutting_capability_is_a_duplication_violation(): void
    {
        $bad = $this->service()->auditOwnershipClaim('surface', [
            'surface_count' => 3,
            'domain_count' => 3,
            'executes_external_commands' => true,
        ]);
        $this->assertFalse($bad['valid']);
        $this->assertContains(
            'surface_ownership_claimed_for_cross_cutting_or_executing_capability',
            $bad['violations'],
        );

        $good = $this->service()->auditOwnershipClaim('surface', [
            'surface_count' => 1,
            'domain_count' => 1,
            'executes_external_commands' => false,
        ]);
        $this->assertTrue($good['valid']);
        $this->assertSame([], $good['violations']);
    }
}
