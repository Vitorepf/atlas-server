<?php

namespace Tests\Unit;

use App\Jobs\ProcessYouTubeIngestionJob;
use App\Services\Ai\YouTubeKnowledgeIngestionService;
use App\Services\WhisperTranscriber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class YouTubeKnowledgeIngestionServiceTest extends TestCase
{
    public function test_extracts_and_canonicalizes_youtube_urls(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $urls = $service->extractUrls('Veja https://youtu.be/abc123XYZ09?t=20 e depois https://www.youtube.com/watch?v=abc123XYZ09&list=x.');

        $this->assertSame(['https://www.youtube.com/watch?v=abc123XYZ09'], $urls);
    }

    public function test_parses_vtt_captions_into_segments(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $segments = $service->parseCaptionPayload(<<<'VTT'
WEBVTT

00:00:01.000 --> 00:00:04.000
Hello &amp; welcome.

00:00:04.500 --> 00:00:08.000
This is a test.
VTT, 'vtt');

        $this->assertCount(2, $segments);
        $this->assertSame(1.0, $segments[0]['start']);
        $this->assertSame('Hello & welcome.', $segments[0]['text']);
        $this->assertSame('This is a test.', $segments[1]['text']);
    }

    public function test_parses_json3_captions_and_chunks_with_labels(): void
    {
        config([
            'atlas.youtube.chunk_seconds' => 60,
            'atlas.youtube.max_chunk_chars' => 200,
            'atlas.youtube.max_chunks' => 10,
            'atlas.youtube.max_transcript_chars' => 2000,
        ]);

        $service = app(YouTubeKnowledgeIngestionService::class);
        $segments = $service->parseCaptionPayload(json_encode([
            'events' => [
                [
                    'tStartMs' => 1000,
                    'dDurationMs' => 2000,
                    'segs' => [['utf8' => 'Primeiro trecho.']],
                ],
                [
                    'tStartMs' => 61000,
                    'dDurationMs' => 3000,
                    'segs' => [['utf8' => 'Segundo trecho.']],
                ],
            ],
        ], JSON_THROW_ON_ERROR), 'json3');

        $chunks = $service->chunkSegments($segments);

        $this->assertCount(2, $chunks);
        $this->assertSame('00:01', $chunks[0]['start_label']);
        $this->assertSame('01:01', $chunks[1]['start_label']);
        $this->assertSame('Primeiro trecho.', $chunks[0]['text']);
        $this->assertSame('Segundo trecho.', $chunks[1]['text']);
    }

    public function test_parses_youtube_timedtext_xml_captions(): void
    {
        $service = app(YouTubeKnowledgeIngestionService::class);

        $segments = $service->parseCaptionPayload(<<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<transcript>
  <text start="1.2" dur="2.5">Olá &amp; bem-vindo.</text>
  <text start="4.0" dur="3.0">Segundo trecho.</text>
</transcript>
XML, 'json3');

        $this->assertCount(2, $segments);
        $this->assertSame(1.2, $segments[0]['start']);
        $this->assertSame(3.7, $segments[0]['end']);
        $this->assertSame('Olá & bem-vindo.', $segments[0]['text']);
    }

    public function test_ingests_video_from_watch_page_caption_fallback(): void
    {
        config([
            'atlas.youtube.data_api_enabled' => false,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
            'atlas.youtube.timeout_seconds' => 5,
            'atlas.youtube.chunk_seconds' => 60,
            'atlas.youtube.max_chunk_chars' => 500,
        ]);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Video de teste',
                'author' => 'Canal Atlas',
                'lengthSeconds' => '90',
            ],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [[
                        'baseUrl' => 'https://captions.example.test/timedtext?id=abc123XYZ09',
                        'languageCode' => 'en',
                        'name' => ['simpleText' => 'English'],
                    ]],
                ],
            ],
        ];

        Http::fake([
            'www.youtube.com/watch*' => Http::response('<html><script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script></html>'),
            'captions.example.test/*' => Http::response(json_encode([
                'events' => [[
                    'tStartMs' => 0,
                    'dDurationMs' => 3000,
                    'segs' => [['utf8' => 'Enterprise transcript ready.']],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = app(YouTubeKnowledgeIngestionService::class)
            ->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');

        $this->assertSame('ready', $result['status']);
        $this->assertSame('ready', $result['videos'][0]['status']);
        $this->assertSame('Video de teste', data_get($result, 'videos.0.metadata.title'));
        $this->assertSame('watch_page', data_get($result, 'videos.0.metadata.metadata_source'));
        $this->assertSame('Enterprise transcript ready.', data_get($result, 'videos.0.chunks.0.text'));
    }

    public function test_enriches_metadata_with_youtube_data_api_and_uses_public_caption_fallback(): void
    {
        Cache::flush();
        config([
            'atlas.youtube.data_api_enabled' => true,
            'atlas.youtube.data_api_key' => 'test-key',
            'atlas.youtube.data_api_daily_unit_limit' => 5,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
            'atlas.youtube.timeout_seconds' => 5,
        ]);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Fallback title',
                'author' => 'Fallback channel',
                'lengthSeconds' => '90',
            ],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [[
                        'baseUrl' => 'https://captions.example.test/timedtext?id=abc123XYZ09',
                        'languageCode' => 'en',
                        'name' => ['simpleText' => 'English'],
                    ]],
                ],
            ],
        ];

        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'snippet' => [
                        'title' => 'Official title',
                        'channelTitle' => 'Official channel',
                        'channelId' => 'channel_123',
                        'description' => "00:00 Intro\n05:30 Deep dive",
                        'publishedAt' => '2026-05-01T10:00:00Z',
                        'defaultAudioLanguage' => 'en',
                    ],
                    'contentDetails' => [
                        'duration' => 'PT18M2S',
                    ],
                    'statistics' => [
                        'viewCount' => '12345',
                        'likeCount' => '678',
                    ],
                ]],
            ]),
            'www.youtube.com/watch*' => Http::response('<html><script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script></html>'),
            'captions.example.test/*' => Http::response(json_encode([
                'events' => [[
                    'tStartMs' => 0,
                    'dDurationMs' => 3000,
                    'segs' => [['utf8' => 'Transcript from captions.']],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = app(YouTubeKnowledgeIngestionService::class)
            ->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');

        $this->assertSame('ready', $result['status']);
        $this->assertSame('Official title', data_get($result, 'videos.0.metadata.title'));
        $this->assertSame('Official channel', data_get($result, 'videos.0.metadata.channel'));
        $this->assertSame(1082, data_get($result, 'videos.0.metadata.duration_seconds'));
        $this->assertSame('youtube_data_api+captions', data_get($result, 'videos.0.metadata.metadata_source'));
        $this->assertSame(1, data_get($result, 'videos.0.metadata.data_api_quota_units'));
        $this->assertSame('Deep dive', data_get($result, 'videos.0.metadata.chapters.1.title'));
        $this->assertSame('Transcript from captions.', data_get($result, 'videos.0.chunks.0.text'));
    }

    public function test_reuses_cached_ready_video_without_spending_data_api_again(): void
    {
        Cache::flush();
        config([
            'atlas.youtube.cache_enabled' => true,
            'atlas.youtube.cache_ttl_days' => 30,
            'atlas.youtube.data_api_enabled' => true,
            'atlas.youtube.data_api_key' => 'test-key',
            'atlas.youtube.data_api_daily_unit_limit' => 5,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
        ]);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Fallback title',
                'author' => 'Fallback channel',
                'lengthSeconds' => '90',
            ],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [[
                        'baseUrl' => 'https://captions.example.test/timedtext?id=abc123XYZ09',
                        'languageCode' => 'en',
                        'name' => ['simpleText' => 'English'],
                    ]],
                ],
            ],
        ];

        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'snippet' => ['title' => 'Official title', 'channelTitle' => 'Official channel'],
                    'contentDetails' => ['duration' => 'PT2M'],
                ]],
            ]),
            'www.youtube.com/watch*' => Http::response('<script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script>'),
            'captions.example.test/*' => Http::response(json_encode([
                'events' => [[
                    'tStartMs' => 0,
                    'dDurationMs' => 3000,
                    'segs' => [['utf8' => 'Cached transcript.']],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = app(YouTubeKnowledgeIngestionService::class);
        $first = $service->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');
        $second = $service->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');

        $this->assertFalse(data_get($first, 'videos.0.cache_hit'));
        $this->assertTrue(data_get($second, 'videos.0.cache_hit'));
        $this->assertSame('Cached transcript.', data_get($second, 'videos.0.chunks.0.text'));

        Http::assertSentCount(3);
    }

    public function test_reports_audio_fallback_runtime_when_caption_body_is_empty(): void
    {
        config([
            'atlas.youtube.data_api_enabled' => false,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
            'atlas.youtube.audio_fallback_enabled' => true,
            'atlas.youtube.timeout_seconds' => 5,
        ]);
        $this->mock(WhisperTranscriber::class);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Video sem caption entregue',
                'author' => 'Canal Atlas',
                'lengthSeconds' => '90',
            ],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [[
                        'baseUrl' => 'https://captions.example.test/empty?id=abc123XYZ09',
                        'languageCode' => 'pt',
                        'kind' => 'asr',
                        'name' => ['simpleText' => 'Português automática'],
                    ]],
                ],
            ],
        ];

        Http::fake([
            'www.youtube.com/watch*' => Http::response('<script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script>'),
            'captions.example.test/*' => Http::response(''),
        ]);

        $result = app(YouTubeKnowledgeIngestionService::class)
            ->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('transcript_empty', data_get($result, 'videos.0.status'));
        $this->assertContains(data_get($result, 'videos.0.audio_fallback.status'), ['runtime_missing', 'download_failed']);
        $this->assertSame('Caption track was available, but did not contain readable transcript text.', data_get($result, 'videos.0.reason'));
    }

    public function test_attempts_audio_fallback_when_caption_download_fails(): void
    {
        config([
            'atlas.youtube.data_api_enabled' => false,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
            'atlas.youtube.audio_fallback_enabled' => true,
            'atlas.youtube.timeout_seconds' => 5,
        ]);
        $this->mock(WhisperTranscriber::class);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Video com caption bloqueada',
                'author' => 'Canal Atlas',
                'lengthSeconds' => '90',
            ],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [[
                        'baseUrl' => 'https://captions.example.test/rate-limited?id=abc123XYZ09',
                        'languageCode' => 'en',
                        'kind' => 'asr',
                        'name' => ['simpleText' => 'English automatic'],
                    ]],
                ],
            ],
        ];

        Http::fake([
            'www.youtube.com/watch*' => Http::response('<script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script>'),
            'captions.example.test/*' => Http::response('rate limited', 429),
        ]);

        $result = app(YouTubeKnowledgeIngestionService::class)
            ->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('caption_failed', data_get($result, 'videos.0.status'));
        $this->assertSame('Caption download failed with HTTP 429.', data_get($result, 'videos.0.reason'));
        $this->assertContains(data_get($result, 'videos.0.audio_fallback.status'), ['runtime_missing', 'download_failed']);
    }

    public function test_defers_audio_fallback_to_background_job_for_chat_flow(): void
    {
        Queue::fake();
        Cache::flush();
        config([
            'atlas.youtube.data_api_enabled' => false,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
            'atlas.youtube.audio_fallback_enabled' => true,
            'atlas.youtube.timeout_seconds' => 5,
        ]);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Video para processamento',
                'author' => 'Canal Atlas',
                'lengthSeconds' => '90',
            ],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [[
                        'baseUrl' => 'https://captions.example.test/empty?id=abc123XYZ09',
                        'languageCode' => 'en',
                        'kind' => 'asr',
                        'name' => ['simpleText' => 'English automatic'],
                    ]],
                ],
            ],
        ];

        Http::fake([
            'www.youtube.com/watch*' => Http::response('<script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script>'),
            'captions.example.test/*' => Http::response(''),
        ]);

        $result = app(YouTubeKnowledgeIngestionService::class)
            ->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09', [
                'defer_audio_fallback' => true,
            ]);

        $this->assertSame('processing', $result['status']);
        $this->assertSame('processing', data_get($result, 'videos.0.status'));
        $this->assertSame('queued', data_get($result, 'videos.0.audio_fallback.status'));
        Queue::assertPushed(ProcessYouTubeIngestionJob::class);
    }

    public function test_attempts_audio_fallback_when_no_caption_track_exists(): void
    {
        config([
            'atlas.youtube.data_api_enabled' => false,
            'atlas.youtube.yt_dlp_binary' => '/definitely/missing/yt-dlp',
            'atlas.youtube.audio_fallback_enabled' => true,
            'atlas.youtube.timeout_seconds' => 5,
        ]);
        $this->mock(WhisperTranscriber::class);

        $player = [
            'videoDetails' => [
                'videoId' => 'abc123XYZ09',
                'title' => 'Video sem caption',
                'author' => 'Canal Atlas',
                'lengthSeconds' => '90',
            ],
        ];

        Http::fake([
            'www.youtube.com/watch*' => Http::response('<script>var ytInitialPlayerResponse = '.json_encode($player, JSON_THROW_ON_ERROR).';</script>'),
        ]);

        $result = app(YouTubeKnowledgeIngestionService::class)
            ->ingestFromInput('Resumo https://www.youtube.com/watch?v=abc123XYZ09');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('caption_unavailable', data_get($result, 'videos.0.status'));
        $this->assertContains(data_get($result, 'videos.0.audio_fallback.status'), ['runtime_missing', 'download_failed']);
    }
}
