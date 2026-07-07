<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Canon lock (Atlas Orchestrator Canon): `role slot != runtime identity`.
 * No architecture-spine LAYER may carry a provider/runtime name, and the Hermes
 * Executive Mesh must stay a RUNTIME ADAPTER *under* the Workcell Fabric (AAWR),
 * never re-climb to a top layer. This is the executable, regression-proof form
 * of the "rg = 0 provider-name in layer position" gate. It is deliberately
 * NARROW: it locks the 5 spine layers + the one demoted adapter, so it never
 * false-positives on the many docs that legitimately *describe* providers.
 */
class OrchestratorLayerNamingGuardTest extends TestCase
{
    /** Runtime/provider tokens that must never name an architecture layer. */
    private const PROVIDER_TOKENS = [
        'hermes', 'claude', 'codex', 'cursor', 'gemini',
        'minimax', 'glm', 'kimi', 'antigravity', 'composer',
    ];

    /** The 5 canonical spine layers — all provider-neutral by construction. */
    private const SPINE_LAYERS = [
        'Hyperflow', 'Decision Core', 'Workcell Fabric', 'Proof Loop', 'Learning Loop',
    ];

    private function docsPath(string $rel): string
    {
        return base_path('docs/engineering-knowledge-base/'.$rel);
    }

    public function test_canon_declares_five_provider_neutral_spine_layers(): void
    {
        $canon = file_get_contents($this->docsPath('atlas-orchestrator-canon.md'));
        $this->assertNotFalse($canon);

        foreach (self::SPINE_LAYERS as $layer) {
            $this->assertStringContainsString($layer, $canon, "Canon lost spine layer: {$layer}");
            $this->assertFalse(
                $this->layerNameCarriesProviderName($layer),
                "Spine layer name carries a provider/runtime token: {$layer}",
            );
        }

        // Workcell Fabric must map to the reused, provider-neutral AAWR — not a
        // provider-named runtime. Reuse-first invariant from the canon.
        $this->assertStringContainsString('AtlasAgenticWorkcellRuntimeService', $canon);
    }

    public function test_hermes_mesh_is_a_runtime_adapter_not_a_spine_layer(): void
    {
        $doc = file_get_contents($this->docsPath('atlas-hermes-executive-mesh.md'));
        $this->assertNotFalse($doc);

        $graphLayer = $this->frontmatterValue($doc, 'graph_layer');
        $this->assertNotSame('system', $graphLayer, 'Hermes mesh re-climbed to a top (system) architecture layer.');

        // The canon demotion decision must stay written in the adapter doc.
        $this->assertStringContainsStringIgnoringCase('runtime adapter', $doc);
        $this->assertStringContainsString('Workcell Fabric', $doc);
    }

    public function test_workcell_adapter_code_frames_itself_as_adapter_under_the_fabric(): void
    {
        $src = file_get_contents(app_path('Services/Ai/Hermes/Mesh/HermesWorkcellAdapter.php'));
        $this->assertNotFalse($src);

        $this->assertStringContainsString('RUNTIME ADAPTER', $src);
        $this->assertStringContainsString('Workcell Fabric', $src);
        $this->assertStringContainsString('implements WorkcellAdapter', $src);
        $this->assertStringNotContainsString('sovereign orchestrator', $src, 'Code still frames the adapter as a top-layer orchestrator.');
    }

    public function test_mesh_name_survives_only_as_a_deprecated_alias(): void
    {
        // Canon rename (Goal 3 SLICE 1): the canonical class is HermesWorkcellAdapter;
        // the retired name HermesExecutiveMeshService must remain a pure deprecated alias.
        $alias = file_get_contents(app_path('Services/Ai/Hermes/Mesh/HermesExecutiveMeshService.php'));
        $this->assertNotFalse($alias);
        $this->assertStringContainsString('@deprecated', $alias);
        $this->assertStringContainsString('extends HermesWorkcellAdapter', $alias);

        // Zero broken callers: the retired FQCN still resolves as the same runtime type,
        // and the canonical class satisfies the provider-neutral Workcell Adapter contract.
        $this->assertTrue(is_a(
            \App\Services\Ai\Hermes\Mesh\HermesExecutiveMeshService::class,
            \App\Services\Ai\Hermes\Mesh\HermesWorkcellAdapter::class,
            true,
        ));
        $this->assertTrue(is_a(
            \App\Services\Ai\Hermes\Mesh\HermesWorkcellAdapter::class,
            \App\Services\Ai\AgenticWorkcell\Contracts\WorkcellAdapter::class,
            true,
        ));
    }

    /**
     * Red-proof: the guard logic MUST flag a provider name sitting in layer
     * position. If this ever passes a violating input, the gate is fake-green.
     */
    public function test_guard_logic_flags_a_provider_named_layer(): void
    {
        // Violation: "Hermes Executive Mesh" as a spine-layer name.
        $this->assertTrue($this->layerNameCarriesProviderName('Hermes Executive Mesh'));
        $this->assertTrue($this->layerNameCarriesProviderName('Codex Decision Core'));

        // Clean: the real spine layers carry no provider token.
        $this->assertFalse($this->layerNameCarriesProviderName('Workcell Fabric'));
        $this->assertFalse($this->layerNameCarriesProviderName('Decision Core'));
    }

    /** True when a layer label contains any provider/runtime token as a word. */
    private function layerNameCarriesProviderName(string $layerName): bool
    {
        foreach (self::PROVIDER_TOKENS as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/i', $layerName) === 1) {
                return true;
            }
        }

        return false;
    }

    private function frontmatterValue(string $doc, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').':\s*(.+)$/m', $doc, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }
}
