<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Context\AtlasContextIdRemapService;
use App\Services\Ai\ValueObjects\ContextIdRemap;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 1 (Integer ID Mapping).
 *
 * Cobre o Service AtlasContextIdRemapService.
 *
 * Testes de persistencia DB vivem em Feature/Ai/AtlasContextIdRemapPersistenceTest.
 * Aqui cobrimos:
 *  - Feature flag pass-through
 *  - Remap logic determinismo
 *  - Dedup, refs invalidos
 *  - Round-trip canonico (CRITICO para audit trail)
 *  - parseResponse extraindo [N] refs
 */
class AtlasContextIdRemapServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Por default a feature flag e false. Cada teste habilita conforme necessario.
        Config::set('atlas.cognition.id_remap.enabled', false);
        Config::set('atlas.cognition.id_remap.ttl_seconds', 3600);
    }

    public function test_disabled_flag_returns_empty_remap(): void
    {
        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-1', 'type' => 'memory_entry'],
            ['id' => 'uuid-2', 'type' => 'verbatim'],
        ];

        $remap = $service->remap($refs, 'ctx_test_disabled');

        $this->assertTrue($remap->isEmpty());
        $this->assertSame(0, $remap->size());
        $this->assertSame('ctx_test_disabled', $remap->contextPackId);
    }

    public function test_enabled_flag_builds_sequential_internal_ids(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-alpha', 'type' => 'memory_entry'],
            ['id' => 'uuid-beta', 'type' => 'verbatim'],
            ['id' => 'uuid-gamma', 'type' => 'semantic_note'],
        ];

        $remap = $service->remap($refs, 'ctx_test_enabled_001');

        $this->assertFalse($remap->isEmpty());
        $this->assertSame(3, $remap->size());
        $this->assertSame('[0]', $remap->internalLabel('uuid-alpha'));
        $this->assertSame('[1]', $remap->internalLabel('uuid-beta'));
        $this->assertSame('[2]', $remap->internalLabel('uuid-gamma'));
        $this->assertSame('uuid-alpha', $remap->realId('[0]'));
        $this->assertSame('uuid-beta', $remap->realId('[1]'));
        $this->assertSame('uuid-gamma', $remap->realId('[2]'));
    }

    public function test_round_trip_encode_decode_preserves_uuid_set(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $originalIds = ['uuid-aaa', 'uuid-bbb', 'uuid-ccc', 'uuid-ddd'];
        $refs = array_map(fn ($id) => ['id' => $id, 'type' => 'memory_entry'], $originalIds);

        $remap = $service->remap($refs, 'ctx_round_trip');

        // Encode: real -> internal
        $internalLabels = array_map(fn ($id) => $remap->internalLabel($id), $originalIds);
        $this->assertSame(['[0]', '[1]', '[2]', '[3]'], $internalLabels);

        // Decode: internal -> real (round-trip)
        $decoded = $remap->realIdsBatch($internalLabels);
        $this->assertSame($originalIds, $decoded);
    }

    public function test_dedup_same_uuid_appears_twice_uses_same_internal_id(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-dup', 'type' => 'memory_entry'],
            ['id' => 'uuid-other', 'type' => 'memory_entry'],
            ['id' => 'uuid-dup', 'type' => 'verbatim'], // mesmo UUID, type diferente
        ];

        $remap = $service->remap($refs, 'ctx_dedup');

        $this->assertSame(2, $remap->size(), 'UUIDs duplicados devem virar UM unico internal_id.');
        $this->assertSame('[0]', $remap->internalLabel('uuid-dup'));
        $this->assertSame('[1]', $remap->internalLabel('uuid-other'));
    }

    public function test_invalid_refs_are_silently_skipped(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-valid', 'type' => 'memory_entry'],
            ['type' => 'memory_entry'], // sem id
            ['id' => '', 'type' => 'memory_entry'], // id vazio
            ['id' => '   ', 'type' => 'memory_entry'], // id whitespace
            'not-an-array', // tipo errado
            ['id' => 'uuid-another'],
        ];

        $remap = $service->remap($refs, 'ctx_invalid');

        $this->assertSame(2, $remap->size());
        $this->assertSame('[0]', $remap->internalLabel('uuid-valid'));
        $this->assertSame('[1]', $remap->internalLabel('uuid-another'));
    }

    public function test_ref_type_metadata_is_preserved_per_internal_id(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-x', 'type' => 'memory_entry', 'privacy_class' => 'normal'],
            ['id' => 'uuid-y', 'type' => 'verbatim', 'privacy_class' => 'sensitive', 'source_type' => 'manual'],
        ];

        $remap = $service->remap($refs, 'ctx_ref_types');

        $this->assertSame(
            ['type' => 'memory_entry', 'privacy_class' => 'normal'],
            $remap->refType('[0]')
        );
        $this->assertSame(
            ['type' => 'verbatim', 'source_type' => 'manual', 'privacy_class' => 'sensitive'],
            $remap->refType('[1]')
        );
    }

    public function test_hash_is_deterministic_across_invocations(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-1', 'type' => 'memory_entry'],
            ['id' => 'uuid-2', 'type' => 'verbatim'],
        ];

        $remapA = $service->remap($refs, 'ctx_hash_a');
        $remapB = $service->remap($refs, 'ctx_hash_b');

        // mapping_hash deve ser o mesmo (contextPackId nao entra no hash).
        $this->assertSame($remapA->mappingHash, $remapB->mappingHash);
    }

    public function test_hash_changes_when_order_of_unique_refs_differs(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        $refsA = [
            ['id' => 'uuid-1', 'type' => 'memory_entry'],
            ['id' => 'uuid-2', 'type' => 'verbatim'],
        ];
        $refsB = [
            ['id' => 'uuid-2', 'type' => 'verbatim'],
            ['id' => 'uuid-1', 'type' => 'memory_entry'],
        ];

        $remapA = $service->remap($refsA, 'ctx_order_a');
        $remapB = $service->remap($refsB, 'ctx_order_b');

        // Ordem afeta internal_id assignment, portanto afeta o hash.
        $this->assertNotSame($remapA->mappingHash, $remapB->mappingHash);
        $this->assertSame('[0]', $remapA->internalLabel('uuid-1'));
        $this->assertSame('[0]', $remapB->internalLabel('uuid-2'));
    }

    public function test_parse_response_returns_empty_when_disabled(): void
    {
        $service = new AtlasContextIdRemapService;

        $result = $service->parseResponse('supersede [0] with [1]', 'ctx_disabled');

        $this->assertSame([], $result);
    }

    public function test_parse_response_returns_empty_when_remap_not_persisted(): void
    {
        Config::set('atlas.cognition.id_remap.enabled', true);

        $service = new AtlasContextIdRemapService;

        // Sem chamar remap antes - get() retorna null pois nao ha DB no unit test.
        $result = $service->parseResponse('supersede [0] with [1]', 'ctx_no_persist');

        $this->assertSame([], $result);
    }
}
