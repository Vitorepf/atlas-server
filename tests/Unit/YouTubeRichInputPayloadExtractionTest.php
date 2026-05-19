<?php

namespace Tests\Unit;

use App\Services\Ai\YouTubeKnowledgeIngestionService;
use Tests\TestCase;

/**
 * YouTube canonical capability · payload-side URL extraction.
 *
 * Mobile/desktop attach YouTube via `rich_input_payload.url_attachments[]`.
 * Before this slice the backend extracted URLs only from `input_text`, so
 * desktop-only attachments were silently dropped. These tests lock the new
 * extraction + dedup contract. See `docs/rich-input/youtube-canon.md`.
 */
class YouTubeRichInputPayloadExtractionTest extends TestCase
{
    public function test_extracts_youtube_url_from_payload_url_attachments(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $urls = $service->extractUrlsFromRichInputPayload([
            ['url' => 'https://youtu.be/abc123XYZ09', 'kind' => 'youtube', 'ref_id' => 'abc123XYZ09'],
        ]);

        $this->assertSame(['https://www.youtube.com/watch?v=abc123XYZ09'], $urls);
    }

    public function test_filters_non_youtube_kinds(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $urls = $service->extractUrlsFromRichInputPayload([
            ['url' => 'https://example.com', 'kind' => 'generic'],
            ['url' => 'https://github.com/owner/repo', 'kind' => 'github'],
            ['url' => 'https://vimeo.com/123', 'kind' => 'vimeo'],
            ['url' => 'https://youtu.be/yt12345aaaa', 'kind' => 'youtube'],
        ]);

        $this->assertSame(['https://www.youtube.com/watch?v=yt12345aaaa'], $urls);
    }

    public function test_dedups_different_url_formats_for_same_video(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $urls = $service->extractUrlsFromRichInputPayload([
            ['url' => 'https://youtu.be/abc123XYZ09?t=99', 'kind' => 'youtube'],
            ['url' => 'https://www.youtube.com/shorts/abc123XYZ09', 'kind' => 'youtube'],
            ['url' => 'https://www.youtube.com/watch?v=abc123XYZ09&list=RDxyz', 'kind' => 'youtube'],
        ]);

        $this->assertSame(['https://www.youtube.com/watch?v=abc123XYZ09'], $urls);
    }

    public function test_drops_invalid_youtube_kind_without_real_video_id(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $urls = $service->extractUrlsFromRichInputPayload([
            // Kind says youtube but URL has no extractable video id; defensive drop.
            ['url' => 'https://www.youtube.com/results?q=foo', 'kind' => 'youtube'],
        ]);

        $this->assertSame([], $urls);
    }

    public function test_handles_null_payload(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $this->assertSame([], $service->extractUrlsFromRichInputPayload(null));
        $this->assertSame([], $service->extractUrlsFromRichInputPayload([]));
    }
}
