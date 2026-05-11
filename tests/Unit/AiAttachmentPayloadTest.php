<?php

namespace Tests\Unit;

use App\Support\AiAttachmentPayload;
use Tests\TestCase;

class AiAttachmentPayloadTest extends TestCase
{
    public function test_public_attachments_do_not_expose_local_paths(): void
    {
        $payload = [
            'attachments' => [
                'images' => [[
                    'path' => '/Users/example/storage/app/ai/attachments/upload.png',
                    'original_path' => '/Users/example/storage/app/ai/attachments/upload.png',
                    'original_name' => 'atlas-clipboard-20260511-080900-a1b2.png',
                    'mime_type' => 'image/png',
                    'bytes' => 1234,
                    'sha256' => 'image-hash',
                    'source' => 'mobile_upload',
                ]],
                'files' => [[
                    'path' => '/Users/example/storage/app/ai/attachments/document.pdf',
                    'original_name' => 'documento.pdf',
                    'mime_type' => 'application/pdf',
                    'bytes' => 4321,
                    'sha256' => 'file-hash',
                    'source' => 'mobile_upload',
                    'text_excerpt' => 'conteudo privado',
                    'text_available' => true,
                    'text_truncated' => false,
                    'pdf_page_count' => 3,
                    'pdf_processing_status' => 'processed',
                    'pdf_chunk_count' => 4,
                    'pdf_render_status' => 'rendered',
                    'pdf_rendered_page_count' => 3,
                    'pdf_ocr_status' => 'unavailable',
                    'pdf_rendered_pages' => [[
                        'page' => 1,
                        'path' => '/Users/example/storage/app/ai/attachments/pdf-pages/page-1.png',
                    ]],
                ]],
            ],
        ];

        $public = AiAttachmentPayload::publicAttachmentsFromPayload($payload);
        $this->assertCount(2, $public);
        $this->assertSame('image', $public[0]['kind']);
        $this->assertSame('atlas-clipboard-20260511-080900-a1b2.png', $public[0]['name']);
        $this->assertSame('file', $public[1]['kind']);
        $this->assertSame('documento.pdf', $public[1]['name']);
        $this->assertArrayNotHasKey('path', $public[0]);
        $this->assertArrayNotHasKey('original_path', $public[0]);
        $this->assertArrayNotHasKey('path', $public[1]);
        $this->assertArrayNotHasKey('text_excerpt', $public[1]);
        $this->assertSame(3, $public[1]['pdf_page_count']);
        $this->assertSame('processed', $public[1]['pdf_processing_status']);
        $this->assertSame(4, $public[1]['pdf_chunk_count']);
        $this->assertSame('rendered', $public[1]['pdf_render_status']);
        $this->assertArrayNotHasKey('pdf_rendered_pages', $public[1]);

        $sanitized = AiAttachmentPayload::sanitizePayload($payload);
        $this->assertArrayNotHasKey('path', $sanitized['attachments']['images'][0]);
        $this->assertArrayNotHasKey('original_path', $sanitized['attachments']['images'][0]);
        $this->assertArrayNotHasKey('path', $sanitized['attachments']['files'][0]);
        $this->assertArrayNotHasKey('text_excerpt', $sanitized['attachments']['files'][0]);
    }
}
