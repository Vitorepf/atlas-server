<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\ContextIdRemap;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 1 Phase 2 (Forward Substitution).
 *
 * Cobre o uso de ContextIdRemap dentro de AiContextPack.toPromptSection():
 * UUIDs em source_id aparecem como "[N]" provider-safe quando remap esta presente.
 */
class AiContextPackIdRemapForwardSubstitutionTest extends TestCase
{
    public function test_to_prompt_section_renders_raw_uuid_without_remap(): void
    {
        $uuid = 'uuid-real-aaaa-bbbb';

        $pack = new AiContextPack(
            data: $this->buildData($uuid),
            contextRefs: [['type' => 'memory_entry', 'id' => $uuid]],
            idRemap: null,
        );

        $section = $pack->toPromptSection();
        $this->assertStringContainsString($uuid, $section);
        $this->assertStringNotContainsString('[0]', $section);
    }

    public function test_to_prompt_section_substitutes_uuid_with_label_when_remap_present(): void
    {
        $uuid = 'uuid-real-xxxx-yyyy';

        $remap = new ContextIdRemap(
            contextPackId: 'ctx_test_forward',
            mappingHash: ContextIdRemap::hashOf(['0' => $uuid], []),
            internalToReal: ['0' => $uuid],
            realToInternal: [$uuid => '0'],
            refTypes: ['0' => ['type' => 'memory_entry']],
        );

        $pack = new AiContextPack(
            data: $this->buildData($uuid),
            contextRefs: [['type' => 'memory_entry', 'id' => $uuid]],
            idRemap: $remap,
        );

        $section = $pack->toPromptSection();

        // O UUID real NAO deve aparecer na string emitida.
        $this->assertStringNotContainsString($uuid, $section);
        // Label provider-safe DEVE aparecer.
        $this->assertStringContainsString('[0]', $section);
    }

    public function test_pack_exposes_id_remap_via_getter(): void
    {
        $remap = ContextIdRemap::empty('ctx_test_getter');
        $pack = new AiContextPack(data: $this->buildData('uuid-a'), contextRefs: [], idRemap: $remap);
        $this->assertSame($remap, $pack->idRemap());
    }

    public function test_pack_id_remap_default_null(): void
    {
        $pack = new AiContextPack(data: $this->buildData('uuid-a'), contextRefs: []);
        $this->assertNull($pack->idRemap());
    }

    /**
     * Constroi um payload minimo com um registry item que expoe source_id.
     */
    private function buildData(string $sourceId): array
    {
        return [
            'task' => [
                'type' => 'direct',
                'desired_mode' => 'direct',
                'objective' => 'teste forward substitution',
                'risk_level' => 'low',
                'domain' => 'test',
                'intent' => 'test',
            ],
            'surface' => [
                'kind' => 'cli',
                'workspace' => '/tmp/test',
            ],
            'conversation' => [
                'thread_id' => null,
                'thread_title' => null,
                'thread_summary' => null,
                'source' => null,
                'context_window' => null,
                'recent_turns' => [],
                'instruction' => null,
            ],
            'continuity' => [
                'active_state' => null,
                'latest_compaction' => null,
                'latest_provider_handoff' => null,
            ],
            'memory' => [
                'recall' => [],
                'registry' => [
                    [
                        'id' => $sourceId,
                        'type' => 'decision',
                        'scope' => 'global',
                        'title' => 'Sample decision',
                        'priority' => 50,
                        'importance' => 3,
                        'source_type' => 'manual',
                        'source_id' => $sourceId, // UUID exposto aqui — deve virar [N] com remap.
                        'reason' => 'teste',
                        'summary' => 'sample summary',
                    ],
                ],
                'verbatim' => [],
                'semantic' => [],
            ],
            'constraints' => [
                'must_do' => [],
                'must_not_do' => [],
                'privacy_class' => 'normal',
            ],
            'open_questions' => [],
            'excluded_context' => [],
        ];
    }
}
