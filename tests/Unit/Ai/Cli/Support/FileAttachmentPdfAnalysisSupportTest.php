<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cli\Support;

use App\Services\Ai\Cli\Support\FileAttachmentPdfAnalysisSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-unit lock for {@see Support}
 * (string/array-only; no FS / Process / config / UploadedFile I/O).
 *
 * Explicit path proof: FileAttachment host imports Support and no longer
 * declares the peeled private methods.
 */
final class FileAttachmentPdfAnalysisSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Cli/Support/FileAttachmentPdfAnalysisSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Cli/AtlasFileAttachmentService.php';

    /** @var list<string> */
    private const PEELED = [
        'pdfPageChunks',
        'classifyPdfPage',
        'selectInitialPdfVisualPages',
        'evenlySampledPages',
        'normalizePageNumbers',
        'selectQueryPdfVisualPages',
        'explicitPageReferences',
        'queryTerms',
        'detectTables',
        'tableRowsToMarkdown',
        'tableColumnCount',
        'splitTableRow',
        'pageStructure',
        'enrichPdfPageVisuals',
        'visualCaptionForPage',
        'normalizeText',
        'safeOriginalNameString',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Cli\Support\FileAttachmentPdfAnalysisSupport;',
            $hostSrc,
            'Host must import FileAttachmentPdfAnalysisSupport',
        );

        // Public host call sites (internal Support-only helpers are not required on host).
        foreach ([
            'pdfPageChunks',
            'classifyPdfPage',
            'selectInitialPdfVisualPages',
            'normalizePageNumbers',
            'selectQueryPdfVisualPages',
            'detectTables',
            'pageStructure',
            'enrichPdfPageVisuals',
            'normalizeText',
            'safeOriginalNameString',
        ] as $method) {
            $this->assertStringContainsString(
                'FileAttachmentPdfAnalysisSupport::'.$method,
                $hostSrc,
                "Host must call Support::{$method}",
            );
        }

        foreach (self::PEELED as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
        }
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function classify_pdf_page_bands_by_char_density(): void
    {
        $this->assertSame('visual_or_scanned', Support::classifyPdfPage(''));
        $this->assertSame('sparse', Support::classifyPdfPage(str_repeat('a', 40)));
        $this->assertSame('mixed', Support::classifyPdfPage(str_repeat('a', 80)));
        $this->assertSame('textual', Support::classifyPdfPage(str_repeat('a', 400)));
    }

    #[Test]
    public function pdf_page_chunks_overlap_and_trim_empty(): void
    {
        $this->assertSame([], Support::pdfPageChunks('', 1));
        $this->assertSame([], Support::pdfPageChunks('   ', 2));

        $text = str_repeat('x', Support::PDF_CHUNK_CHARS + 50);
        $chunks = Support::pdfPageChunks($text, 3);
        $this->assertGreaterThanOrEqual(2, count($chunks));
        $this->assertSame(3, $chunks[0]['page']);
        $this->assertSame(1, $chunks[0]['chunk']);
        $this->assertSame(Support::PDF_CHUNK_CHARS, $chunks[0]['text_chars']);
        $this->assertSame(2, $chunks[1]['chunk']);
    }

    #[Test]
    public function evenly_sampled_pages_and_normalize_page_numbers(): void
    {
        $this->assertSame([], Support::evenlySampledPages(0, 3));
        $this->assertSame([1, 2, 3, 4, 5], Support::evenlySampledPages(5, 10));
        $sampled = Support::evenlySampledPages(10, 3);
        $this->assertCount(3, $sampled);
        $this->assertSame($sampled, array_values(array_unique($sampled)));
        foreach ($sampled as $page) {
            $this->assertGreaterThanOrEqual(1, $page);
            $this->assertLessThanOrEqual(10, $page);
        }

        $this->assertSame(
            [1, 3, 7],
            Support::normalizePageNumbers([7, 0, 3, 1, 3, -2, '7'], 10),
        );
        $this->assertSame([1, 2], Support::normalizePageNumbers([1, 2, 3, 4], 2));
    }

    #[Test]
    public function explicit_page_references_and_query_terms(): void
    {
        $this->assertSame([2, 10], Support::explicitPageReferences('ver pagina 2 e page 10 e p. 99', 12));
        $this->assertSame([], Support::explicitPageReferences('sem referencia', 5));

        $terms = Support::queryTerms('  Sobre o documento Atlas kernel gate  ');
        $this->assertNotContains('sobre', $terms);
        $this->assertNotContains('documento', $terms);
        $this->assertContains('atlas', $terms);
        $this->assertContains('kernel', $terms);
        $this->assertContains('gate', $terms);
    }

    #[Test]
    public function table_split_markdown_detect_and_page_structure(): void
    {
        $this->assertSame(['a', 'b', 'c'], Support::splitTableRow("a\tb\tc"));
        $this->assertSame(['x', 'y'], Support::splitTableRow('| x | y |'));
        $this->assertSame(['col1', 'col2', 'col3'], Support::splitTableRow('col1  col2  col3'));

        $md = Support::tableRowsToMarkdown([
            'Name  Value  Unit',
            'alpha  1  kg',
            'beta  2  m',
        ]);
        $this->assertStringContainsString('| Name | Value | Unit |', $md);
        $this->assertStringContainsString('| --- | --- | --- |', $md);
        $this->assertStringContainsString('| alpha | 1 | kg |', $md);
        $this->assertSame(3, Support::tableColumnCount(['a  b  c', '1  2  3']));

        $tables = Support::detectTables("noise\nA  B  C  D\n1  2  3  4\n5  6  7  8\n9  0  1  2\n");
        $this->assertSame(4, $tables['count']);
        $this->assertSame('high', $tables['confidence']);
        $this->assertSame(4, $tables['column_count']);
        $this->assertNotSame('', $tables['markdown']);

        $structure = Support::pageStructure("INTRODUCTION\n1. Scope here\nbody line\n", $tables, 2);
        $this->assertSame(3, $structure['line_count']);
        $this->assertContains('INTRODUCTION', $structure['heading_candidates']);
        $this->assertSame(4, $structure['table_like_rows']);
        $this->assertSame(2, $structure['image_count']);
        $this->assertSame('low', $structure['text_density']);
    }

    #[Test]
    public function select_initial_and_query_visual_pages_prefer_sparse_and_explicit(): void
    {
        $pages = [
            ['page' => 1, 'classification' => 'textual', 'table_count' => 0, 'image_count' => 0, 'text_chars' => 500, 'text_excerpt' => 'intro atlas'],
            ['page' => 4, 'classification' => 'sparse', 'table_count' => 2, 'image_count' => 1, 'text_chars' => 20, 'text_excerpt' => 'chart'],
            ['page' => 8, 'classification' => 'visual_or_scanned', 'table_count' => 0, 'image_count' => 3, 'text_chars' => 0, 'text_excerpt' => ''],
        ];

        $initial = Support::selectInitialPdfVisualPages($pages, 10, 3);
        $this->assertNotEmpty($initial);
        $this->assertContains(1, $initial); // opening boost
        $this->assertTrue(in_array(4, $initial, true) || in_array(8, $initial, true));

        $querySelected = Support::selectQueryPdfVisualPages($pages, 'veja pagina 8 e o chart', 10, 2);
        $this->assertContains(8, $querySelected);
    }

    #[Test]
    public function enrich_visuals_and_caption_and_normalize_and_safe_name(): void
    {
        $pages = [
            ['page' => 1, 'text_chars' => 10, 'table_count' => 1, 'image_count' => 0],
            ['page' => 2, 'text_chars' => 500, 'table_count' => 0, 'image_count' => 2],
        ];
        $rendered = [
            ['page' => 1, 'ocr_preprocess_status' => 'ok', 'orientation_degrees' => 0],
        ];
        $ocr = [
            ['page' => 1, 'text_chars' => 42],
        ];

        $enriched = Support::enrichPdfPageVisuals($pages, $rendered, $ocr);
        $this->assertTrue($enriched[0]['visual_available']);
        $this->assertFalse($enriched[1]['visual_available']);
        $this->assertTrue($enriched[0]['vision_fallback_recommended']);
        $this->assertStringContainsString('Pagina 1', $enriched[0]['visual_caption']);
        $this->assertStringContainsString('OCR disponivel', $enriched[0]['visual_caption']);
        $this->assertStringContainsString('possivel tabela', $enriched[0]['visual_caption']);

        $caption = Support::visualCaptionForPage(3, 0, 0, 2, 0);
        $this->assertSame('Pagina 3; sem texto nativo; 2 imagens/graficos embutidos.', $caption);

        $this->assertSame("a b\n\nc", Support::normalizeText("  a\t\tb\n\n\nc\0  "));
        $this->assertSame('arquivo', Support::safeOriginalNameString(''));
        $this->assertSame('meu_doc.pdf', Support::safeOriginalNameString('  meu/doc.pdf  '));
        $this->assertSame(180, mb_strlen(Support::safeOriginalNameString(str_repeat('á', 400))));
    }
}
