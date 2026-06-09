<?php

namespace Tests\Unit;

use App\Enums\YoutubeIngestionStatus;
use App\Enums\YoutubeTranscriptStatus;
use App\Enums\YoutubeTranslationStatus;
use App\Services\Ai\YoutubeCanonicalProjection;
use PHPUnit\Framework\TestCase;

/**
 * YouTube canonical capability · server-side projection contract.
 *
 * Mirrors the canon TS tests in `@atlas/rich-input-canon` so the two
 * stacks stay aligned. See `docs/rich-input/youtube-canon.md`.
 */
class YoutubeCanonicalProjectionTest extends TestCase
{
    public function test_pt_br_video_does_not_require_translation(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $out = $projection->project([
            'url' => 'https://www.youtube.com/watch?v=ptbr1234567',
            'status' => 'ready',
            'caption' => ['language' => 'pt-BR', 'kind' => 'manual'],
            'metadata' => ['title' => 'Talk PT-BR', 'channel' => 'Canal BR'],
        ]);

        $this->assertSame('ready', $out['ingestion_status']);
        $this->assertSame('original_ready', $out['transcript_status']);
        $this->assertSame('not_required', $out['translation_status']);
        $this->assertSame('pt-BR', $out['source_language']);
        $this->assertSame('pt-BR', $out['target_language']);
        $this->assertFalse($out['translation_required']);
        $this->assertSame('ptbr1234567', $out['video_id']);
    }

    public function test_english_video_requires_translation_with_required_status(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $out = $projection->project([
            'url' => 'https://www.youtube.com/watch?v=enxxxxxxxxx',
            'status' => 'ready',
            'caption' => ['language' => 'en', 'kind' => 'manual'],
            'metadata' => ['title' => 'English Talk'],
        ]);

        $this->assertSame('ready', $out['ingestion_status']);
        $this->assertSame('original_ready', $out['transcript_status']);
        // Honest: pipeline de tradução não existe; status fica REQUIRED, nunca TRANSLATED_READY.
        $this->assertSame('required', $out['translation_status']);
        $this->assertSame('en', $out['source_language']);
        $this->assertTrue($out['translation_required']);
    }

    public function test_processing_video_yields_pending_translation(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $out = $projection->project([
            'url' => 'https://youtu.be/processingxx',
            'status' => 'processing',
        ]);

        $this->assertSame('processing', $out['ingestion_status']);
        $this->assertSame('pending', $out['transcript_status']);
        $this->assertSame('pending', $out['translation_status']);
    }

    public function test_caption_unavailable_yields_not_required_translation(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $out = $projection->project([
            'url' => 'https://youtu.be/nocapsssss1',
            'status' => 'caption_unavailable',
        ]);

        // Ingestion succeeded — we tried. Transcript absent. Nothing to translate.
        $this->assertSame('ready', $out['ingestion_status']);
        $this->assertSame('unavailable', $out['transcript_status']);
        $this->assertSame('not_required', $out['translation_status']);
        $this->assertFalse($out['translation_required']);
    }

    public function test_failed_status_propagates_across_dimensions(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $out = $projection->project([
            'url' => 'https://youtu.be/failedaaaaa',
            'status' => 'failed',
            'reason' => 'metadata fetch failed',
        ]);

        $this->assertSame('failed', $out['ingestion_status']);
        $this->assertSame('failed', $out['transcript_status']);
        $this->assertSame('failed', $out['translation_status']);
    }

    public function test_translated_ready_only_via_explicit_signal(): void
    {
        $projection = new YoutubeCanonicalProjection;
        // Even with EN caption, default behavior NEVER sets translated_ready
        $out = $projection->project([
            'url' => 'https://youtu.be/enxxxxxxxxx',
            'status' => 'ready',
            'caption' => ['language' => 'en'],
        ]);
        $this->assertNotSame('translated_ready', $out['translation_status']);

        // Explicit signal (future translation pipeline) honored
        $outExplicit = $projection->project([
            'url' => 'https://youtu.be/enxxxxxxxxx',
            'status' => 'ready',
            'caption' => ['language' => 'en'],
            'translation_status' => 'translated_ready',
        ]);
        $this->assertSame('translated_ready', $outExplicit['translation_status']);
    }

    public function test_source_language_inference_normalizes_bcp47(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $this->assertSame('pt-BR', $projection->inferSourceLanguage('pt-BR'));
        $this->assertSame('pt-BR', $projection->inferSourceLanguage('pt_br'));
        $this->assertSame('pt-PT', $projection->inferSourceLanguage('pt-PT'));
        $this->assertSame('pt', $projection->inferSourceLanguage('pt'));
        $this->assertSame('en', $projection->inferSourceLanguage('en-US'));
        $this->assertSame('zh-Hans', $projection->inferSourceLanguage('zh-Hans'));
        $this->assertSame('zh-Hant', $projection->inferSourceLanguage('zh-Hant'));
        $this->assertSame('ja', $projection->inferSourceLanguage('ja-JP'));
        $this->assertNull($projection->inferSourceLanguage('und'));
        $this->assertNull($projection->inferSourceLanguage(''));
        $this->assertNull($projection->inferSourceLanguage(null));
    }

    public function test_translation_requirement_collapses_portuguese_variants(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $this->assertFalse($projection->deriveTranslationRequirement('pt-BR', 'pt-BR'));
        $this->assertFalse($projection->deriveTranslationRequirement('pt-PT', 'pt-BR'));
        $this->assertFalse($projection->deriveTranslationRequirement('pt', 'pt-BR'));
        $this->assertTrue($projection->deriveTranslationRequirement('en', 'pt-BR'));
        $this->assertTrue($projection->deriveTranslationRequirement('ja', 'pt-BR'));
        $this->assertFalse($projection->deriveTranslationRequirement(null, 'pt-BR'));
        $this->assertFalse($projection->deriveTranslationRequirement('en', null));
    }

    public function test_enums_match_canon_string_values(): void
    {
        // Sanity check: PHP enum string values mirror the canon TS string union.
        $this->assertSame('queued', YoutubeIngestionStatus::Queued->value);
        $this->assertSame('processing', YoutubeIngestionStatus::Processing->value);
        $this->assertSame('ready', YoutubeIngestionStatus::Ready->value);
        $this->assertSame('failed', YoutubeIngestionStatus::Failed->value);

        $this->assertSame('unavailable', YoutubeTranscriptStatus::Unavailable->value);
        $this->assertSame('pending', YoutubeTranscriptStatus::Pending->value);
        $this->assertSame('original_ready', YoutubeTranscriptStatus::OriginalReady->value);
        $this->assertSame('failed', YoutubeTranscriptStatus::Failed->value);

        $this->assertSame('not_required', YoutubeTranslationStatus::NotRequired->value);
        $this->assertSame('required', YoutubeTranslationStatus::Required->value);
        $this->assertSame('pending', YoutubeTranslationStatus::Pending->value);
        $this->assertSame('translated_ready', YoutubeTranslationStatus::TranslatedReady->value);
        $this->assertSame('failed', YoutubeTranslationStatus::Failed->value);

        $this->assertSame(YoutubeIngestionStatus::Ready, YoutubeIngestionStatus::fromStoredStatus('caption_unavailable'));
        $this->assertSame(YoutubeIngestionStatus::Ready, YoutubeIngestionStatus::fromLegacy('caption_unavailable'));
        $this->assertSame(YoutubeTranscriptStatus::Unavailable, YoutubeTranscriptStatus::fromStoredStatus('caption_unavailable'));
        $this->assertSame(YoutubeTranscriptStatus::Unavailable, YoutubeTranscriptStatus::fromLegacy('caption_unavailable'));
    }

    public function test_project_ingestion_processes_each_video(): void
    {
        $projection = new YoutubeCanonicalProjection;

        $out = $projection->projectIngestion([
            'schema_version' => 1,
            'status' => 'ready',
            'videos' => [
                ['url' => 'https://youtu.be/aaaaaaaaaaa', 'status' => 'ready', 'caption' => ['language' => 'en']],
                ['url' => 'https://youtu.be/bbbbbbbbbbb', 'status' => 'ready', 'caption' => ['language' => 'pt-BR']],
                'not an array — must be ignored',
            ],
        ]);

        $this->assertCount(2, $out['videos']);
        $this->assertSame('required', $out['videos'][0]['translation_status']);
        $this->assertSame('not_required', $out['videos'][1]['translation_status']);
    }
}
