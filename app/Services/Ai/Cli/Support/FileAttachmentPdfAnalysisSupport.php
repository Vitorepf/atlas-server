<?php

declare(strict_types=1);

namespace App\Services\Ai\Cli\Support;

/**
 * Pure PDF/table/query analysis helpers peeled from
 * {@see \App\Services\Ai\Cli\AtlasFileAttachmentService}.
 *
 * No FS, Process, config, Storage, UploadedFile, or provider I/O — only
 * deterministic string/array projection for page classification, chunking,
 * table detection, visual-page selection, and filename sanitization.
 * The host keeps render/OCR/process binaries and attachment I/O.
 */
final class FileAttachmentPdfAnalysisSupport
{
    public const PDF_CHUNK_CHARS = 1_600;

    public const PDF_CHUNK_OVERLAP_CHARS = 180;

    public const PDF_OCR_TEXT_THRESHOLD_CHARS = 80;

    private function __construct()
    {
    }

    /**
     * @return list<array{page:int,chunk:int,text_excerpt:string,text_chars:int}>
     */
    public static function pdfPageChunks(string $pageText, int $pageNumber): array
    {
        $length = mb_strlen($pageText);
        if ($length <= 0) {
            return [];
        }

        $chunks = [];
        $start = 0;
        $chunkNumber = 1;
        while ($start < $length) {
            $text = mb_substr($pageText, $start, self::PDF_CHUNK_CHARS);
            $text = trim($text);
            if ($text !== '') {
                $chunks[] = [
                    'page' => $pageNumber,
                    'chunk' => $chunkNumber,
                    'text_excerpt' => $text,
                    'text_chars' => mb_strlen($text),
                ];
                $chunkNumber++;
            }

            if ($start + self::PDF_CHUNK_CHARS >= $length) {
                break;
            }

            $start += self::PDF_CHUNK_CHARS - self::PDF_CHUNK_OVERLAP_CHARS;
        }

        return $chunks;
    }

    public static function classifyPdfPage(string $text): string
    {
        $chars = mb_strlen($text);
        if ($chars >= 400) {
            return 'textual';
        }

        if ($chars >= self::PDF_OCR_TEXT_THRESHOLD_CHARS) {
            return 'mixed';
        }

        if ($chars > 0) {
            return 'sparse';
        }

        return 'visual_or_scanned';
    }

    /**
     * Seleciona paginas visuais com alto valor informacional:
     * abertura, paginas escaneadas/sparse, tabelas, imagens/graficos e amostras
     * distribuidas. Isso aumenta cobertura de PDFs longos sem renderizar tudo.
     *
     * @param  array<int,array<string,mixed>>  $pages
     * @return array<int,int>
     */
    public static function selectInitialPdfVisualPages(array $pages, int $totalPages, int $limit): array
    {
        if ($limit <= 0 || $totalPages <= 0) {
            return [];
        }

        $scores = [];
        foreach (range(1, min(3, $totalPages)) as $page) {
            $scores[$page] = ($scores[$page] ?? 0) + 120;
        }

        foreach ($pages as $page) {
            $number = (int) ($page['page'] ?? 0);
            if ($number < 1 || $number > $totalPages) {
                continue;
            }

            $classification = (string) ($page['classification'] ?? '');
            $tableCount = (int) ($page['table_count'] ?? 0);
            $imageCount = (int) ($page['image_count'] ?? 0);
            $textChars = (int) ($page['text_chars'] ?? 0);

            $score = $scores[$number] ?? 0;
            if (in_array($classification, ['visual_or_scanned', 'sparse'], true)) {
                $score += 100;
            } elseif ($classification === 'mixed') {
                $score += 55;
            }
            if ($tableCount > 0) {
                $score += min(80, 35 + ($tableCount * 8));
            }
            if ($imageCount > 0) {
                $score += min(80, 35 + ($imageCount * 10));
            }
            if ($textChars > 0 && $textChars < self::PDF_OCR_TEXT_THRESHOLD_CHARS) {
                $score += 45;
            }
            if ((bool) ($page['text_truncated'] ?? false)) {
                $score += 20;
            }

            if ($score > 0) {
                $scores[$number] = $score;
            }
        }

        $sampleCount = min($limit, max(0, (int) ceil($limit * 0.25)));
        if ($sampleCount > 0 && $totalPages > 3) {
            foreach (self::evenlySampledPages($totalPages, $sampleCount) as $page) {
                $scores[$page] = ($scores[$page] ?? 0) + 35;
            }
        }

        arsort($scores);

        return collect(array_keys($scores))
            ->map(fn (mixed $page): int => (int) $page)
            ->filter(fn (int $page): bool => $page >= 1 && $page <= $totalPages)
            ->take($limit)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    public static function evenlySampledPages(int $totalPages, int $count): array
    {
        if ($totalPages <= 0 || $count <= 0) {
            return [];
        }

        if ($count >= $totalPages) {
            return range(1, $totalPages);
        }

        $pages = [];
        for ($index = 1; $index <= $count; $index++) {
            $page = (int) round(($index * $totalPages) / ($count + 1));
            $page = max(1, min($totalPages, $page));
            $pages[$page] = true;
        }

        return array_keys($pages);
    }

    /**
     * @param  array<int,int|string|float>  $pageNumbers
     * @return array<int,int>
     */
    public static function normalizePageNumbers(array $pageNumbers, int $limit): array
    {
        return collect($pageNumbers)
            ->map(fn (mixed $page): int => (int) $page)
            ->filter(fn (int $page): bool => $page > 0)
            ->unique()
            ->sort()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $pages
     * @return array<int,int>
     */
    public static function selectQueryPdfVisualPages(array $pages, string $query, int $totalPages, int $limit): array
    {
        $explicitPages = self::explicitPageReferences($query, $totalPages);
        $terms = self::queryTerms($query);
        $scores = [];

        foreach ($explicitPages as $page) {
            $scores[$page] = ($scores[$page] ?? 0) + 500;
        }

        foreach ($pages as $page) {
            $number = (int) ($page['page'] ?? 0);
            if ($number < 1 || $number > $totalPages) {
                continue;
            }

            $haystack = mb_strtolower(implode("\n", array_filter([
                (string) ($page['text_excerpt'] ?? ''),
                (string) ($page['table_excerpt'] ?? ''),
                (string) ($page['visual_caption'] ?? ''),
                implode(' ', (array) data_get($page, 'structure.heading_candidates', [])),
            ])));

            $score = $scores[$number] ?? 0;
            foreach ($terms as $term) {
                $score += substr_count($haystack, $term) * 18;
            }
            if ((bool) ($page['vision_fallback_recommended'] ?? false)) {
                $score += 45;
            }
            if ((int) ($page['table_count'] ?? 0) > 0) {
                $score += 30;
            }
            if ((int) ($page['image_count'] ?? 0) > 0) {
                $score += 30;
            }
            if (in_array((string) ($page['classification'] ?? ''), ['visual_or_scanned', 'sparse'], true)) {
                $score += 35;
            }

            if ($score > 0) {
                $scores[$number] = $score;
            }
        }

        arsort($scores);

        return collect(array_keys($scores))
            ->map(fn (mixed $page): int => (int) $page)
            ->filter(fn (int $page): bool => $page >= 1 && $page <= $totalPages)
            ->take($limit)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    public static function explicitPageReferences(string $query, int $totalPages): array
    {
        preg_match_all('/\b(?:p(?:ag(?:ina)?)?\.?|page)\s*(\d{1,4})\b/iu', $query, $matches);
        $pages = [];
        foreach ($matches[1] ?? [] as $value) {
            $page = (int) $value;
            if ($page >= 1 && $page <= $totalPages) {
                $pages[$page] = true;
            }
        }

        return array_keys($pages);
    }

    /**
     * @return array<int,string>
     */
    public static function queryTerms(string $query): array
    {
        $query = mb_strtolower(self::normalizeText($query));
        preg_match_all('/[\pL\pN]{4,}/u', $query, $matches);

        return collect($matches[0] ?? [])
            ->reject(fn (string $term): bool => in_array($term, ['sobre', 'para', 'como', 'qual', 'quais', 'esse', 'essa', 'documento', 'arquivo'], true))
            ->unique()
            ->take(24)
            ->values()
            ->all();
    }

    /**
     * @return array{count:int,excerpt:string,markdown:string,confidence:string,column_count:int}
     */
    public static function detectTables(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $looksTabular = str_contains($trimmed, "\t")
                || substr_count($trimmed, '|') >= 2
                || preg_match('/\S+\s{2,}\S+\s{2,}\S+/', $trimmed) === 1
                || preg_match('/(?:\d+[,.]?\d*\s+){3,}/', $trimmed) === 1;
            if ($looksTabular) {
                $rows[] = $trimmed;
            }
        }

        $markdown = self::tableRowsToMarkdown($rows);
        $columnCount = self::tableColumnCount($rows);
        $confidence = match (true) {
            count($rows) >= 4 && $columnCount >= 3 => 'high',
            count($rows) >= 2 && $columnCount >= 2 => 'medium',
            count($rows) > 0 => 'low',
            default => 'none',
        };

        return [
            'count' => count($rows),
            'excerpt' => mb_substr(implode("\n", array_slice($rows, 0, 10)), 0, 1600),
            'markdown' => mb_substr($markdown, 0, 2400),
            'confidence' => $confidence,
            'column_count' => $columnCount,
        ];
    }

    /**
     * @param  array<int,string>  $rows
     */
    public static function tableRowsToMarkdown(array $rows): string
    {
        $parsed = collect(array_slice($rows, 0, 12))
            ->map(fn (string $row): array => self::splitTableRow($row))
            ->filter(fn (array $columns): bool => count($columns) >= 2)
            ->values()
            ->all();

        if ($parsed === []) {
            return '';
        }

        $width = min(8, max(array_map('count', $parsed)));
        $normalized = array_map(function (array $columns) use ($width): array {
            $columns = array_slice($columns, 0, $width);
            while (count($columns) < $width) {
                $columns[] = '';
            }

            return array_map(fn (string $value): string => str_replace('|', '\\|', trim($value)), $columns);
        }, $parsed);

        $header = $normalized[0];
        $separator = array_fill(0, $width, '---');
        $body = array_slice($normalized, 1);

        return collect([$header, $separator, ...$body])
            ->map(fn (array $columns): string => '| '.implode(' | ', $columns).' |')
            ->implode("\n");
    }

    /**
     * @param  array<int,string>  $rows
     */
    public static function tableColumnCount(array $rows): int
    {
        return collect($rows)
            ->map(fn (string $row): int => count(self::splitTableRow($row)))
            ->max() ?: 0;
    }

    /**
     * @return array<int,string>
     */
    public static function splitTableRow(string $row): array
    {
        if (str_contains($row, "\t")) {
            $columns = preg_split('/\t+/', $row) ?: [];
        } elseif (substr_count($row, '|') >= 2) {
            $columns = explode('|', trim($row, '| '));
        } else {
            $columns = preg_split('/\s{2,}/', $row) ?: [];
            if (count($columns) < 2 && preg_match('/(?:\d+[,.]?\d*\s+){2,}/', $row) === 1) {
                $columns = preg_split('/\s+/', $row) ?: [];
            }
        }

        return collect($columns)
            ->map(fn (mixed $column): string => trim((string) $column))
            ->filter(fn (string $column): bool => $column !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array{count:int,excerpt?:string}  $tables
     * @return array<string,mixed>
     */
    public static function pageStructure(string $text, array $tables, int $imageCount): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
        $headings = collect($lines)
            ->filter(fn (string $line): bool => mb_strlen($line) <= 90 && (preg_match('/^[A-Z0-9 .:_-]{8,}$/u', $line) === 1 || preg_match('/^\d+(?:\.\d+)*\s+\S+/', $line) === 1))
            ->take(5)
            ->values()
            ->all();

        return [
            'line_count' => count($lines),
            'heading_candidates' => $headings,
            'table_like_rows' => $tables['count'],
            'image_count' => $imageCount,
            'text_density' => mb_strlen($text) >= 400 ? 'high' : (mb_strlen($text) >= 80 ? 'medium' : 'low'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $pages
     * @param  array<int,array<string,mixed>>  $renderedPages
     * @param  array<int,array<string,mixed>>  $ocrPages
     * @return array<int,array<string,mixed>>
     */
    public static function enrichPdfPageVisuals(array $pages, array $renderedPages, array $ocrPages): array
    {
        $renderedByPage = collect($renderedPages)->keyBy(fn (array $page): int => (int) ($page['page'] ?? 0));
        $ocrByPage = collect($ocrPages)->keyBy(fn (array $page): int => (int) ($page['page'] ?? 0));

        return collect($pages)
            ->map(function (array $page) use ($renderedByPage, $ocrByPage): array {
                $pageNumber = (int) ($page['page'] ?? 0);
                $rendered = $renderedByPage->get($pageNumber);
                $ocr = $ocrByPage->get($pageNumber);
                $textChars = (int) ($page['text_chars'] ?? 0);
                $tableCount = (int) ($page['table_count'] ?? 0);
                $imageCount = (int) ($page['image_count'] ?? 0);
                $caption = self::visualCaptionForPage($pageNumber, $textChars, $tableCount, $imageCount, is_array($ocr) ? (int) ($ocr['text_chars'] ?? 0) : 0);

                return [
                    ...$page,
                    'visual_available' => is_array($rendered),
                    'visual_caption' => $caption,
                    'ocr_preprocess_status' => is_array($rendered) ? ($rendered['ocr_preprocess_status'] ?? null) : null,
                    'orientation_degrees' => is_array($rendered) ? ($rendered['orientation_degrees'] ?? null) : null,
                    'vision_fallback_recommended' => is_array($rendered) && ($textChars < self::PDF_OCR_TEXT_THRESHOLD_CHARS || $imageCount > 0 || $tableCount > 0),
                ];
            })
            ->values()
            ->all();
    }

    public static function visualCaptionForPage(int $page, int $textChars, int $tableCount, int $imageCount, int $ocrChars): string
    {
        $parts = ["Pagina {$page}"];
        $parts[] = $textChars >= 400 ? 'com texto nativo denso' : ($textChars > 0 ? 'com pouco texto nativo' : 'sem texto nativo');
        if ($ocrChars > 0) {
            $parts[] = 'OCR disponivel';
        }
        if ($tableCount > 0) {
            $parts[] = $tableCount === 1 ? 'possivel tabela' : "{$tableCount} linhas tabulares";
        }
        if ($imageCount > 0) {
            $parts[] = $imageCount === 1 ? 'imagem/grafico embutido' : "{$imageCount} imagens/graficos embutidos";
        }

        return implode('; ', $parts).'.';
    }

    public static function normalizeText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function safeOriginalNameString(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'arquivo';
        }

        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'arquivo';

        return mb_substr($name, 0, 180);
    }
}
