<?php

declare(strict_types=1);

namespace App\Services\Ai\Cli\Support;

/**
 * Pure Office/MIME/XML parse helpers peeled from
 * {@see \App\Services\Ai\Cli\AtlasFileAttachmentService}.
 *
 * No FS, ZipArchive open, Process, config, Storage, UploadedFile, or provider
 * I/O — only deterministic string/array/DOM projection for MIME classification,
 * OCR page-block merge, and OOXML/XLSX text extraction given already-loaded XML.
 * The host keeps zip open/read, binary render/OCR, and attachment I/O.
 */
final class FileAttachmentOfficeParseSupport
{
    /** @var list<string> */
    public const PLAIN_TEXT_EXTENSIONS = [
        'txt',
        'md',
        'markdown',
        'csv',
        'json',
        'xml',
        'html',
        'htm',
        'rtf',
        'log',
        'yaml',
        'yml',
    ];

    /** @var list<string> */
    public const OFFICE_EXTENSIONS = [
        'docx',
        'xlsx',
        'pptx',
    ];

    private function __construct()
    {
    }

    public static function isPlainTextLike(string $mime, string $extension): bool
    {
        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        return in_array(strtolower($extension), self::PLAIN_TEXT_EXTENSIONS, true);
    }

    public static function isPdf(string $mime, string $extension): bool
    {
        return strtolower($extension) === 'pdf' || $mime === 'application/pdf';
    }

    public static function isOfficeDocument(string $extension): bool
    {
        return in_array(strtolower($extension), self::OFFICE_EXTENSIONS, true);
    }

    /**
     * @param  list<string>  $pageBlocks
     * @param  list<array<string, mixed>>  $ocrPages
     * @return list<string>
     */
    public static function mergeOcrPageBlocks(array $pageBlocks, array $ocrPages): array
    {
        foreach ($ocrPages as $page) {
            $pageNumber = is_numeric($page['page'] ?? null) ? (int) $page['page'] : null;
            $text = is_string($page['text_excerpt'] ?? null) ? trim($page['text_excerpt']) : '';
            if (! $pageNumber || $text === '') {
                continue;
            }

            $pageBlocks[] = "[OCR p. {$pageNumber}]\n{$text}";
        }

        return $pageBlocks;
    }

    /**
     * @return list<string>
     */
    public static function parseXlsxSharedStringsXml(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $dom = self::loadXml($xml);
        if (! $dom) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $strings = [];
        foreach ($xpath->query('//*[local-name()="si"]') ?: [] as $node) {
            $chunks = [];
            foreach ($xpath->query('.//*[local-name()="t"]', $node) ?: [] as $textNode) {
                $chunks[] = $textNode->textContent;
            }

            $strings[] = implode('', $chunks);
        }

        return $strings;
    }

    /**
     * Pure workbook+rels projection for sheet name → package entry.
     * Callers supply a zip-existence probe so this stays free of ZipArchive.
     *
     * @param  array<string, string>  $fallback  sheetName => entry path
     * @param  callable(string): bool  $entryExists
     * @return array<string, string>
     */
    public static function parseXlsxSheetEntriesFromXml(
        string $workbookXml,
        string $relsXml,
        array $fallback,
        callable $entryExists,
    ): array {
        if ($workbookXml === '' || $relsXml === '') {
            return $fallback;
        }

        $workbookDom = self::loadXml($workbookXml);
        $relsDom = self::loadXml($relsXml);
        if (! $workbookDom || ! $relsDom) {
            return $fallback;
        }

        $relsXpath = new \DOMXPath($relsDom);
        $targetsById = [];
        foreach ($relsXpath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if (! $relationship instanceof \DOMElement) {
                continue;
            }

            $id = $relationship->getAttribute('Id');
            $target = ltrim($relationship->getAttribute('Target'), '/');
            if ($id !== '' && $target !== '') {
                $targetsById[$id] = str_starts_with($target, 'xl/')
                    ? $target
                    : 'xl/'.$target;
            }
        }

        $workbookXpath = new \DOMXPath($workbookDom);
        $sheets = [];
        foreach ($workbookXpath->query('//*[local-name()="sheet"]') ?: [] as $sheet) {
            if (! $sheet instanceof \DOMElement) {
                continue;
            }

            $name = trim($sheet->getAttribute('name')) ?: 'Sheet '.(count($sheets) + 1);
            $relationshipId = $sheet->getAttribute('r:id');
            $entry = $targetsById[$relationshipId] ?? null;
            if (is_string($entry) && $entryExists($entry)) {
                $sheets[$name] = $entry;
            }
        }

        return $sheets !== [] ? $sheets : $fallback;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<string>
     */
    public static function xlsxRows(string $xml, array $sharedStrings): array
    {
        $dom = self::loadXml($xml);
        if (! $dom) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//*[local-name()="row"]') ?: [] as $rowNode) {
            $cells = [];
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) ?: [] as $cellNode) {
                if (! $cellNode instanceof \DOMElement) {
                    continue;
                }

                $value = self::xlsxCellValue($xpath, $cellNode, $sharedStrings);
                if ($value !== '') {
                    $cells[] = $value;
                }
            }

            if ($cells !== []) {
                $rows[] = implode("\t", $cells);
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    public static function xlsxCellValue(\DOMXPath $xpath, \DOMElement $cell, array $sharedStrings): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 's') {
            $indexNodes = $xpath->query('./*[local-name()="v"]', $cell);
            $indexNode = $indexNodes instanceof \DOMNodeList ? $indexNodes->item(0) : null;
            $index = $indexNode ? (int) trim($indexNode->textContent) : null;

            return $index !== null ? trim($sharedStrings[$index] ?? '') : '';
        }

        if ($type === 'inlineStr') {
            $chunks = [];
            foreach ($xpath->query('.//*[local-name()="t"]', $cell) ?: [] as $textNode) {
                $chunks[] = $textNode->textContent;
            }

            return trim(implode('', $chunks));
        }

        $valueNodes = $xpath->query('./*[local-name()="v"]', $cell);
        $valueNode = $valueNodes instanceof \DOMNodeList ? $valueNodes->item(0) : null;

        return $valueNode ? trim($valueNode->textContent) : '';
    }

    public static function textFromOoxml(string $xml): string
    {
        $xml = preg_replace('/<\/(?:w|a):(?:p|tr)>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/(?:w|a):tc>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<(?:w|a):(?:br|tab)\b[^>]*\/>/', "\n", $xml) ?? $xml;

        return html_entity_decode(trim(strip_tags($xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function textNodesFromXml(string $xml): string
    {
        $dom = self::loadXml($xml);
        if (! $dom) {
            return self::textFromOoxml($xml);
        }

        $xpath = new \DOMXPath($dom);
        $chunks = [];
        foreach ($xpath->query('//*[local-name()="t"]') ?: [] as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return implode("\n", $chunks);
    }

    public static function loadXml(string $xml): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }

            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
