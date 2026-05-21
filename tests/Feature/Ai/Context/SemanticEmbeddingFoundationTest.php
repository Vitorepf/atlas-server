<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasSemanticEmbeddingFoundationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SemanticEmbeddingFoundationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

        parent::tearDown();
    }

    public function test_candidate_set_is_deterministic_and_does_not_expose_raw_text(): void
    {
        $service = app(AtlasSemanticEmbeddingFoundationService::class);
        $sources = [[
            'source_ref' => 'doc://aucri/test',
            'text' => 'Context ranking must keep evidence, source hashes, freshness and privacy boundaries intact.',
            'privacy_class' => 'normal',
        ]];

        $first = $service->candidateSet($sources);
        $second = $service->candidateSet($sources);

        $this->assertSame('ready', $first['status']);
        $this->assertSame($first['candidate_set_hash'], $second['candidate_set_hash']);
        $this->assertSame('atlas.aucri.embedding_candidate_set.v1', data_get($first, 'candidate_set.schema_version'));
        $this->assertFalse(data_get($first, 'quality_gates.raw_text_exposed'));
        $this->assertStringNotContainsString('Context ranking must keep evidence', json_encode($first, JSON_THROW_ON_ERROR));
        $this->assertTrue(data_get($first, 'candidate_set.chunks.0.provider_safe'));
        $this->assertNotEmpty(data_get($first, 'candidate_set.chunks.0.lexical_signature'));
    }

    public function test_sensitive_content_is_blocked_from_provider_safe_signature(): void
    {
        $payload = app(AtlasSemanticEmbeddingFoundationService::class)->candidateSet([[
            'source_ref' => 'secret://operator',
            'text' => 'api_key=abc123 password=super-secret-value',
            'privacy_class' => 'normal',
        ]]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('secret', data_get($payload, 'candidate_set.chunks.0.privacy_class'));
        $this->assertFalse(data_get($payload, 'candidate_set.chunks.0.provider_safe'));
        $this->assertTrue(data_get($payload, 'candidate_set.chunks.0.redaction_required'));
        $this->assertSame([], data_get($payload, 'candidate_set.chunks.0.lexical_signature'));
    }

    public function test_readiness_reports_existing_stores_without_external_embedding(): void
    {
        $this->createLocalRagTables();

        $payload = app(AtlasSemanticEmbeddingFoundationService::class)->readiness();

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue(data_get($payload, 'stores.semantic_notes.table_exists'));
        $this->assertTrue(data_get($payload, 'stores.ai_attachment_index_entries.embedding_column_exists'));
        $this->assertSame('manifest_chunking_privacy_hashes_only', data_get($payload, 'embedding_policy.laravel_scope'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claims.external_vector_store_used'));
    }

    public function test_command_emits_readiness_json(): void
    {
        $this->createLocalRagTables();

        $exit = Artisan::call('atlas:context:semantic-foundation', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSemanticEmbeddingFoundationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertArrayHasKey('readiness_hash', $payload);
    }

    private function createLocalRagTables(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_attachment_index_entries', function (Blueprint $table): void {
            $table->id();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });
    }
}
