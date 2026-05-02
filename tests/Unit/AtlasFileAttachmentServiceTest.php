<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasFileAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AtlasFileAttachmentServiceTest extends TestCase
{
    private string $root;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/atlas-file-attachment-test-'.bin2hex(random_bytes(4));
        $this->workspace = $this->root.'/workspace';
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_builds_text_attachment_from_uploaded_file(): void
    {
        $source = $this->root.'/notes.md';
        File::put($source, "# Tese\n\nConteudo do arquivo anexado.");

        $upload = new UploadedFile($source, 'notes.md', 'text/markdown', null, true);
        $attachment = app(AtlasFileAttachmentService::class)->fromUploadedFile($upload, $this->workspace, 'mobile_upload');

        $this->assertFileExists($attachment['path']);
        $this->assertSame('notes.md', $attachment['original_name']);
        $this->assertSame('mobile_upload', $attachment['source']);
        $this->assertSame(hash_file('sha256', $source), $attachment['sha256']);
        $this->assertTrue($attachment['text_available']);
        $this->assertStringContainsString('Conteudo do arquivo anexado.', $attachment['text_excerpt']);

        File::delete($attachment['path']);
    }

    public function test_rejects_large_uploaded_file(): void
    {
        $source = $this->root.'/large.txt';
        File::put($source, str_repeat('a', 20_971_521));

        $upload = new UploadedFile($source, 'large.txt', 'text/plain', null, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('grande demais');

        app(AtlasFileAttachmentService::class)->fromUploadedFile($upload, $this->workspace);
    }

    public function test_extracts_text_from_uploaded_pdf(): void
    {
        $source = $this->root.'/atlas.pdf';
        $this->writeSimplePdf($source, 'Atlas PDF real');

        $upload = new UploadedFile($source, 'atlas.pdf', 'application/pdf', null, true);
        $attachment = app(AtlasFileAttachmentService::class)->fromUploadedFile($upload, $this->workspace);

        $this->assertTrue($attachment['text_available']);
        $this->assertStringContainsString('Atlas PDF real', $attachment['text_excerpt']);
        $this->assertSame('processed', $attachment['pdf_processing_status']);
        $this->assertSame(1, $attachment['pdf_page_count']);
        $this->assertTrue($attachment['pdf_text_available']);
        $this->assertSame(1, $attachment['pdf_chunk_count']);
        $this->assertNotEmpty($attachment['pdf_chunks']);
        $this->assertSame(1, $attachment['pdf_chunks'][0]['page']);
        $this->assertNotEmpty($attachment['pdf_pages']);
        $this->assertSame(1, $attachment['pdf_pages'][0]['page']);
        $this->assertStringContainsString('Atlas PDF real', $attachment['pdf_pages'][0]['text_excerpt']);

        foreach ($attachment['pdf_rendered_pages'] ?? [] as $renderedPage) {
            if (is_array($renderedPage) && is_string($renderedPage['path'] ?? null)) {
                File::delete($renderedPage['path']);
            }
        }
        File::delete($attachment['path']);
    }

    public function test_extracts_text_from_uploaded_xlsx(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $source = $this->root.'/atlas.xlsx';
        $this->writeSimpleXlsx($source);

        $upload = new UploadedFile(
            $source,
            'atlas.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $attachment = app(AtlasFileAttachmentService::class)->fromUploadedFile($upload, $this->workspace);

        $this->assertTrue($attachment['text_available']);
        $this->assertStringContainsString('Planilha: Dados', $attachment['text_excerpt']);
        $this->assertStringContainsString('Nome Valor', $attachment['text_excerpt']);
        $this->assertStringContainsString('Atlas 42', $attachment['text_excerpt']);

        File::delete($attachment['path']);
    }

    public function test_extracts_text_from_uploaded_pptx(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $source = $this->root.'/atlas.pptx';
        $this->writeSimplePptx($source);

        $upload = new UploadedFile(
            $source,
            'atlas.pptx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            null,
            true,
        );
        $attachment = app(AtlasFileAttachmentService::class)->fromUploadedFile($upload, $this->workspace);

        $this->assertTrue($attachment['text_available']);
        $this->assertStringContainsString('Slide 1:', $attachment['text_excerpt']);
        $this->assertStringContainsString('Atlas PPTX real', $attachment['text_excerpt']);

        File::delete($attachment['path']);
    }

    private function writeSimplePdf(string $path, string $text): void
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 24 Tf 72 720 Td ({$escapedText}) Tj ET";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            "5 0 obj << /Length ".strlen($stream)." >> stream\n{$stream}\nendstream\nendobj",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        File::put($path, $pdf);
    }

    private function writeSimpleXlsx(string $path): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Dados" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Nome</t></si><si><t>Valor</t></si><si><t>Atlas</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>42</v></c></row></sheetData></worksheet>');

        $zip->close();
    }

    private function writeSimplePptx(string $path): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('ppt/slides/slide1.xml', '<?xml version="1.0" encoding="UTF-8"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>Atlas PPTX real</a:t></a:r></a:p><a:p><a:r><a:t>Segundo bloco</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>');
        $zip->close();
    }
}
