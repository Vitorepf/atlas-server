<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cli\Support;

use App\Services\Ai\Cli\Support\FileAttachmentOfficeParseSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-unit lock for {@see Support}
 * (string/array/DOM-only; no FS / Zip open / Process / config / UploadedFile I/O).
 *
 * Explicit path proof: FileAttachment host imports Support and no longer
 * declares the peeled private methods.
 */
final class FileAttachmentOfficeParseSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Cli/Support/FileAttachmentOfficeParseSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Cli/AtlasFileAttachmentService.php';

    /** @var list<string> */
    private const PEELED = [
        'isPlainTextLike',
        'isPdf',
        'isOfficeDocument',
        'mergeOcrPageBlocks',
        'parseXlsxSharedStringsXml',
        'parseXlsxSheetEntriesFromXml',
        'xlsxRows',
        'xlsxCellValue',
        'textFromOoxml',
        'textNodesFromXml',
        'loadXml',
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
            'use App\Services\Ai\Cli\Support\FileAttachmentOfficeParseSupport;',
            $hostSrc,
            'Host must import FileAttachmentOfficeParseSupport',
        );

        foreach ([
            'isPdf',
            'isOfficeDocument',
            'isPlainTextLike',
            'mergeOcrPageBlocks',
            'textFromOoxml',
            'textNodesFromXml',
            'xlsxRows',
            'parseXlsxSharedStringsXml',
            'parseXlsxSheetEntriesFromXml',
        ] as $method) {
            $this->assertStringContainsString(
                'FileAttachmentOfficeParseSupport::'.$method,
                $hostSrc,
                "Host must call Support::{$method}",
            );
        }

        foreach ([
            'isPlainTextLike',
            'isPdf',
            'isOfficeDocument',
            'mergeOcrPageBlocks',
            'xlsxRows',
            'xlsxCellValue',
            'textFromOoxml',
            'textNodesFromXml',
            'loadXml',
        ] as $method) {
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
    public function mime_and_extension_classifiers_are_deterministic(): void
    {
        $this->assertTrue(Support::isPlainTextLike('text/plain', 'bin'));
        $this->assertTrue(Support::isPlainTextLike('application/octet-stream', 'md'));
        $this->assertTrue(Support::isPlainTextLike('application/json', 'json'));
        $this->assertFalse(Support::isPlainTextLike('application/pdf', 'pdf'));

        $this->assertTrue(Support::isPdf('application/pdf', 'txt'));
        $this->assertTrue(Support::isPdf('application/octet-stream', 'pdf'));
        $this->assertTrue(Support::isPdf('application/octet-stream', 'PDF'));
        $this->assertFalse(Support::isPdf('text/plain', 'txt'));

        $this->assertTrue(Support::isOfficeDocument('docx'));
        $this->assertTrue(Support::isOfficeDocument('XLSX'));
        $this->assertTrue(Support::isOfficeDocument('pptx'));
        $this->assertFalse(Support::isOfficeDocument('pdf'));
    }

    #[Test]
    public function merge_ocr_page_blocks_appends_only_valid_pages(): void
    {
        $merged = Support::mergeOcrPageBlocks(
            ['native page'],
            [
                ['page' => 2, 'text_excerpt' => '  OCR text  '],
                ['page' => null, 'text_excerpt' => 'skip'],
                ['page' => 3, 'text_excerpt' => ''],
                ['page' => '4', 'text_excerpt' => 'four'],
            ],
        );

        $this->assertSame(
            [
                'native page',
                "[OCR p. 2]\nOCR text",
                "[OCR p. 4]\nfour",
            ],
            $merged,
        );
    }

    #[Test]
    public function text_from_ooxml_and_text_nodes_from_xml_extract_content(): void
    {
        $ooxml = '<w:document><w:p><w:t>Hello</w:t></w:p><w:p><w:t>World</w:t></w:p>'
            .'<w:tc><w:t>cell</w:t></w:tc><w:br/></w:document>';
        $fromOoxml = Support::textFromOoxml($ooxml);
        $this->assertStringContainsString('Hello', $fromOoxml);
        $this->assertStringContainsString('World', $fromOoxml);
        $this->assertStringContainsString('cell', $fromOoxml);

        $xml = '<?xml version="1.0"?><root xmlns:a="urn:a">'
            .'<a:t> Alpha </a:t><a:t></a:t><a:t>Beta</a:t></root>';
        $nodes = Support::textNodesFromXml($xml);
        $this->assertSame("Alpha\nBeta", $nodes);

        // Invalid XML falls back to strip_tags OOXML path (still deterministic, no I/O).
        $this->assertSame('not-xml-at-all', Support::textNodesFromXml('not-xml-at-all'));
        $this->assertNull(Support::loadXml('<broken'));
        $this->assertInstanceOf(\DOMDocument::class, Support::loadXml('<ok/>'));
    }

    #[Test]
    public function xlsx_shared_strings_rows_and_sheet_entries_parse_pure_xml(): void
    {
        $sharedXml = '<?xml version="1.0"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<si><t>Name</t></si>'
            .'<si><r><t>Val</t></r><r><t>ue</t></r></si>'
            .'</sst>';
        $shared = Support::parseXlsxSharedStringsXml($sharedXml);
        $this->assertSame(['Name', 'Value'], $shared);
        $this->assertSame([], Support::parseXlsxSharedStringsXml(''));
        $this->assertSame([], Support::parseXlsxSharedStringsXml('<not-shared/>'));

        $sheetXml = '<?xml version="1.0"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'
            .'<row>'
            .'<c t="s"><v>0</v></c>'
            .'<c t="s"><v>1</v></c>'
            .'<c><v>42</v></c>'
            .'<c t="inlineStr"><is><t>inline</t></is></c>'
            .'</row>'
            .'<row><c t="s"><v>99</v></c></row>'
            .'</sheetData>'
            .'</worksheet>';
        $rows = Support::xlsxRows($sheetXml, $shared);
        $this->assertSame(["Name\tValue\t42\tinline"], $rows);

        $workbook = '<?xml version="1.0"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="Alpha" r:id="rId1"/>'
            .'<sheet name="Beta" r:id="rId2"/>'
            .'<sheet name="Missing" r:id="rId9"/>'
            .'</sheets>'
            .'</workbook>';
        $rels = '<?xml version="1.0"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Target="/xl/worksheets/sheet2.xml"/>'
            .'</Relationships>';
        $fallback = ['Sheet 1' => 'xl/worksheets/sheet1.xml'];
        $entries = Support::parseXlsxSheetEntriesFromXml(
            $workbook,
            $rels,
            $fallback,
            static fn (string $entry): bool => in_array($entry, [
                'xl/worksheets/sheet1.xml',
                'xl/worksheets/sheet2.xml',
            ], true),
        );
        $this->assertSame([
            'Alpha' => 'xl/worksheets/sheet1.xml',
            'Beta' => 'xl/worksheets/sheet2.xml',
        ], $entries);

        $this->assertSame(
            $fallback,
            Support::parseXlsxSheetEntriesFromXml('', '', $fallback, static fn (): bool => true),
        );
    }

    #[Test]
    public function xlsx_cell_value_shared_inline_and_raw(): void
    {
        $dom = Support::loadXml(
            '<?xml version="1.0"?><root>'
            .'<c t="s" id="shared"><v>1</v></c>'
            .'<c t="inlineStr" id="inline"><is><t>hello</t><t> world</t></is></c>'
            .'<c id="raw"><v> 3.14 </v></c>'
            .'<c t="s" id="missing"><v>9</v></c>'
            .'</root>',
        );
        $this->assertNotNull($dom);
        $xpath = new \DOMXPath($dom);
        $shared = ['zero', 'one'];

        $sharedCell = $xpath->query('//*[@id="shared"]')->item(0);
        $inlineCell = $xpath->query('//*[@id="inline"]')->item(0);
        $rawCell = $xpath->query('//*[@id="raw"]')->item(0);
        $missingCell = $xpath->query('//*[@id="missing"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $sharedCell);
        $this->assertInstanceOf(\DOMElement::class, $inlineCell);
        $this->assertInstanceOf(\DOMElement::class, $rawCell);
        $this->assertInstanceOf(\DOMElement::class, $missingCell);

        $this->assertSame('one', Support::xlsxCellValue($xpath, $sharedCell, $shared));
        $this->assertSame('hello world', Support::xlsxCellValue($xpath, $inlineCell, $shared));
        $this->assertSame('3.14', Support::xlsxCellValue($xpath, $rawCell, $shared));
        $this->assertSame('', Support::xlsxCellValue($xpath, $missingCell, $shared));
    }
}
