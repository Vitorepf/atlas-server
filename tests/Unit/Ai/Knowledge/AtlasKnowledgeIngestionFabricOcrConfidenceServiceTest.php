<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Knowledge;

use App\Services\Ai\Knowledge\AtlasKnowledgeIngestionFabricOcrConfidenceService;
use Tests\TestCase;

class AtlasKnowledgeIngestionFabricOcrConfidenceServiceTest extends TestCase
{
    private string $logPath;

    private AtlasKnowledgeIngestionFabricOcrConfidenceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = sys_get_temp_dir().'/atlas_akif_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasKnowledgeIngestionFabricOcrConfidenceService;
        $this->svc->setArtifactsLogPathForTesting($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        parent::tearDown();
    }

    public function test_record_envelope_shape(): void
    {
        $r = $this->svc->record([
            'source_kind' => 'audio_transcription',
            'confidence' => 0.92,
            'content' => 'Hello world',
            'engine' => 'whisper.cpp',
            'language' => 'en',
        ]);
        $this->assertSame(AtlasKnowledgeIngestionFabricOcrConfidenceService::ARTIFACT_SCHEMA, $r['schema_version']);
        $this->assertSame('high', $r['confidence_bucket']);
        $this->assertTrue($r['promotable']);
    }

    public function test_low_confidence_not_promotable(): void
    {
        $r = $this->svc->record([
            'source_kind' => 'ocr',
            'confidence' => 0.4,
            'content' => 'noisy ocr text',
        ]);
        $this->assertSame('low', $r['confidence_bucket']);
        $this->assertFalse($r['promotable']);
    }

    public function test_medium_confidence_promotable(): void
    {
        $r = $this->svc->record([
            'source_kind' => 'pdf_extraction',
            'confidence' => 0.7,
            'content' => 'mid confidence',
        ]);
        $this->assertSame('medium', $r['confidence_bucket']);
        $this->assertTrue($r['promotable']);
    }

    public function test_unknown_source_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record([
            'source_kind' => 'martian',
            'confidence' => 0.5,
            'content' => 'x',
        ]);
    }

    public function test_confidence_out_of_range_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record([
            'source_kind' => 'ocr',
            'confidence' => 1.5,
            'content' => 'x',
        ]);
    }

    public function test_empty_content_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record([
            'source_kind' => 'ocr',
            'confidence' => 0.5,
            'content' => '',
        ]);
    }

    public function test_content_hash_and_preview(): void
    {
        $long = str_repeat('A', 1000);
        $r = $this->svc->record([
            'source_kind' => 'ocr',
            'confidence' => 0.95,
            'content' => $long,
        ]);
        $this->assertStringStartsWith('sha256:', $r['content_hash']);
        $this->assertLessThanOrEqual(AtlasKnowledgeIngestionFabricOcrConfidenceService::CONTENT_PREVIEW_MAX, strlen($r['content_preview']));
        $this->assertSame(1000, $r['content_length']);
    }

    public function test_list_promotable_filters_low_confidence_out(): void
    {
        $this->svc->record(['source_kind' => 'ocr', 'confidence' => 0.95, 'content' => 'high']);
        $this->svc->record(['source_kind' => 'ocr', 'confidence' => 0.3, 'content' => 'low']);
        $this->svc->record(['source_kind' => 'ocr', 'confidence' => 0.75, 'content' => 'mid']);
        $promotable = $this->svc->listPromotable();
        $this->assertCount(2, $promotable);
    }
}
