<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\ValueObjects\ContextIdRemap;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 1 (Integer ID Mapping).
 *
 * Cobre o ValueObject ContextIdRemap (pure, sem DB).
 * Tests de Service vivem em AtlasContextIdRemapServiceTest.
 */
class ContextIdRemapValueObjectTest extends TestCase
{
    public function test_empty_remap_is_size_zero_and_idempotent(): void
    {
        $remap = ContextIdRemap::empty('ctx_test_001');

        $this->assertTrue($remap->isEmpty());
        $this->assertSame(0, $remap->size());
        $this->assertSame('ctx_test_001', $remap->contextPackId);
        $this->assertSame('atlas.context.id_remap.v1', $remap->schemaVersion);
        $this->assertTrue($remap->providerSafe);
        $this->assertNull($remap->realId('0'));
        $this->assertNull($remap->internalLabel('uuid-not-mapped'));
    }

    public function test_internal_label_and_real_id_round_trip(): void
    {
        $remap = new ContextIdRemap(
            contextPackId: 'ctx_test_002',
            mappingHash: ContextIdRemap::hashOf(['0' => 'uuid-a', '1' => 'uuid-b'], []),
            internalToReal: ['0' => 'uuid-a', '1' => 'uuid-b'],
            realToInternal: ['uuid-a' => '0', 'uuid-b' => '1'],
            refTypes: [],
        );

        $this->assertSame('[0]', $remap->internalLabel('uuid-a'));
        $this->assertSame('[1]', $remap->internalLabel('uuid-b'));
        $this->assertSame('uuid-a', $remap->realId('[0]'));
        $this->assertSame('uuid-a', $remap->realId('0')); // sem brackets aceito
        $this->assertSame('uuid-b', $remap->realId('[1]'));
        $this->assertNull($remap->realId('[99]'));
        $this->assertNull($remap->internalLabel('uuid-not-exist'));
    }

    public function test_real_ids_batch_handles_missing_labels(): void
    {
        $remap = new ContextIdRemap(
            contextPackId: 'ctx_test_003',
            mappingHash: ContextIdRemap::hashOf(['0' => 'uuid-x', '1' => 'uuid-y'], []),
            internalToReal: ['0' => 'uuid-x', '1' => 'uuid-y'],
            realToInternal: ['uuid-x' => '0', 'uuid-y' => '1'],
            refTypes: [],
        );

        $result = $remap->realIdsBatch(['[0]', '[1]', '[2]', '0']);

        $this->assertSame(['uuid-x', 'uuid-y', null, 'uuid-x'], $result);
    }

    public function test_hash_is_deterministic_across_order_of_construction(): void
    {
        $hashA = ContextIdRemap::hashOf(
            ['0' => 'uuid-z', '1' => 'uuid-a'],
            ['0' => ['type' => 'memory_entry'], '1' => ['type' => 'verbatim']]
        );

        // Mesma estrutura, mas ordem de array diferente.
        $hashB = ContextIdRemap::hashOf(
            ['1' => 'uuid-a', '0' => 'uuid-z'],
            ['1' => ['type' => 'verbatim'], '0' => ['type' => 'memory_entry']]
        );

        $this->assertSame($hashA, $hashB, 'mapping_hash deve ser deterministico independente da ordem do array.');
    }

    public function test_hash_changes_when_mapping_changes(): void
    {
        $hashA = ContextIdRemap::hashOf(['0' => 'uuid-a'], []);
        $hashB = ContextIdRemap::hashOf(['0' => 'uuid-b'], []);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_entries_yields_sorted_by_numeric_internal_key(): void
    {
        $remap = new ContextIdRemap(
            contextPackId: 'ctx_test_004',
            mappingHash: ContextIdRemap::hashOf(['2' => 'uuid-c', '0' => 'uuid-a', '1' => 'uuid-b'], []),
            internalToReal: ['2' => 'uuid-c', '0' => 'uuid-a', '1' => 'uuid-b'],
            realToInternal: ['uuid-c' => '2', 'uuid-a' => '0', 'uuid-b' => '1'],
            refTypes: [
                '0' => ['type' => 'memory_entry'],
                '1' => ['type' => 'verbatim'],
                '2' => ['type' => 'semantic_note'],
            ],
        );

        $collected = [];
        foreach ($remap->entries() as $entry) {
            $collected[] = $entry;
        }

        $this->assertCount(3, $collected);
        $this->assertSame(['0', 'uuid-a', ['type' => 'memory_entry']], $collected[0]);
        $this->assertSame(['1', 'uuid-b', ['type' => 'verbatim']], $collected[1]);
        $this->assertSame(['2', 'uuid-c', ['type' => 'semantic_note']], $collected[2]);
    }

    public function test_to_array_contains_canonical_fields(): void
    {
        $remap = new ContextIdRemap(
            contextPackId: 'ctx_test_005',
            mappingHash: 'deadbeef',
            internalToReal: ['0' => 'uuid-w'],
            realToInternal: ['uuid-w' => '0'],
            refTypes: ['0' => ['type' => 'memory_entry']],
        );

        $array = $remap->toArray();

        $this->assertSame('atlas.context.id_remap.v1', $array['schema_version']);
        $this->assertSame('ctx_test_005', $array['context_pack_id']);
        $this->assertSame('deadbeef', $array['mapping_hash']);
        $this->assertSame('context_pack', $array['scope']);
        $this->assertTrue($array['provider_safe']);
        $this->assertSame(['0' => 'uuid-w'], $array['internal_to_real']);
        $this->assertSame(['uuid-w' => '0'], $array['real_to_internal']);
        $this->assertSame(['0' => ['type' => 'memory_entry']], $array['ref_types']);
        $this->assertSame(1, $array['size']);
    }

    public function test_ref_type_lookup_accepts_bracket_and_plain(): void
    {
        $remap = new ContextIdRemap(
            contextPackId: 'ctx_test_006',
            mappingHash: 'cafebabe',
            internalToReal: ['0' => 'uuid-r'],
            realToInternal: ['uuid-r' => '0'],
            refTypes: ['0' => ['type' => 'memory_entry', 'privacy_class' => 'normal']],
        );

        $expected = ['type' => 'memory_entry', 'privacy_class' => 'normal'];

        $this->assertSame($expected, $remap->refType('[0]'));
        $this->assertSame($expected, $remap->refType('0'));
        $this->assertNull($remap->refType('[99]'));
    }
}
