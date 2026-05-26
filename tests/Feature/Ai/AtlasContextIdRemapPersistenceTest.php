<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasContextIdRemap;
use App\Services\Ai\Context\AtlasContextIdRemapService;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesAtlasContextIdRemapTable;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 1 (Integer ID Mapping).
 *
 * Feature tests da persistencia em atlas_context_id_remaps.
 * Cria a tabela em SQLite via trait (evita rodar migration core pgsql-only).
 */
class AtlasContextIdRemapPersistenceTest extends TestCase
{
    use CreatesAtlasContextIdRemapTable;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('atlas.cognition.id_remap.enabled', true);
        Config::set('atlas.cognition.id_remap.ttl_seconds', 3600);

        $this->createAtlasContextIdRemapTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasContextIdRemapTable();
        parent::tearDown();
    }

    public function test_remap_persists_row_with_canonical_schema(): void
    {
        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-mem-1', 'type' => 'memory_entry', 'privacy_class' => 'normal'],
            ['id' => 'uuid-verb-1', 'type' => 'verbatim', 'privacy_class' => 'sensitive'],
        ];

        $remap = $service->remap($refs, 'ctx_persist_001');

        $this->assertSame(2, $remap->size());

        $row = AtlasContextIdRemap::query()
            ->where('context_pack_id', 'ctx_persist_001')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($row, 'Row deve ter sido persistida.');
        $this->assertSame('atlas.context.id_remap.v1', $row->schema_version);
        $this->assertSame($remap->mappingHash, $row->mapping_hash);
        $this->assertSame(['0' => 'uuid-mem-1', '1' => 'uuid-verb-1'], $row->internal_to_real);
        $this->assertSame(['uuid-mem-1' => '0', 'uuid-verb-1' => '1'], $row->real_to_internal);
        $this->assertTrue($row->provider_safe);
        $this->assertSame('context_pack', $row->scope);
    }

    public function test_idempotent_when_same_mapping_hash(): void
    {
        $service = new AtlasContextIdRemapService;

        $refs = [
            ['id' => 'uuid-a', 'type' => 'memory_entry'],
            ['id' => 'uuid-b', 'type' => 'verbatim'],
        ];

        $service->remap($refs, 'ctx_idempotent');
        $service->remap($refs, 'ctx_idempotent');

        $count = AtlasContextIdRemap::query()
            ->where('context_pack_id', 'ctx_idempotent')
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(1, $count, 'Re-chamada com mesmo mapping_hash deve ser idempotente.');
    }

    public function test_mapping_drift_soft_deletes_old_and_inserts_new(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [['id' => 'uuid-1', 'type' => 'memory_entry']],
            'ctx_drift'
        );

        $service->remap(
            [['id' => 'uuid-1', 'type' => 'memory_entry'], ['id' => 'uuid-2', 'type' => 'verbatim']],
            'ctx_drift'
        );

        $active = AtlasContextIdRemap::query()
            ->where('context_pack_id', 'ctx_drift')
            ->whereNull('deleted_at')
            ->count();

        $softDeleted = AtlasContextIdRemap::query()
            ->where('context_pack_id', 'ctx_drift')
            ->withTrashed()
            ->whereNotNull('deleted_at')
            ->count();

        $this->assertSame(1, $active, 'Apenas um active por context_pack_id.');
        $this->assertSame(1, $softDeleted, 'Antigo deve estar soft-deleted.');
    }

    public function test_reverse_lookup_retrieves_uuid_and_increments_counter(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [
                ['id' => 'uuid-X', 'type' => 'memory_entry'],
                ['id' => 'uuid-Y', 'type' => 'verbatim'],
            ],
            'ctx_reverse'
        );

        $resolved = $service->reverseLookup('[1]', 'ctx_reverse');

        $this->assertSame('uuid-Y', $resolved);

        $row = AtlasContextIdRemap::query()
            ->where('context_pack_id', 'ctx_reverse')
            ->whereNull('deleted_at')
            ->first();

        $this->assertSame(1, $row->reverse_lookup_count);
    }

    public function test_parse_response_extracts_internal_labels_from_text(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [
                ['id' => 'uuid-old', 'type' => 'memory_entry'],
                ['id' => 'uuid-new', 'type' => 'memory_entry'],
                ['id' => 'uuid-other', 'type' => 'memory_entry'],
            ],
            'ctx_parse'
        );

        $providerResponse = 'Based on the recall, supersede [0] with [1]. The [2] is compatible.';

        $resolvedIds = $service->parseResponse($providerResponse, 'ctx_parse');

        $this->assertSame(['uuid-old', 'uuid-new', 'uuid-other'], $resolvedIds);
    }

    public function test_parse_response_dedups_repeated_refs(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [['id' => 'uuid-only', 'type' => 'memory_entry']],
            'ctx_parse_dedup'
        );

        $providerResponse = '[0] is important. [0] supersedes nothing. Re-check [0].';

        $resolvedIds = $service->parseResponse($providerResponse, 'ctx_parse_dedup');

        $this->assertSame(['uuid-only'], $resolvedIds);
    }

    public function test_parse_response_ignores_unmapped_refs(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [['id' => 'uuid-mapped', 'type' => 'memory_entry']],
            'ctx_parse_unmapped'
        );

        $providerResponse = '[0] is OK but [99] does not exist.';

        $resolvedIds = $service->parseResponse($providerResponse, 'ctx_parse_unmapped');

        $this->assertSame(['uuid-mapped'], $resolvedIds);
    }

    public function test_parse_response_handles_type_prefix(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [['id' => 'uuid-typed', 'type' => 'memory_entry']],
            'ctx_parse_typed'
        );

        $providerResponse = 'See [memory_entry:0] for context.';

        $resolvedIds = $service->parseResponse($providerResponse, 'ctx_parse_typed');

        $this->assertSame(['uuid-typed'], $resolvedIds);
    }

    public function test_parse_response_detailed_preserves_order_and_marks_misses(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [
                ['id' => 'uuid-first', 'type' => 'memory_entry'],
                ['id' => 'uuid-second', 'type' => 'verbatim'],
            ],
            'ctx_detailed'
        );

        $providerResponse = '[1] then [99] then [0].';

        $detailed = $service->parseResponseDetailed($providerResponse, 'ctx_detailed');

        $this->assertCount(3, $detailed);
        $this->assertSame(['internal' => '1', 'real' => 'uuid-second', 'ref_type' => ['type' => 'verbatim']], $detailed[0]);
        $this->assertSame(['internal' => '99', 'real' => null, 'ref_type' => []], $detailed[1]);
        $this->assertSame(['internal' => '0', 'real' => 'uuid-first', 'ref_type' => ['type' => 'memory_entry']], $detailed[2]);
    }

    public function test_get_returns_null_for_expired_remap(): void
    {
        $service = new AtlasContextIdRemapService;

        $service->remap(
            [['id' => 'uuid-exp', 'type' => 'memory_entry']],
            'ctx_expired'
        );

        AtlasContextIdRemap::query()
            ->where('context_pack_id', 'ctx_expired')
            ->whereNull('deleted_at')
            ->update(['expires_at' => now()->subHour()]);

        $resolved = $service->reverseLookup('[0]', 'ctx_expired');

        $this->assertNull($resolved);
    }
}
