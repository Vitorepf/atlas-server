<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Knowledge;

use App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AKIF Phase 1 — source packet registry contract.
 *
 * Exercita o caminho REAL (register/dedup/transicoes/list) contra a migration
 * canonica de atlas_knowledge_source_packets em sqlite :memory:, cobrindo os
 * invariantes do docblock do service:
 *  - validacao de source_type / origin_uri / source_hash (sha256 hex)
 *  - quarentena imune: todo packet nasce `received`; ready() e promocao explicita
 *  - dedup idempotente por source_hash
 *  - privacy secret -> provider_safe=false (deterministico)
 *  - listProviderSafe so expoe ready + provider_safe
 *  - degrade-safe com tabela ausente (table_missing, sem crash)
 */
class AtlasKnowledgeSourcePacketRegistryServiceTest extends TestCase
{
    private AtlasKnowledgeSourcePacketRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_knowledge_source_packets');
        $migration = require database_path('migrations/2026_05_25_040000_create_atlas_knowledge_source_packets_table.php');
        $migration->up();

        $this->service = new AtlasKnowledgeSourcePacketRegistryService;
    }

    private function validParams(array $overrides = []): array
    {
        return array_merge([
            'source_type' => AtlasKnowledgeSourcePacketRegistryService::SOURCE_TYPE_DOC,
            'origin_uri' => 'docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md',
            'source_hash' => hash('sha256', 'conteudo-canonico'),
            'ingester' => 'unit-test',
        ], $overrides);
    }

    public function test_register_rejects_non_canonical_source_type_bad_uri_and_bad_hash(): void
    {
        $badType = $this->service->register($this->validParams(['source_type' => 'tweet']));
        $badUri = $this->service->register($this->validParams(['origin_uri' => '']));
        $badHash = $this->service->register($this->validParams(['source_hash' => 'not-a-sha256']));

        $this->assertFalse($badType['ok']);
        $this->assertSame('invalid_source_type', $badType['status']);
        $this->assertFalse($badUri['ok']);
        $this->assertSame('invalid_origin_uri', $badUri['status']);
        $this->assertFalse($badHash['ok']);
        $this->assertSame('invalid_source_hash', $badHash['status']);
    }

    public function test_register_creates_packet_in_received_quarantine_with_lineage_and_receipt_hash(): void
    {
        $result = $this->service->register($this->validParams());

        $this->assertTrue($result['ok']);
        $this->assertSame('registered', $result['status']);
        $this->assertSame('atlas.knowledge.source_packet.v1', $result['schema_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['receipt_hash']);

        $packet = $this->service->findBySourceHash($this->validParams()['source_hash']);
        $this->assertNotNull($packet);
        // Cognitive immune: nasce em quarentena, nunca ready por default.
        $this->assertSame(AtlasKnowledgeSourcePacketRegistryService::INGESTION_RECEIVED, $packet->ingestion_status);
        $this->assertTrue((bool) $packet->provider_safe);
        // Lineage minimo obrigatorio.
        $this->assertSame('doc', $packet->lineage['source_type']);
        $this->assertSame('unit-test', $packet->lineage['ingester']);
        $this->assertArrayHasKey('ingested_at', $packet->lineage);
        $this->assertArrayHasKey('origin_uri', $packet->lineage);
    }

    public function test_register_is_idempotent_by_source_hash(): void
    {
        $first = $this->service->register($this->validParams());
        $second = $this->service->register($this->validParams(['origin_uri' => 'outro/caminho.md']));

        $this->assertSame('registered', $first['status']);
        $this->assertSame('already_registered', $second['status']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['packet_id'], $second['packet_id']);
        $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
    }

    public function test_secret_privacy_forces_provider_unsafe(): void
    {
        $hash = hash('sha256', 'segredo');
        $result = $this->service->register($this->validParams([
            'source_hash' => $hash,
            'privacy_status' => AtlasKnowledgeSourcePacketRegistryService::PRIVACY_SECRET,
        ]));

        $this->assertTrue($result['ok']);
        $packet = $this->service->findBySourceHash($hash);
        $this->assertSame('secret', $packet->privacy_status);
        $this->assertFalse((bool) $packet->provider_safe);
    }

    public function test_ready_and_block_transitions_and_provider_safe_listing(): void
    {
        $readyHash = hash('sha256', 'fonte-a');
        $blockedHash = hash('sha256', 'fonte-b');
        $ready = $this->service->register($this->validParams(['source_hash' => $readyHash]));
        $blocked = $this->service->register($this->validParams(['source_hash' => $blockedHash]));

        // Antes de qualquer promocao explicita, nada e listado downstream.
        $this->assertSame([], $this->service->listProviderSafe());

        $readyResult = $this->service->ready((string) $ready['packet_id']);
        $blockResult = $this->service->block((string) $blocked['packet_id'], 'conteudo sensivel detectado');

        $this->assertSame('transitioned_to_ready', $readyResult['status']);
        $this->assertSame('transitioned_to_blocked', $blockResult['status']);
        $this->assertSame(
            'conteudo sensivel detectado',
            $this->service->findBySourceHash($blockedHash)->blocking_reason,
        );

        $listed = $this->service->listProviderSafe();
        $this->assertCount(1, $listed);
        $this->assertSame($readyHash, $listed[0]['source_hash']);
        $this->assertSame('doc', $listed[0]['source_type']);
        $this->assertArrayHasKey('lineage', $listed[0]);

        $missing = $this->service->ready('00000000-0000-0000-0000-000000000000');
        $this->assertFalse($missing['ok']);
        $this->assertSame('packet_not_found', $missing['status']);
    }

    public function test_missing_table_degrades_to_table_missing_envelope_without_crash(): void
    {
        Schema::dropIfExists('atlas_knowledge_source_packets');

        $result = $this->service->register($this->validParams());

        $this->assertFalse($result['ok']);
        $this->assertSame('table_missing', $result['status']);
        $this->assertNull($this->service->findBySourceHash($this->validParams()['source_hash']));
        $this->assertSame([], $this->service->listProviderSafe());
    }
}
