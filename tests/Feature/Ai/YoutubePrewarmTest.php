<?php

namespace Tests\Feature\Ai;

use App\Jobs\ProcessYouTubeIngestionJob;
use App\Models\AiYoutubeIngestion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * YouTube prewarm endpoint contract.
 *
 * Validates that paste-time prewarm is idempotent, dedup'd, lock-protected
 * and reuses fresh DB state instead of redispatching. Mirrors the canon
 * doctrine in `docs/rich-input/youtube-canon.md`.
 */
class YoutubePrewarmTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'prewarm-test-token-1234567890');
        config()->set('atlas.youtube.queue', 'transcription');
        Cache::flush();

        if (! Schema::hasTable('ai_youtube_ingestions')) {
            Schema::create('ai_youtube_ingestions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('video_id', 32)->unique();
                $table->text('url');
                $table->text('title')->nullable();
                $table->string('channel')->nullable();
                $table->string('status', 48)->index();
                $table->text('reason')->nullable();
                $table->string('metadata_source', 96)->nullable()->index();
                $table->string('caption_kind', 64)->nullable();
                $table->string('caption_language', 24)->nullable();
                $table->string('audio_fallback_status', 64)->nullable()->index();
                $table->unsignedInteger('chunk_count')->default(0);
                $table->unsignedInteger('transcript_chars')->default(0);
                $table->unsignedInteger('ingestion_ms')->nullable();
                $table->boolean('cache_hit')->default(false);
                $table->json('metadata')->nullable();
                $table->json('caption')->nullable();
                $table->json('chunks')->nullable();
                $table->json('diagnostics')->nullable();
                $table->timestamp('last_ingested_at')->nullable()->index();
                $table->timestamps();
                $table->string('ingestion_status', 32)->nullable()->index();
                $table->string('transcript_status', 32)->nullable()->index();
                $table->string('translation_status', 32)->nullable()->index();
                $table->string('source_language', 24)->nullable();
                $table->string('target_language', 24)->nullable()->default('pt-BR');
                $table->boolean('translation_required')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        Cache::flush();
        AiYoutubeIngestion::query()->delete();
        parent::tearDown();
    }

    private function authHeaders(): array
    {
        return ['X-Atlas-Token' => 'prewarm-test-token-1234567890'];
    }

    public function test_prewarm_dispatches_job_and_returns_canonical_snapshot(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/ai/youtube/prewarm', [
                'url' => 'https://youtu.be/abc123XYZ09',
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'schema_version',
            'items' => [
                ['url', 'canonical_url', 'video_id', 'ingestion_status', 'transcript_status', 'translation_status', 'cache_hit', 'dispatched', 'locked'],
            ],
        ]);
        $response->assertJsonPath('items.0.canonical_url', 'https://www.youtube.com/watch?v=abc123XYZ09');
        $response->assertJsonPath('items.0.video_id', 'abc123XYZ09');
        $response->assertJsonPath('items.0.dispatched', true);
        $response->assertJsonPath('items.0.cache_hit', false);
        $response->assertJsonPath('items.0.locked', false);

        Queue::assertPushedOn('transcription', ProcessYouTubeIngestionJob::class);
    }

    public function test_second_call_with_same_video_id_returns_locked_without_redispatch(): void
    {
        Queue::fake();

        $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', [
            'url' => 'https://youtu.be/lockedaaaa1',
        ])->assertOk();

        Queue::assertPushed(ProcessYouTubeIngestionJob::class, 1);

        // Second call within the lock TTL.
        $second = $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', [
            'url' => 'https://www.youtube.com/watch?v=lockedaaaa1',
        ]);
        $second->assertOk();
        $second->assertJsonPath('items.0.dispatched', false);
        $second->assertJsonPath('items.0.locked', true);

        // Still only 1 job total.
        Queue::assertPushed(ProcessYouTubeIngestionJob::class, 1);
    }

    public function test_batch_dedups_different_url_formats_for_same_video(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', [
            'urls' => [
                'https://youtu.be/dedupAaaaa1',
                'https://www.youtube.com/watch?v=dedupAaaaa1&list=RDxyz',
                'https://www.youtube.com/shorts/dedupAaaaa1',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.video_id', 'dedupAaaaa1');
        Queue::assertPushed(ProcessYouTubeIngestionJob::class, 1);
    }

    public function test_fresh_ready_record_returns_cache_hit_without_dispatch(): void
    {
        Queue::fake();

        AiYoutubeIngestion::query()->create([
            'video_id' => 'cachedAaaa1',
            'url' => 'https://www.youtube.com/watch?v=cachedAaaa1',
            'status' => 'ready',
            'ingestion_status' => 'ready',
            'transcript_status' => 'original_ready',
            'translation_status' => 'not_required',
            'source_language' => 'pt-BR',
            'target_language' => 'pt-BR',
            'translation_required' => false,
            'caption_language' => 'pt-BR',
            'caption_kind' => 'manual',
            'metadata' => ['title' => 'Cached'],
            'caption' => ['language' => 'pt-BR', 'kind' => 'manual'],
            'chunks' => [],
            'last_ingested_at' => now()->subMinutes(10),
        ]);

        $response = $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', [
            'url' => 'https://youtu.be/cachedAaaa1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.cache_hit', true);
        $response->assertJsonPath('items.0.dispatched', false);
        $response->assertJsonPath('items.0.ingestion_status', 'ready');
        $response->assertJsonPath('items.0.translation_status', 'not_required');
        Queue::assertNothingPushed();
    }

    public function test_stale_ready_record_triggers_fresh_dispatch(): void
    {
        Queue::fake();

        AiYoutubeIngestion::query()->create([
            'video_id' => 'staleAaaaaa1',
            'url' => 'https://www.youtube.com/watch?v=staleAaaaaa1',
            'status' => 'ready',
            'ingestion_status' => 'ready',
            'transcript_status' => 'original_ready',
            'translation_status' => 'not_required',
            'source_language' => 'pt-BR',
            'target_language' => 'pt-BR',
            'translation_required' => false,
            'caption_language' => 'pt-BR',
            'metadata' => [],
            'caption' => [],
            'chunks' => [],
            'last_ingested_at' => now()->subDays(30), // stale > 7d
        ]);

        $response = $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', [
            'url' => 'https://youtu.be/staleAaaaaa1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.cache_hit', false);
        $response->assertJsonPath('items.0.dispatched', true);
        Queue::assertPushed(ProcessYouTubeIngestionJob::class, 1);
    }

    public function test_invalid_url_yields_failed_item_without_dispatch(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', [
            'urls' => [
                'https://example.com',
                'https://www.youtube.com/results?q=foo',
                'not a url at all',
                'https://youtu.be/validAaaaa1',
            ],
        ]);

        $response->assertOk();
        $items = $response->json('items');
        $this->assertIsArray($items);
        // Three failed items (not_a_youtube_url) + one dispatched
        $dispatched = collect($items)->where('dispatched', true)->count();
        $failed = collect($items)->where('reason', 'not_a_youtube_url')->count();
        $this->assertSame(1, $dispatched);
        $this->assertGreaterThanOrEqual(2, $failed);
        Queue::assertPushed(ProcessYouTubeIngestionJob::class, 1);
    }

    public function test_status_endpoint_returns_canonical_projection(): void
    {
        AiYoutubeIngestion::query()->create([
            'video_id' => 'statusAaaa1',
            'url' => 'https://www.youtube.com/watch?v=statusAaaa1',
            'status' => 'ready',
            'ingestion_status' => 'ready',
            'transcript_status' => 'original_ready',
            'translation_status' => 'required',
            'source_language' => 'en',
            'target_language' => 'pt-BR',
            'translation_required' => true,
            'caption_language' => 'en',
            'caption_kind' => 'manual',
            'metadata' => ['title' => 'EN Talk'],
            'caption' => ['language' => 'en', 'kind' => 'manual'],
            'chunks' => [],
            'last_ingested_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/ai/youtube/ingestion/statusAaaa1');

        $response->assertOk();
        $response->assertJsonPath('item.video_id', 'statusAaaa1');
        $response->assertJsonPath('item.ingestion_status', 'ready');
        $response->assertJsonPath('item.translation_status', 'required');
        $response->assertJsonPath('item.translation_required', true);
        $response->assertJsonPath('item.source_language', 'en');
    }

    public function test_status_endpoint_rejects_invalid_video_id(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/ai/youtube/ingestion/not-an-id');
        // The route constraint [A-Za-z0-9_-]{11} kicks in first → 404 from Laravel.
        // For non-matching length we hit the route as missing, which returns 404.
        $this->assertContains($response->status(), [404, 422]);
    }

    public function test_status_endpoint_returns_404_when_video_unseen(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/ai/youtube/ingestion/unseenAaaa1');

        $response->assertStatus(404);
    }

    public function test_empty_body_returns_empty_items(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->authHeaders())->postJson('/ai/youtube/prewarm', []);

        $response->assertOk();
        $response->assertJsonPath('items', []);
        Queue::assertNothingPushed();
    }
}
