<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasKnowledgeIngestionFabricService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class KnowledgeIngestionFabricTest extends TestCase
{
    public function test_text_source_normalizes_to_source_packet_with_lineage_and_receipts(): void
    {
        $payload = app(AtlasKnowledgeIngestionFabricService::class)->normalize([
            'source_type' => 'text',
            'content' => 'Atlas retrieval context source',
            'language' => 'en',
            'provider_target' => 'external',
        ]);

        $this->assertSame(AtlasKnowledgeIngestionFabricService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('atlas.knowledge.source_packet.v1', data_get($payload, 'source_packet.schema_version'));
        $this->assertSame('text', data_get($payload, 'source_packet.source_type'));
        $this->assertSame('en', data_get($payload, 'source_packet.language'));
        $this->assertSame('normalized', data_get($payload, 'source_packet.ingestion_status'));
        $this->assertSame(1, count(data_get($payload, 'source_packet.lineage')));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'normalization_receipt.receipt_hash'));
        $this->assertFalse(data_get($payload, 'claims.raw_text_exposed'));
    }

    public function test_youtube_source_preserves_language_confidence_media_and_origin_lineage(): void
    {
        $payload = app(AtlasKnowledgeIngestionFabricService::class)->normalize([
            'origin_uri' => 'https://www.youtube.com/watch?v=abc123',
            'transcript' => 'the system explains retrieval',
            'language' => 'en',
            'confidence' => 0.71,
            'transcript_source' => 'official_caption',
        ]);

        $this->assertSame('youtube', data_get($payload, 'source_packet.source_type'));
        $this->assertSame('en', data_get($payload, 'source_packet.language'));
        $this->assertSame(0.71, data_get($payload, 'source_packet.confidence'));
        $this->assertSame('official_caption', data_get($payload, 'adapters.extraction_mode'));
        $this->assertSame('youtube', data_get($payload, 'source_packet.media_refs.0.media_type'));
        $this->assertSame('url', data_get($payload, 'lineage_refs.0.origin_kind'));
    }

    public function test_sensitive_source_is_blocked_by_arptl_and_does_not_leak_raw_content(): void
    {
        $secret = 'api_key=sk-AKIFSEGREDO1234567890';
        $payload = app(AtlasKnowledgeIngestionFabricService::class)->normalize([
            'source_type' => 'pdf',
            'origin_uri' => 'file://private/contract.pdf',
            'content' => 'Contrato privado '.$secret,
            'provider_target' => 'external',
            'classification' => 'confidential',
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked_by_privacy', data_get($payload, 'source_packet.ingestion_status'));
        $this->assertSame('blocked', data_get($payload, 'privacy_gate.status'));
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('sk-AKIFSEGREDO', $encoded);
    }

    public function test_hash_is_deterministic_for_same_source(): void
    {
        $service = app(AtlasKnowledgeIngestionFabricService::class);

        $first = $service->normalize(['source_type' => 'repo_file', 'origin_uri' => 'app/Foo.php', 'content' => '<?php echo 1;']);
        $second = $service->normalize(['source_type' => 'repo_file', 'origin_uri' => 'app/Foo.php', 'content' => '<?php echo 1;']);

        $this->assertSame(data_get($first, 'source_packet.source_hash'), data_get($second, 'source_packet.source_hash'));
        $this->assertSame(data_get($first, 'source_packet.version_hash'), data_get($second, 'source_packet.version_hash'));
        $this->assertSame($first['ingestion_fabric_hash'], $second['ingestion_fabric_hash']);
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:knowledge-ingestion', [
            '--source-type' => 'url',
            '--origin-uri' => 'https://example.com/page',
            '--content' => 'public source',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasKnowledgeIngestionFabricService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('url', data_get($payload, 'source_packet.source_type'));
    }
}
