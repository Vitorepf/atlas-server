<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AiPromptAttachmentSupport;
use App\Services\Ai\Support\AiPromptInstructionSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pure attachment / YouTube / PDF / skill-catalog prompt peels — no AiPromptBuilder, no I/O.
 */
final class AiPromptAttachmentSupportTest extends TestCase
{
    public function test_attachment_instructions_empty_without_payload(): void
    {
        $this->assertSame('', AiPromptAttachmentSupport::attachmentInstructions([], 'hi'));
        $this->assertSame('', AiPromptAttachmentSupport::attachmentInstructions([
            'payload' => ['attachments' => ['images' => [], 'files' => []]],
        ], 'hi'));
    }

    public function test_attachment_instructions_images_and_plain_file(): void
    {
        $section = AiPromptAttachmentSupport::attachmentInstructions([
            'payload' => [
                'attachments' => [
                    'images' => [
                        ['mime_type' => 'image/png', 'bytes' => 1200, 'source' => 'paste'],
                    ],
                    'files' => [
                        [
                            'original_name' => 'notes.txt',
                            'mime_type' => 'text/plain',
                            'bytes' => 42,
                            'text_excerpt' => 'hello operator',
                            'text_truncated' => true,
                        ],
                    ],
                ],
            ],
        ], 'resumo');

        $this->assertStringContainsString('# Anexos enviados', $section);
        $this->assertStringContainsString('## Imagens', $section);
        $this->assertStringContainsString('imagem 1: image/png, 1200 bytes, origem paste.', $section);
        $this->assertStringContainsString('name="notes.txt"', $section);
        $this->assertStringContainsString('hello operator', $section);
        $this->assertStringContainsString('[conteudo truncado pelo Atlas]', $section);
    }

    public function test_attachment_instructions_pdf_pages_map_and_ocr(): void
    {
        $pages = [];
        for ($i = 1; $i <= 5; $i++) {
            $pages[] = [
                'page' => $i,
                'text_excerpt' => $i === 3 ? 'contrato de aluguel importante' : "pagina {$i} filler",
                'classification' => $i === 2 ? 'visual_or_scanned' : 'text',
                'table_count' => $i === 4 ? 2 : 0,
                'image_count' => $i === 1 ? 1 : 0,
                'structure' => [
                    'heading_candidates' => $i === 1 ? ['Introdução'] : [],
                ],
                'visual_caption' => $i === 2 ? 'assinatura digital' : '',
            ];
        }

        $section = AiPromptAttachmentSupport::attachmentInstructions([
            'payload' => [
                'attachments' => [
                    'files' => [
                        [
                            'original_name' => 'doc.pdf',
                            'mime_type' => 'application/pdf',
                            'bytes' => 9000,
                            'pdf_page_count' => 5,
                            'pdf_processing_status' => 'ready',
                            'pdf_render_status' => 'ready',
                            'pdf_ocr_status' => 'partial',
                            'pdf_visual_understanding_status' => 'ready',
                            'pdf_visual_page_strategy' => 'selected',
                            'pdf_visual_selected_pages' => [2, 4],
                            'pdf_pages' => $pages,
                            'pdf_ocr_pages' => [
                                ['page' => 2, 'text_excerpt' => 'ocr texto pagina 2'],
                            ],
                            'pdf_pages_truncated' => false,
                        ],
                    ],
                ],
            ],
        ], 'contrato aluguel');

        $this->assertStringContainsString('[pdf_metadata pages="5"', $section);
        $this->assertStringContainsString('<pdf_document_map visual_strategy="selected">', $section);
        $this->assertStringContainsString('<visual_pages>2, 4</visual_pages>', $section);
        $this->assertStringContainsString('<scanned_or_sparse_pages>2</scanned_or_sparse_pages>', $section);
        $this->assertStringContainsString('p. 4 (2)', $section);
        $this->assertStringContainsString('p. 1: Introdução', $section);
        $this->assertStringContainsString('<visual_caption>assinatura digital</visual_caption>', $section);
        $this->assertStringContainsString('<pdf_ocr_page page="2">', $section);
        $this->assertStringContainsString('ocr texto pagina 2', $section);
        $this->assertStringContainsString('contrato de aluguel importante', $section);
    }

    public function test_attachment_instructions_office_without_excerpt(): void
    {
        $section = AiPromptAttachmentSupport::attachmentInstructions([
            'payload' => [
                'attachments' => [
                    'files' => [
                        [
                            'original_name' => 'slides.pptx',
                            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'bytes' => 100,
                            'office_render_status' => 'ready',
                            'office_rendered_page_count' => 3,
                            'office_processing_status' => 'ready',
                        ],
                    ],
                ],
            ],
        ], 'slides');

        $this->assertStringContainsString('[office_metadata processing="ready" render="ready" visual_pages="3"]', $section);
        $this->assertStringContainsString('[sem texto extraido automaticamente deste Office]', $section);
    }

    public function test_youtube_knowledge_empty_and_ready_with_translation_gap(): void
    {
        $this->assertSame('', AiPromptAttachmentSupport::youtubeKnowledgeSection([]));

        $section = AiPromptAttachmentSupport::youtubeKnowledgeSection([
            'payload' => [
                'youtube_ingestion' => [
                    'videos' => [
                        [
                            'status' => 'ready',
                            'translation_required' => true,
                            'translation_status' => 'pending',
                            'url' => 'https://youtube.com/watch?v=abc',
                            'metadata' => [
                                'title' => 'How to Build',
                                'channel' => 'Dev Channel',
                                'duration_seconds' => 120,
                                'language' => 'en',
                                'webpage_url' => 'https://youtube.com/watch?v=abc',
                                'chapters' => [
                                    ['start_label' => '00:00', 'title' => 'Intro'],
                                ],
                                'description_excerpt' => 'A short description',
                            ],
                            'caption' => [
                                'language' => 'en',
                                'kind' => 'auto',
                                'timestamp_source' => 'estimated',
                            ],
                            'chunks' => [
                                [
                                    'index' => 0,
                                    'start_label' => '00:00',
                                    'end_label' => '00:10',
                                    'text' => 'Welcome to the video',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('# YouTube ingerido', $section);
        $this->assertStringContainsString('TRADUCAO HONESTA', $section);
        $this->assertStringContainsString('<youtube_video index="1" status="ready"', $section);
        $this->assertStringContainsString('title="How to Build"', $section);
        $this->assertStringContainsString('<timestamp_note>', $section);
        $this->assertStringContainsString('[00:00] Intro', $section);
        $this->assertStringContainsString('<transcript_chunk index="0" start="00:00" end="00:10">', $section);
        $this->assertStringContainsString('Welcome to the video', $section);
    }

    public function test_youtube_knowledge_processing_gap(): void
    {
        $section = AiPromptAttachmentSupport::youtubeKnowledgeSection([
            'payload' => [
                'youtube_ingestion' => [
                    'videos' => [
                        [
                            'status' => 'processing',
                            'reason' => 'whisper em andamento',
                            'metadata' => ['title' => 'T'],
                            'processing' => [
                                'stage' => 'transcribe',
                                'progress' => 40,
                                'estimated_remaining_seconds' => 30,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('<ingestion_gap>whisper em andamento</ingestion_gap>', $section);
        $this->assertStringContainsString('stage="transcribe"', $section);
        $this->assertStringNotContainsString('<transcript_chunk', $section);
    }

    public function test_select_pdf_pages_keeps_small_set_and_ranks_by_input(): void
    {
        $small = [
            ['page' => 1, 'text_excerpt' => 'a'],
            ['page' => 2, 'text_excerpt' => 'b'],
        ];
        $this->assertCount(2, AiPromptAttachmentSupport::selectPdfPagesForPrompt($small, 'anything', 36));

        $pages = [];
        for ($i = 1; $i <= 50; $i++) {
            $pages[] = [
                'page' => $i,
                'text_excerpt' => $i === 40 ? 'faturamento anual da empresa' : "texto generico pagina {$i}",
            ];
        }

        $selected = AiPromptAttachmentSupport::selectPdfPagesForPrompt($pages, 'faturamento anual', 5);
        $numbers = array_map(fn (array $p): int => (int) $p['page'], $selected);

        $this->assertCount(5, $selected);
        $this->assertContains(1, $numbers);
        $this->assertContains(2, $numbers);
        $this->assertContains(40, $numbers);
        $this->assertSame($numbers, array_values(array_unique($numbers)));
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers);
    }

    public function test_pdf_document_map_empty_and_populated(): void
    {
        $this->assertSame('', AiPromptAttachmentSupport::pdfDocumentMap([], [], 'none'));

        $map = AiPromptAttachmentSupport::pdfDocumentMap([
            [
                'page' => 1,
                'classification' => 'sparse',
                'table_count' => 1,
                'image_count' => 2,
                'structure' => ['heading_candidates' => ['Capítulo 1']],
            ],
        ], [1, 1, 0, 3], 'auto');

        $this->assertStringContainsString('visual_strategy="auto"', $map);
        $this->assertStringContainsString('<visual_pages>1, 3</visual_pages>', $map);
        $this->assertStringContainsString('<scanned_or_sparse_pages>1</scanned_or_sparse_pages>', $map);
        $this->assertStringContainsString('<table_like_pages>p. 1 (1)</table_like_pages>', $map);
        $this->assertStringContainsString('<image_or_chart_pages>p. 1 (2)</image_or_chart_pages>', $map);
        $this->assertStringContainsString('p. 1: Capítulo 1', $map);
    }

    public function test_skill_catalog_section_empty_and_with_compatibility(): void
    {
        $this->assertSame('', AiPromptInstructionSupport::skillCatalogSection([]));

        $section = AiPromptInstructionSupport::skillCatalogSection([
            [
                'name' => 'desenvolvedor',
                'description' => 'Implementa patches',
                'compatibility' => 'claude',
            ],
            [
                'name' => 'revisor',
                'description' => 'Revisa código',
            ],
        ]);

        $this->assertStringContainsString('# Available Skills', $section);
        $this->assertStringContainsString('- desenvolvedor: Implementa patches [claude]', $section);
        $this->assertStringContainsString('- revisor: Revisa código', $section);
        $this->assertStringContainsString('Use uma skill quando o pedido combinar', $section);
    }
}
