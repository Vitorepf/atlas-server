<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Knowledge;

use App\Services\Ai\Knowledge\YouTubeMetadataSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class YouTubeMetadataSupportTest extends TestCase
{
    #[Test]
    public function merge_metadata_prefers_official_non_empty_fields(): void
    {
        $merged = YouTubeMetadataSupport::mergeMetadata(
            ['title' => 'Official', 'metadata_source' => 'youtube_data_api'],
            ['title' => 'Caption', 'subtitles' => ['pt' => [['url' => 'x']]]],
        );
        $this->assertSame('Official', $merged['title']);
        $this->assertSame('youtube_data_api+captions', $merged['metadata_source']);
        $this->assertArrayHasKey('pt', $merged['subtitles']);
    }

    #[Test]
    public function public_caption_projects_provider_safe_fields(): void
    {
        $public = YouTubeMetadataSupport::publicCaption([
            'language' => 'pt',
            'name' => 'Portuguese',
            'track_kind' => 'manual',
            'ext' => 'vtt',
            'url' => 'https://example.invalid/secret',
        ]);
        $this->assertSame('pt', $public['language']);
        $this->assertSame('manual', $public['kind']);
        $this->assertArrayNotHasKey('url', $public);
    }

    #[Test]
    public function is_retryable_download_error_detects_transient_phrases(): void
    {
        $this->assertTrue(YouTubeMetadataSupport::isRetryableDownloadError('HTTP Error 429: Too Many Requests'));
        $this->assertFalse(YouTubeMetadataSupport::isRetryableDownloadError('Video unavailable'));
    }
}
