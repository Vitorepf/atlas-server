<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context\Retrieval;

use App\Services\Ai\Context\Retrieval\AsefChunkIndexService;
use App\Services\Ai\Context\AtlasSemanticEmbeddingFoundationService;
use App\Services\Semantic\EmbeddingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * MAXA-05 — asef_chunks persistence: chunk→doc mapping + delete cascade.
 *
 * Live R8 mid-doc precision@5 > single-vector dual-read remains pending_window
 * (needs ≥10 labeled mid-doc queries against a populated index). This suite
 * proves the pétreo mechanical aceite: deterministic contextualize, chunk→doc
 * dedup, and delete_cascade_key removal — including the negative case that a
 * cascade key never deletes a sibling source's chunks.
 */
final class Maxa05AsefChunksIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropTable();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        $this->dropTable();
        Mockery::close();
        parent::tearDown();
    }

    public function test_contextualized_text_is_deterministic_title_section_chunk(): void
    {
        $a = AsefChunkIndexService::contextualizedText('Doc Title', '§2 Mid', 'body chunk here');
        $b = AsefChunkIndexService::contextualizedText('Doc Title', '§2 Mid', 'body chunk here');

        $this->assertSame("Doc Title > §2 Mid\n\nbody chunk here", $a);
        $this->assertSame($a, $b);
    }

    public function test_map_chunk_hits_to_documents_dedupes_by_source_ref_keeping_best_similarity(): void
    {
        $service = $this->makeService($this->mockEmbeddings());

        $docs = $service->mapChunkHitsToDocuments([
            ['source_ref' => 'doc://a', 'similarity' => 0.4, 'chunk_hash' => 'h1', 'chunk_id' => 'c1'],
            ['source_ref' => 'doc://a', 'similarity' => 0.9, 'chunk_hash' => 'h2', 'chunk_id' => 'c2'],
            ['source_ref' => 'doc://b', 'similarity' => 0.7, 'chunk_hash' => 'h3', 'chunk_id' => 'c3'],
            ['source_ref' => '', 'similarity' => 1.0, 'chunk_hash' => 'ignored'],
        ]);

        $this->assertCount(2, $docs);
        $this->assertSame('doc://a', $docs[0]['source_ref']);
        $this->assertSame(0.9, $docs[0]['similarity']);
        $this->assertSame('h2', $docs[0]['chunk_hash']);
        $this->assertSame(2, $docs[0]['chunk_hit_count']);
        $this->assertSame('doc://b', $docs[1]['source_ref']);
        $this->assertSame(1, $docs[1]['chunk_hit_count']);
    }

    public function test_index_source_persists_chunks_and_delete_cascade_removes_only_matching_key(): void
    {
        $longBody = str_repeat("Paragraph about mid-document topic alpha.\n\n", 40)
            .'UNIQUE_MID_DOC_MARKER_XYZ '.str_repeat('tail text for length. ', 80);

        $service = $this->makeService($this->mockEmbeddings());

        $resultA = $service->indexSource([
            'source_ref' => 'doc://alpha',
            'title' => 'Alpha Doc',
            'section' => 'Body',
            'text' => $longBody,
            'privacy_class' => 'normal',
        ], false);

        $this->assertSame('ok', $resultA['status']);
        $this->assertGreaterThanOrEqual(2, $resultA['chunks_written']);

        $resultB = $service->indexSource([
            'source_ref' => 'doc://beta',
            'title' => 'Beta Doc',
            'section' => 'Body',
            'text' => $longBody.' BETA_ONLY',
            'privacy_class' => 'normal',
        ], false);

        $this->assertSame('ok', $resultB['status']);

        $alphaCount = (int) DB::table('asef_chunks')->where('source_ref', 'doc://alpha')->count();
        $betaCount = (int) DB::table('asef_chunks')->where('source_ref', 'doc://beta')->count();
        $this->assertGreaterThanOrEqual(2, $alphaCount);
        $this->assertGreaterThanOrEqual(2, $betaCount);

        // ASEF keys are per-chunk (source_ref+chunk_hash). Cascade one key → one row.
        $alphaKeys = DB::table('asef_chunks')->where('source_ref', 'doc://alpha')->pluck('delete_cascade_key');
        $this->assertGreaterThanOrEqual(2, $alphaKeys->unique()->count());

        $firstKey = (string) $alphaKeys->first();
        $this->assertNotSame('', $firstKey);
        $deletedOne = $service->deleteCascade($firstKey);
        $this->assertSame(1, $deletedOne);
        $this->assertSame($alphaCount - 1, (int) DB::table('asef_chunks')->where('source_ref', 'doc://alpha')->count());
        // Negative case: cascading an alpha key must NOT wipe beta.
        $this->assertSame($betaCount, (int) DB::table('asef_chunks')->where('source_ref', 'doc://beta')->count());

        // Source-level cleanup (forget path) removes the rest of alpha only.
        $deletedSource = $service->deleteBySourceRef('doc://alpha');
        $this->assertSame($alphaCount - 1, $deletedSource);
        $this->assertSame(0, (int) DB::table('asef_chunks')->where('source_ref', 'doc://alpha')->count());
        $this->assertSame($betaCount, (int) DB::table('asef_chunks')->where('source_ref', 'doc://beta')->count());

        // Embedded text always carries contextualized prefix.
        $betaEmbedded = (string) DB::table('asef_chunks')->where('source_ref', 'doc://beta')->value('embedded_text');
        $this->assertStringStartsWith("Beta Doc > Body\n\n", $betaEmbedded);
    }

    public function test_empty_source_is_blocked_and_writes_nothing(): void
    {
        $service = $this->makeService($this->mockEmbeddings());
        $result = $service->indexSource([
            'source_ref' => 'doc://empty',
            'title' => 'Empty',
            'text' => '',
        ], false);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, $result['chunks_written']);
        $this->assertSame(0, (int) DB::table('asef_chunks')->count());
    }

    public function test_search_degrades_honestly_without_embedding_column(): void
    {
        $service = $this->makeService($this->mockEmbeddings());
        $payload = $service->search('mid document topic', 5, false);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame('embedding_column_absent', $payload['reason']);
        $this->assertSame([], $payload['documents']);
    }

    private function makeService(EmbeddingService $embeddings): AsefChunkIndexService
    {
        return new AsefChunkIndexService(
            app(AtlasSemanticEmbeddingFoundationService::class),
            $embeddings,
        );
    }

    private function mockEmbeddings(): EmbeddingService
    {
        $mock = Mockery::mock(EmbeddingService::class);
        $mock->shouldReceive('embedText')
            ->andReturnUsing(static function (string $text): array {
                // Deterministic tiny vector derived from text length — never calls provider.
                $seed = (strlen($text) % 97) + 1;

                return array_map(
                    static fn (int $i): float => (($seed + $i) % 13) / 13.0,
                    range(0, 7),
                );
            });
        $mock->shouldReceive('lastInfo')->andReturn([
            'provider' => 'test',
            'model' => 'mock-maxa05',
            'semantic' => true,
            'dimensions' => 8,
        ]);
        $mock->shouldReceive('vectorLiteral')
            ->andReturnUsing(static fn (array $v): string => '['.implode(',', $v).']');

        return $mock;
    }

    private function createTable(): void
    {
        Schema::create('asef_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('chunk_id', 64)->unique();
            $table->string('source_ref', 512)->index();
            $table->string('source_hash', 64)->index();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->string('chunk_hash', 64)->index();
            $table->string('title', 512)->default('');
            $table->string('section', 512)->default('');
            $table->text('chunk_text');
            $table->text('embedded_text');
            $table->string('privacy_class', 32)->default('normal');
            $table->boolean('provider_safe')->default(true);
            $table->string('delete_cascade_key', 80)->index();
            $table->string('embedding_model', 120)->nullable();
            $table->string('embedded_content_hash', 64)->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->string('embedding_status', 64)->default('pending');
            $table->timestamps();
            $table->unique(['source_ref', 'chunk_hash'], 'uniq_asef_chunks_source_chunk_hash');
        });
    }

    private function dropTable(): void
    {
        Schema::dropIfExists('asef_chunks');
    }
}
