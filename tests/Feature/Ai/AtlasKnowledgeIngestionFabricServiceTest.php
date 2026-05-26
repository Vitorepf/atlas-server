<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasKnowledgeSourcePacket;
use App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService;
use Tests\Concerns\CreatesAtlasKnowledgeSourcePacketsTable;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — AKIF (Knowledge Ingestion Fabric) Phase 1 tests.
 *
 * Cobertura:
 *  - register valida source_type, source_hash format, origin_uri
 *  - dedup canonico por source_hash
 *  - privacy_status influencia provider_safe deterministicamente
 *  - ingestion_status default = received (quarentena)
 *  - ready transitions status
 *  - block transitions status com reason
 *  - listProviderSafe retorna apenas ready+provider_safe
 *  - hash determinismo
 */
class AtlasKnowledgeIngestionFabricServiceTest extends TestCase
{
    use CreatesAtlasKnowledgeSourcePacketsTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasKnowledgeSourcePacketsTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasKnowledgeSourcePacketsTable();
        parent::tearDown();
    }

    public function test_register_validates_source_type(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $result = $service->register([
            'source_type' => 'invalid_type',
            'origin_uri' => 'https://example.com/doc',
            'source_hash' => str_repeat('a', 64),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_source_type', $result['status']);
    }

    public function test_register_validates_source_hash_format(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $result = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'https://example.com/doc',
            'source_hash' => 'too-short',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_source_hash', $result['status']);
    }

    public function test_register_validates_origin_uri_required(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $result = $service->register([
            'source_type' => 'doc',
            'origin_uri' => '',
            'source_hash' => str_repeat('a', 64),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_origin_uri', $result['status']);
    }

    public function test_register_creates_packet_with_canonical_envelope(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $hash = hash('sha256', 'content-test');
        $result = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/test.md',
            'source_hash' => $hash,
            'language' => 'pt',
            'confidence' => 0.95,
            'privacy_status' => 'normal',
            'ingester' => 'cli',
            'metadata' => ['origin' => 'test'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('registered', $result['status']);
        $this->assertNotNull($result['packet_id']);
        $this->assertSame($hash, $result['source_hash']);
        $this->assertSame('atlas.knowledge.source_packet.v1', $result['schema_version']);

        $packet = AtlasKnowledgeSourcePacket::find($result['packet_id']);
        $this->assertNotNull($packet);
        $this->assertSame('doc', $packet->source_type);
        $this->assertSame('docs/test.md', $packet->origin_uri);
        $this->assertSame('pt', $packet->language);
        $this->assertEqualsWithDelta(0.95, $packet->confidence, 0.001);
        $this->assertSame('normal', $packet->privacy_status);
        $this->assertTrue($packet->provider_safe);
        $this->assertSame('received', $packet->ingestion_status);
        $this->assertSame('atlas.knowledge.source_packet.v1', $packet->schema_version);
    }

    public function test_register_is_idempotent_on_same_source_hash(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $hash = hash('sha256', 'idempotent-content');

        $first = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/x.md',
            'source_hash' => $hash,
        ]);

        $second = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/x.md',
            'source_hash' => $hash,
        ]);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame('registered', $first['status']);
        $this->assertSame('already_registered', $second['status']);
        $this->assertSame($first['packet_id'], $second['packet_id']);
    }

    public function test_secret_privacy_blocks_provider_safe(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $result = $service->register([
            'source_type' => 'manual',
            'origin_uri' => 'private://secrets.md',
            'source_hash' => hash('sha256', 'secret-content'),
            'privacy_status' => 'secret',
        ]);

        $this->assertTrue($result['ok']);
        $packet = AtlasKnowledgeSourcePacket::find($result['packet_id']);
        $this->assertSame('secret', $packet->privacy_status);
        $this->assertFalse($packet->provider_safe);
    }

    public function test_sensitive_privacy_still_provider_safe(): void
    {
        // Por design v1: sensitive nao bloqueia provider_safe; apenas secret bloqueia.
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $result = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/sens.md',
            'source_hash' => hash('sha256', 'sensitive-content'),
            'privacy_status' => 'sensitive',
        ]);

        $packet = AtlasKnowledgeSourcePacket::find($result['packet_id']);
        $this->assertSame('sensitive', $packet->privacy_status);
        $this->assertTrue($packet->provider_safe);
    }

    public function test_ready_transitions_status(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $registered = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/y.md',
            'source_hash' => hash('sha256', 'ready-content'),
        ]);

        $readyResult = $service->ready($registered['packet_id']);

        $this->assertTrue($readyResult['ok']);
        $this->assertSame('transitioned_to_ready', $readyResult['status']);

        $packet = AtlasKnowledgeSourcePacket::find($registered['packet_id']);
        $this->assertSame('ready', $packet->ingestion_status);
    }

    public function test_block_transitions_status_with_reason(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $registered = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/block.md',
            'source_hash' => hash('sha256', 'blocked-content'),
        ]);

        $blocked = $service->block($registered['packet_id'], 'PII detected in extraction');

        $this->assertTrue($blocked['ok']);
        $this->assertSame('transitioned_to_blocked', $blocked['status']);

        $packet = AtlasKnowledgeSourcePacket::find($registered['packet_id']);
        $this->assertSame('blocked', $packet->ingestion_status);
        $this->assertSame('PII detected in extraction', $packet->blocking_reason);
    }

    public function test_list_provider_safe_filters_correctly(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $p1 = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'a.md',
            'source_hash' => hash('sha256', 'A'),
        ]);
        $p2 = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'b.md',
            'source_hash' => hash('sha256', 'B'),
        ]);
        $p3 = $service->register([
            'source_type' => 'youtube',
            'origin_uri' => 'https://youtube.com/x',
            'source_hash' => hash('sha256', 'C'),
            'privacy_status' => 'secret', // provider_safe=false
        ]);

        $service->ready($p1['packet_id']);
        $service->ready($p2['packet_id']);
        $service->ready($p3['packet_id']); // ready mas provider_safe=false

        $safe = $service->listProviderSafe();
        $this->assertCount(2, $safe, 'apenas p1 e p2 sao provider_safe + ready.');

        $safeYoutube = $service->listProviderSafe('youtube');
        $this->assertCount(0, $safeYoutube, 'p3 secret esta excluido de provider-safe list.');

        $safeDoc = $service->listProviderSafe('doc');
        $this->assertCount(2, $safeDoc);
    }

    public function test_find_by_source_hash_returns_packet(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $hash = hash('sha256', 'findable');
        $result = $service->register([
            'source_type' => 'doc',
            'origin_uri' => 'docs/findable.md',
            'source_hash' => $hash,
        ]);

        $found = $service->findBySourceHash($hash);

        $this->assertNotNull($found);
        $this->assertSame($result['packet_id'], (string) $found->id);
    }

    public function test_find_by_source_hash_rejects_invalid_format(): void
    {
        $service = new AtlasKnowledgeSourcePacketRegistryService;

        $this->assertNull($service->findBySourceHash('not-hex'));
    }

    public function test_static_validators_work(): void
    {
        $this->assertTrue(AtlasKnowledgeSourcePacketRegistryService::isValidSourceType('doc'));
        $this->assertFalse(AtlasKnowledgeSourcePacketRegistryService::isValidSourceType('email'));
        $this->assertTrue(AtlasKnowledgeSourcePacketRegistryService::isValidPrivacyStatus('secret'));
        $this->assertFalse(AtlasKnowledgeSourcePacketRegistryService::isValidPrivacyStatus('top-secret'));
        $this->assertTrue(AtlasKnowledgeSourcePacketRegistryService::isValidIngestionStatus('ready'));
        $this->assertFalse(AtlasKnowledgeSourcePacketRegistryService::isValidIngestionStatus('done'));
    }
}
