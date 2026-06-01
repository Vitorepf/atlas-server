<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCartographyNomenclatureContractService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Cartography Nomenclature Contract rules.
 *
 * @see docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
 */
class AtlasCartographyNomenclatureContractTest extends TestCase
{
    private function service(): AtlasCartographyNomenclatureContractService
    {
        return new AtlasCartographyNomenclatureContractService();
    }

    /**
     * "Regra De Proximo Patamar": when none of the patamar_* fields are present,
     * the modal must read the exact sentinel "patamar ainda nao declarado" — and
     * graph_layer / flows_to / version numbers must NOT be used as a substitute.
     */
    public function test_undeclared_patamar_emits_sentinel_and_never_infers(): void
    {
        $svc = $this->service();

        // Atlas Vox canonical example: versions, no patamar.
        $vox = $svc->resolvePatamares([
            'version_family' => 'Atlas Vox',
            'versions' => ['V0', 'V3', 'V4', 'V6'],
            'graph_layer' => 'surface',
            'flows_to' => ['atlas-cartography'],
        ]);

        $this->assertFalse($vox['declared']);
        $this->assertSame('patamar ainda nao declarado', $vox['display']);
        $this->assertSame('patamar ainda nao declarado', $vox['lines']['Patamar atual']);
        $this->assertSame('patamar ainda nao declarado', $vox['lines']['Proximo patamar']);

        // A declared maturity leap reads the real value, not the sentinel.
        $declared = $svc->resolvePatamares([
            'patamar_current' => 'Self-Construction OS',
            'patamar_next' => 'Self-Programming OS',
        ]);
        $this->assertTrue($declared['declared']);
        $this->assertSame('Self-Construction OS', $declared['lines']['Patamar atual']);
        $this->assertSame('Self-Programming OS', $declared['lines']['Proximo patamar']);
    }

    /**
     * "Regra De Patamar" / "Regras para IA": patamar may never be inferred from
     * graph_layer, flows_to, unlocks, depends_on, version numbers or paths. Only
     * the four patamar_* fields are legal sources for the Patamares category.
     */
    public function test_patamares_rejects_every_forbidden_inference_source(): void
    {
        $svc = $this->service();

        $verdict = $svc->assertPatamares([
            'patamar_current',   // legal
            'patamar_next',      // legal
            'graph_layer',       // denied — camada
            'flows_to',          // denied — fluxo edge
            'unlocks',           // denied — fluxo edge
            'versions',          // denied — version number
            'schema_version',    // denied — schema
            'source_path',       // denied — fonte
        ]);

        $this->assertFalse($verdict['ok']);
        $this->assertSame('invalid', $verdict['verdict']);
        $this->assertSame(['patamar_current', 'patamar_next'], $verdict['legal']);
        $this->assertSame(
            ['graph_layer', 'flows_to', 'unlocks', 'versions', 'schema_version', 'source_path'],
            $verdict['rejected'],
        );

        // Each denied field is individually flagged as an inference source.
        $this->assertTrue($svc->isPatamarInferenceField('graph_layer'));
        $this->assertTrue($svc->isPatamarInferenceField('flows_to'));
        $this->assertTrue($svc->isPatamarInferenceField('V4'));            // bare version token
        $this->assertTrue($svc->isPatamarInferenceField('docs/some.md'));  // path
        $this->assertFalse($svc->isPatamarInferenceField('patamar_next')); // the only legal kind
    }

    /**
     * "Glossario De Nomenclatura Para Modal": each frontmatter field belongs to
     * exactly one category. version_family/versions are Versoes, graph_layer is
     * Camadas, repo_paths is Fontes, risk_level is Riscos — none of them is
     * Patamares. Only patamar_* fields are patamar-bearing.
     */
    public function test_field_classification_keeps_categories_separate(): void
    {
        $svc = $this->service();

        $this->assertSame('Patamares', $svc->classifyField('patamar_next')['category']);
        $this->assertTrue($svc->classifyField('patamar_next')['patamar_bearing']);

        $this->assertSame('Versoes', $svc->classifyField('versions')['category']);
        $this->assertFalse($svc->classifyField('versions')['patamar_bearing']);

        $this->assertSame('Camadas', $svc->classifyField('graph_layer')['category']);
        $this->assertSame('Fontes', $svc->classifyField('repo_paths')['category']);
        $this->assertSame('Riscos', $svc->classifyField('risk_level')['category']);
        $this->assertSame('Regras', $svc->classifyField('forbidden_changes')['category']);
        $this->assertSame('Fluxo', $svc->classifyField('flows_to')['category']);

        // An unknown field is reported, never guessed into a category.
        $unknown = $svc->classifyField('totally_made_up_field');
        $this->assertSame('unclassified', $unknown['category']);
        $this->assertFalse($unknown['classified']);
    }

    /**
     * "Regra De Versao" + "Quando A Documentacao Estiver Incompleta": when a
     * piece declares versions but no patamar, the Versoes section must carry the
     * note "versao declarada, nao patamar" so a version is never read as a leap.
     * When a patamar IS declared, that note must not fire.
     */
    public function test_versions_without_patamar_raise_the_not_a_patamar_note(): void
    {
        $svc = $this->service();

        $voxVersoes = $svc->resolveVersoes([
            'version_family' => 'Atlas Vox',
            'versions' => ['V0', 'V3', 'V4', 'V6'],
        ]);
        $this->assertTrue($voxVersoes['has_versions']);
        $this->assertSame(['V0', 'V3', 'V4', 'V6'], $voxVersoes['versions']);
        $this->assertSame('versao declarada, nao patamar', $voxVersoes['note']);

        // Versions present AND a patamar declared -> no "not a patamar" note.
        $withPatamar = $svc->resolveVersoes([
            'version_family' => 'Atlas Vox',
            'versions' => ['V6'],
            'patamar_current' => 'Ambient Cognitive Layer',
        ]);
        $this->assertNull($withPatamar['note']);
    }

    /**
     * "Vox vs Voice Realtime" + "Regras para IA": a Vox task reads the Vox doc,
     * a Voice-Realtime task reads the realtime surface doc, and a mixed task must
     * read BOTH plus ADR 0003 before any change.
     */
    public function test_vox_vs_voice_realtime_routing(): void
    {
        $svc = $this->service();

        $vox = $svc->routeVoiceTopic('improve Atlas Vox dictation');
        $this->assertSame('vox', $vox['topic']);
        $this->assertSame(['atlas-vox-operational-thinking-interface.md'], $vox['read_docs']);

        $realtime = $svc->routeVoiceTopic('fix the Voice Realtime LiveKit turns');
        $this->assertSame('voice_realtime', $realtime['topic']);
        $this->assertSame(['atlas-ai-voice-realtime-surface.md'], $realtime['read_docs']);

        $mixed = $svc->routeVoiceTopic('reconcile Atlas Vox with the Voice Realtime surface');
        $this->assertSame('mixed', $mixed['topic']);
        $this->assertSame(
            ['atlas-vox-operational-thinking-interface.md', 'atlas-ai-voice-realtime-surface.md', 'ADR 0003'],
            $mixed['read_docs'],
        );
    }

    /**
     * "Contratos": the modal follows the ordered 7-layer contract with Patamares
     * and Versoes as distinct, separately-ordered categories.
     */
    public function test_modal_has_seven_layers_with_patamares_before_versoes(): void
    {
        $svc = $this->service();
        $layers = $svc->modalLayers();

        $this->assertCount(7, $layers);
        $this->assertContains('Patamares', $layers);
        $this->assertContains('Versoes', $layers);
        // Patamares must be its own category, ordered before Versoes.
        $this->assertLessThan(
            array_search('Versoes', $layers, true),
            array_search('Patamares', $layers, true),
        );
    }
}
