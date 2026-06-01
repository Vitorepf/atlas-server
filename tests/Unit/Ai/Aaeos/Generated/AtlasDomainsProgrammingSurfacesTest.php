<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDomainsProgrammingSurfacesService;
use Tests\TestCase;

/**
 * Pins the documented surface→flow contract table and its invariants:
 *   - Atlas Code binds programming.forge ONLY, requires obra_id, evidence req.
 *   - atlas fix is a thin alias of `atlas dev --repair` (programming.repair).
 *   - every surface binds domain_id=programming (no parallel product).
 *   - surfaces never own model selection (authority=atlas_decide).
 *
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-surfaces.md
 */
class AtlasDomainsProgrammingSurfacesTest extends TestCase
{
    private function service(): AtlasDomainsProgrammingSurfacesService
    {
        return new AtlasDomainsProgrammingSurfacesService();
    }

    /**
     * Atlas Code (and its SCOR-1 alias) resolves to programming.forge only, with
     * routing_task=forge, programming_profile=forge, obra required + evidence.
     */
    public function test_atlas_code_resolves_to_forge_only_contract(): void
    {
        $contract = $this->service()->resolve('Atlas Code SCOR-1');

        $this->assertTrue($contract['known']);
        $this->assertSame('atlas_code', $contract['surface']);
        $this->assertSame('programming.forge', $contract['flow']);
        $this->assertTrue($contract['forge_only']);
        $this->assertSame('forge', $contract['routing_task']);
        $this->assertSame('forge', $contract['programming_profile']);
        $this->assertTrue($contract['requires_obra_id']);
        $this->assertTrue($contract['evidence_required']);
    }

    /**
     * Doc invariant: Atlas Code WITHOUT obra_id is inadmissible; the violation is
     * the documented obra requirement.
     */
    public function test_atlas_code_without_obra_id_is_inadmissible(): void
    {
        $result = $this->service()->admit(['surface' => 'Atlas Code']);

        $this->assertSame(
            AtlasDomainsProgrammingSurfacesService::OUTCOME_INADMISSIBLE,
            $result['outcome']
        );

        $rules = array_column($result['violations'], 'rule');
        $this->assertContains(
            AtlasDomainsProgrammingSurfacesService::VIOLATION_OBRA_REQUIRED,
            $rules
        );
    }

    /**
     * Atlas Code WITH a valid obra_id on the forge flow is admissible (no
     * violations).
     */
    public function test_atlas_code_with_obra_id_on_forge_flow_is_admissible(): void
    {
        $result = $this->service()->admit([
            'surface' => 'Atlas Code',
            'flow' => 'programming.forge',
            'obra_id' => 'obra_demo_001',
        ]);

        $this->assertSame(
            AtlasDomainsProgrammingSurfacesService::OUTCOME_ADMISSIBLE,
            $result['outcome']
        );
        $this->assertSame([], $result['violations']);
    }

    /**
     * Doc invariant: Atlas Code binds programming.forge ONLY — asking for any
     * other flow is inadmissible even when obra_id is present.
     */
    public function test_atlas_code_rejects_non_forge_flow(): void
    {
        $result = $this->service()->admit([
            'surface' => 'Atlas Code',
            'flow' => 'programming.dev',
            'obra_id' => 'obra_demo_001',
        ]);

        $rules = array_column($result['violations'], 'rule');
        $this->assertContains(
            AtlasDomainsProgrammingSurfacesService::VIOLATION_FORGE_ONLY,
            $rules
        );
    }

    /**
     * atlas fix is a thin alias of `atlas dev --repair` and maps to
     * programming.repair with the dev_repair_executor runtime.
     */
    public function test_atlas_fix_is_thin_alias_of_dev_repair(): void
    {
        $contract = $this->service()->resolve('atlas fix');

        $this->assertSame('atlas_fix', $contract['surface']);
        $this->assertSame('programming.repair', $contract['flow']);
        $this->assertSame('atlas dev --repair', $contract['is_alias_of']);
    }

    /**
     * Doc invariants that hold for EVERY surface: bound to domain_id=programming,
     * never owning model selection, never a parallel product. Also: a surface
     * that tries to bind a parallel domain or own a model is inadmissible.
     */
    public function test_every_surface_binds_programming_and_never_owns_model(): void
    {
        foreach ($this->service()->table() as $row) {
            $this->assertSame('programming', $row['domain_id']);
            $this->assertFalse($row['owns_model_selection']);
            $this->assertSame('atlas_decide', $row['model_selection_authority']);
            $this->assertFalse($row['parallel_product']);
        }

        // A surface attempting to own model selection is re-routed (violation).
        $ownsModel = $this->service()->admit([
            'surface' => 'atlas dev',
            'owns_model_selection' => true,
        ]);
        $ownRules = array_column($ownsModel['violations'], 'rule');
        $this->assertContains(
            AtlasDomainsProgrammingSurfacesService::VIOLATION_SURFACE_OWNS_MODEL,
            $ownRules
        );
        $this->assertFalse($ownsModel['model_selection']['owned_by_surface']);

        // A surface attempting to bind a parallel domain is inadmissible.
        $parallel = $this->service()->admit([
            'surface' => 'atlas dev',
            'domain_id' => 'frontend',
        ]);
        $parallelRules = array_column($parallel['violations'], 'rule');
        $this->assertContains(
            AtlasDomainsProgrammingSurfacesService::VIOLATION_PARALLEL_DOMAIN,
            $parallelRules
        );
    }
}
