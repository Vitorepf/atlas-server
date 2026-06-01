<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingSelfConstructionForgeMapService;
use Tests\TestCase;

/**
 * Pins the enforceable contracts of the Atlas Programming Self-Construction
 * Forge Map: the canonical layer hierarchy (Contract 1), the AI reading order
 * router (Contract 2), the canonical name meanings and the forbidden-claim
 * gates (Contract 3).
 *
 * @see docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
 */
class AtlasProgrammingSelfConstructionForgeMapTest extends TestCase
{
    private function service(): AtlasProgrammingSelfConstructionForgeMapService
    {
        return new AtlasProgrammingSelfConstructionForgeMapService();
    }

    /**
     * Contract 1 — the canonical stack: Self-Construction OS is the mother-law
     * (rank 1), Forge Continuum sits below it, Atlas Code is a surface below
     * Forge, and a Provider is the lowest substitutable executor.
     */
    public function test_hierarchy_ranks_self_construction_as_mother_law(): void
    {
        $svc = $this->service();

        $this->assertSame(1, $svc->rankOf(AtlasProgrammingSelfConstructionForgeMapService::LAYER_SELF_CONSTRUCTION));
        $this->assertSame(2, $svc->rankOf(AtlasProgrammingSelfConstructionForgeMapService::LAYER_SELF_PROGRAMMING));
        $this->assertSame(3, $svc->rankOf(AtlasProgrammingSelfConstructionForgeMapService::LAYER_FORGE_CONTINUUM));
        $this->assertSame(4, $svc->rankOf(AtlasProgrammingSelfConstructionForgeMapService::LAYER_ATLAS_CODE));
        $this->assertSame(6, $svc->rankOf(AtlasProgrammingSelfConstructionForgeMapService::LAYER_PROVIDER));

        $hierarchy = $svc->hierarchy();
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::LAYER_SELF_CONSTRUCTION, $hierarchy['root']);
        $this->assertCount(6, $hierarchy['layers']);
    }

    /**
     * Contract 1 — "Forge Continuum nao substitui Self-Construction OS" and
     * "Atlas Code e so a surface": the higher layer always governs, regardless
     * of argument order, and aliases (e.g. "forge", "code") resolve correctly.
     */
    public function test_compare_layers_enforces_self_construction_above_forge_and_surface(): void
    {
        $svc = $this->service();

        // Self-Construction governs Forge Continuum (it is not replaced by it).
        $a = $svc->compareLayers('self-construction-os', 'forge');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::LAYER_SELF_CONSTRUCTION, $a['governs']);
        $this->assertSame('governs', $a['relation']);

        // Order independence: Atlas Code (surface) never governs Self-Construction.
        $b = $svc->compareLayers('code', 'self-construction');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::LAYER_SELF_CONSTRUCTION, $b['governs']);

        // Forge governs its own surface (Atlas Code).
        $c = $svc->compareLayers('atlas-code', 'forge-continuum-os');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::LAYER_FORGE_CONTINUUM, $c['governs']);
    }

    /**
     * Contract 2 — "Ordem De Leitura Para IA": a heavy-programming question
     * reads this map first, then forge-flow, then forge-continuum, then the
     * obras OS — in that exact order.
     */
    public function test_reading_order_routes_heavy_programming_question(): void
    {
        $order = $this->service()->readingOrder('atlas-code')['order'];

        $this->assertSame([
            'atlas-programming-self-construction-forge-map-v1',
            'atlas-programming-forge-flow',
            'atlas-forge-continuum-os',
            'atlas-code-programming-obras-operating-system',
        ], $order);
    }

    /**
     * Contract 2 — an unknown topic still resolves to read this map first
     * (the doc is the read-first orientation) and is flagged unknown.
     */
    public function test_reading_order_unknown_topic_falls_back_to_map_only(): void
    {
        $result = $this->service()->readingOrder('something-not-listed');

        $this->assertFalse($result['known']);
        $this->assertSame(['atlas-programming-self-construction-forge-map-v1'], $result['order']);
    }

    /**
     * Contract 3 — "O Que Cada Nome Quer Dizer": Atlas Code IS a surface and is
     * NOT the whole system; a Provider is NOT a permanent owner of a role.
     */
    public function test_resolve_name_returns_is_and_is_not(): void
    {
        $svc = $this->service();

        $code = $svc->resolveName('atlas-code');
        $this->assertTrue($code['known']);
        $this->assertSame('the whole system', $code['is_not']);

        $provider = $svc->resolveName('provider');
        $this->assertSame('a permanent owner of a role', $provider['is_not']);
    }

    /**
     * Contract 3 — the forbidden-claim gates: the three documented confusions
     * are blocked with the right gate, while a neutral statement is allowed.
     */
    public function test_evaluate_claim_blocks_documented_confusions(): void
    {
        $svc = $this->service();

        $forgeReplaces = $svc->evaluateClaim('forge-replaces-self-construction');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::STATUS_BLOCKED, $forgeReplaces['status']);
        $this->assertSame('no-duplicate-os', $forgeReplaces['gate']);

        $selfProg = $svc->evaluateClaim('self-programming-is-free-runtime');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::STATUS_BLOCKED, $selfProg['status']);
        $this->assertSame('self-programming-not-overclaimed', $selfProg['gate']);

        $wholeSystem = $svc->evaluateClaim('atlas-code-is-whole-system');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::STATUS_BLOCKED, $wholeSystem['status']);

        $neutral = $svc->evaluateClaim('forge-continuum-specializes-heavy-programming');
        $this->assertSame(AtlasProgrammingSelfConstructionForgeMapService::STATUS_ALLOWED, $neutral['status']);
        $this->assertNull($neutral['gate']);
    }
}
