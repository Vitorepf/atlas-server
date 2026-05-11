<?php

namespace App\Services\Ai\Cli;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class AtlasFileAttachmentService
{
    private const MAX_FILE_BYTES = 20_971_520;
    private const MAX_EXCERPT_CHARS = 20_000;
    private const MAX_PDF_STORED_PAGES = 240;
    private const MAX_PDF_PAGE_EXCERPT_CHARS = 3_000;
    private const MAX_PDF_CHUNKS = 480;
    private const PDF_CHUNK_CHARS = 1_600;
    private const PDF_CHUNK_OVERLAP_CHARS = 180;
    private const DEFAULT_PDF_RENDERED_PAGES = 24;
    private const MAX_OFFICE_RENDERED_PAGES = 12;
    private const PDF_OCR_TEXT_THRESHOLD_CHARS = 80;

    /**
     * @param  array<int,UploadedFile>  $files
     * @return array<int,array<string,mixed>>
     */
    public function fromUploadedFiles(array $files, string $workspace, string $source = 'upload'): array
    {
        $attachments = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $attachments[] = $this->fromUploadedFile($file, $workspace, $source);
        }

        return $this->dedupe($attachments);
    }

    /**
     * @return array<string,mixed>
     */
    public function fromUploadedFile(UploadedFile $file, string $workspace, string $source = 'upload'): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Arquivo enviado invalido.');
        }

        $bytes = (int) $file->getSize();
        if ($bytes <= 0 || $bytes > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Arquivo enviado invalido ou grande demais.');
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! File::isFile($realPath)) {
            throw new RuntimeException('Arquivo temporario enviado nao encontrado.');
        }

        $originalName = $this->safeOriginalName($file);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $target = storage_path('app/ai/attachments/document-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).($extension ? '.'.$extension : ''));
        File::ensureDirectoryExists(dirname($target));
        File::copy($realPath, $target);

        $mime = $file->getMimeType() ?: File::mimeType($target) ?: 'application/octet-stream';
        $content = $this->extractContent($target, $mime, $originalName);

        return [
            'path' => $target,
            'source' => $source,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $target),
            'text_excerpt' => $content['excerpt'],
            'text_truncated' => $content['truncated'],
            'text_available' => $content['excerpt'] !== '',
            ...$content['metadata'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function fromLocalUploadPath(string $path, string $originalName, string $mime, string $workspace, string $source = 'chunked_upload'): array
    {
        $resolved = realpath($path);
        if (! $resolved || ! File::isFile($resolved)) {
            throw new RuntimeException('Arquivo enviado em chunks nao encontrado.');
        }

        $bytes = File::size($resolved);
        if ($bytes <= 0 || $bytes > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Arquivo enviado invalido ou grande demais.');
        }

        $originalName = $this->safeOriginalNameString($originalName);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $target = storage_path('app/ai/attachments/document-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).($extension ? '.'.$extension : ''));
        File::ensureDirectoryExists(dirname($target));
        File::copy($resolved, $target);

        $mime = File::mimeType($target) ?: $mime ?: 'application/octet-stream';
        $content = $this->extractContent($target, $mime, $originalName);

        return [
            'path' => $target,
            'source' => $source,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $target),
            'text_excerpt' => $content['excerpt'],
            'text_truncated' => $content['truncated'],
            'text_available' => $content['excerpt'] !== '',
            ...$content['metadata'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     * @return array<int,array<string,mixed>>
     */
    public function dedupe(array $attachments): array
    {
        $byHash = [];
        foreach ($attachments as $attachment) {
            $hash = is_string($attachment['sha256'] ?? null) ? $attachment['sha256'] : null;
            if (! $hash || isset($byHash[$hash])) {
                continue;
            }

            $byHash[$hash] = $attachment;
        }

        return array_values($byHash);
    }

    /**
     * Reprocessa um anexo ja salvo, usado pelo worker de background para OCR/render/index.
     *
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    public function enhanceStoredAttachment(array $attachment): array
    {
        $path = is_string($attachment['path'] ?? null) ? $attachment['path'] : '';
        if ($path === '' || ! File::isFile($path)) {
            return $attachment;
        }

        $name = is_string($attachment['original_name'] ?? null) ? $attachment['original_name'] : basename($path);
        $mime = is_string($attachment['mime_type'] ?? null)
            ? $attachment['mime_type']
            : (File::mimeType($path) ?: 'application/octet-stream');

        $content = $this->extractContent($path, $mime, $name);

        return [
            ...$attachment,
            'text_excerpt' => $content['excerpt'],
            'text_truncated' => $content['truncated'],
            'text_available' => $content['excerpt'] !== '',
            ...$content['metadata'],
            'attachment_processing_status' => 'processed',
            'attachment_processing_completed_at' => now()->toJSON(),
        ];
    }

    /**
     * Renderiza paginas adicionais relevantes para a pergunta atual sem
     * reprocessar o PDF inteiro. Usa o cache de paginas ja renderizadas e
     * complementa apenas lacunas de alto valor.
     *
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    public function enhancePdfForQuery(array $attachment, string $query, int $maxAdditionalPages = 8): array
    {
        $path = is_string($attachment['path'] ?? null) ? $attachment['path'] : '';
        if ($path === '' || ! File::isFile($path) || ! $this->isPdf(
            is_string($attachment['mime_type'] ?? null) ? $attachment['mime_type'] : '',
            strtolower(pathinfo(is_string($attachment['original_name'] ?? null) ? $attachment['original_name'] : $path, PATHINFO_EXTENSION)),
        )) {
            return $attachment;
        }

        $pages = is_array($attachment['pdf_pages'] ?? null) ? $attachment['pdf_pages'] : [];
        $renderedPages = is_array($attachment['pdf_rendered_pages'] ?? null) ? $attachment['pdf_rendered_pages'] : [];
        if ($pages === [] || $maxAdditionalPages <= 0) {
            return $attachment;
        }

        $pageCount = is_numeric($attachment['pdf_page_count'] ?? null) ? (int) $attachment['pdf_page_count'] : count($pages);
        $existingNumbers = collect($renderedPages)
            ->map(fn (mixed $page): int => is_array($page) ? (int) ($page['page'] ?? 0) : 0)
            ->filter(fn (int $page): bool => $page > 0)
            ->unique()
            ->all();
        $selected = collect($this->selectQueryPdfVisualPages($pages, $query, $pageCount, $maxAdditionalPages))
            ->reject(fn (int $page): bool => in_array($page, $existingNumbers, true))
            ->take($maxAdditionalPages)
            ->values()
            ->all();

        if ($selected === []) {
            return [
                ...$attachment,
                'pdf_query_visualization' => [
                    'status' => 'cache_hit',
                    'query_hash' => hash('sha256', mb_substr($query, 0, 400)),
                    'selected_pages' => [],
                    'rendered_page_count' => count($renderedPages),
                ],
            ];
        }

        $render = $this->renderPdfPages($path, count($selected), $selected, clearExisting: false);
        $newRendered = is_array($render['pdf_rendered_pages'] ?? null) ? $render['pdf_rendered_pages'] : [];
        $mergedRendered = collect([...$renderedPages, ...$newRendered])
            ->filter(fn (mixed $page): bool => is_array($page) && (int) ($page['page'] ?? 0) > 0)
            ->unique(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->sortBy(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->values()
            ->all();

        $ocr = $this->ocrSparsePdfPages($pages, $mergedRendered);
        $mergedOcrPages = collect([
            ...(is_array($attachment['pdf_ocr_pages'] ?? null) ? $attachment['pdf_ocr_pages'] : []),
            ...$ocr['pages'],
        ])
            ->filter(fn (mixed $page): bool => is_array($page) && (int) ($page['page'] ?? 0) > 0)
            ->unique(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->sortBy(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->values()
            ->all();

        return [
            ...$attachment,
            'pdf_render_status' => $render['pdf_render_status'] === 'failed' ? ($attachment['pdf_render_status'] ?? 'partial') : 'rendered',
            'pdf_rendered_page_count' => count($mergedRendered),
            'pdf_rendered_pages' => $mergedRendered,
            'pdf_ocr_status' => $mergedOcrPages !== [] ? 'processed' : ($attachment['pdf_ocr_status'] ?? $ocr['status']),
            'pdf_ocr_pages' => $mergedOcrPages,
            'pdf_pages' => $this->enrichPdfPageVisuals($pages, $mergedRendered, $mergedOcrPages),
            'pdf_visual_understanding_status' => $mergedRendered !== [] ? 'ready' : ($attachment['pdf_visual_understanding_status'] ?? 'metadata_only'),
            'pdf_query_visualization' => [
                'status' => $newRendered === [] ? 'no_new_pages_rendered' : 'rendered',
                'query_hash' => hash('sha256', mb_substr($query, 0, 400)),
                'selected_pages' => $selected,
                'added_pages' => collect($newRendered)->pluck('page')->filter()->values()->all(),
                'rendered_page_count' => count($mergedRendered),
            ],
        ];
    }

    /**
     * @return array{excerpt:string,truncated:bool,metadata:array<string,mixed>}
     */
    private function extractContent(string $path, string $mime, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $metadata = [];
        if ($this->isPdf($mime, $extension)) {
            $pdf = $this->analyzePdf($path);
            $text = $pdf['text'];
            $metadata = $pdf['metadata'];
        } elseif ($this->isOfficeDocument($extension)) {
            $text = match ($extension) {
                'docx' => $this->readDocx($path),
                'xlsx' => $this->readXlsx($path),
                'pptx' => $this->readPptx($path),
                default => '',
            };
            $metadata = [
                'office_processing_status' => $text !== '' ? 'processed' : 'no_text',
                ...$this->renderOfficeDocument($path, $extension),
            ];
        } else {
            $text = match (true) {
                $this->isPlainTextLike($mime, $extension) => $this->readTextFile($path),
                default => '',
            };
        }

        $text = $this->normalizeText($text);
        $truncated = mb_strlen($text) > self::MAX_EXCERPT_CHARS;

        return [
            'excerpt' => mb_substr($text, 0, self::MAX_EXCERPT_CHARS),
            'truncated' => $truncated,
            'metadata' => $metadata,
        ];
    }

    private function isPlainTextLike(string $mime, string $extension): bool
    {
        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        return in_array($extension, [
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
        ], true);
    }

    private function isPdf(string $mime, string $extension): bool
    {
        return $extension === 'pdf' || $mime === 'application/pdf';
    }

    private function isOfficeDocument(string $extension): bool
    {
        return in_array($extension, ['docx', 'xlsx', 'pptx'], true);
    }

    private function readTextFile(string $path): string
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return '';
        }

        try {
            return (string) fread($handle, self::MAX_EXCERPT_CHARS * 4);
        } finally {
            fclose($handle);
        }
    }

    private function readDocx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        try {
            $parts = [];
            foreach ($this->zipEntriesMatching($zip, '/^word\/(?:document|header\d+|footer\d+|footnotes|endnotes|comments)\.xml$/') as $entry) {
                $xml = $zip->getFromName($entry);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $text = $this->textFromOoxml($xml);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode("\n\n", $parts);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{text:string,metadata:array<string,mixed>}
     */
    private function analyzePdf(string $path): array
    {
        $metadata = [
            'pdf_processing_status' => 'failed',
            'pdf_page_count' => null,
            'pdf_pages' => [],
            'pdf_chunks' => [],
            'pdf_chunk_count' => 0,
            'pdf_text_chars' => 0,
            'pdf_text_available' => false,
            'pdf_render_status' => 'unavailable',
            'pdf_rendered_page_count' => 0,
            'pdf_rendered_pages' => [],
            'pdf_ocr_status' => 'unavailable',
        ];

        if (! class_exists(PdfParser::class)) {
            return ['text' => '', 'metadata' => $metadata];
        }

        try {
            $document = (new PdfParser())->parseFile($path);
            $pages = $document->getPages();
        } catch (Throwable) {
            return ['text' => '', 'metadata' => $metadata];
        }

        $metadata['pdf_processing_status'] = 'processed';
        $metadata['pdf_page_count'] = count($pages);

        $imageCounts = $this->pdfImageCounts($path);
        $pageBlocks = [];
        $pageMetadata = [];
        $chunks = [];
        foreach (array_slice($pages, 0, self::MAX_PDF_STORED_PAGES) as $index => $page) {
            $pageNumber = $index + 1;
            try {
                $pageText = $this->normalizeText($page->getText());
            } catch (Throwable) {
                $pageText = '';
                $metadata['pdf_processing_status'] = 'partial';
            }

            $textChars = mb_strlen($pageText);
            $pageExcerpt = mb_substr($pageText, 0, self::MAX_PDF_PAGE_EXCERPT_CHARS);
            $tables = $this->detectTables($pageText);
            $pageMetadata[] = [
                'page' => $pageNumber,
                'text_excerpt' => $pageExcerpt,
                'text_chars' => $textChars,
                'text_available' => $pageText !== '',
                'text_truncated' => $textChars > self::MAX_PDF_PAGE_EXCERPT_CHARS,
                'classification' => $this->classifyPdfPage($pageText),
                'structure' => $this->pageStructure($pageText, $tables, (int) ($imageCounts[$pageNumber] ?? 0)),
                'table_count' => $tables['count'],
                'table_excerpt' => $tables['excerpt'],
                'table_markdown' => $tables['markdown'],
                'table_confidence' => $tables['confidence'],
                'table_column_count' => $tables['column_count'],
                'image_count' => (int) ($imageCounts[$pageNumber] ?? 0),
            ];

            if ($pageText !== '') {
                $pageBlocks[] = "[p. {$pageNumber}]\n{$pageText}";
                $metadata['pdf_text_chars'] += $textChars;
                foreach ($this->pdfPageChunks($pageText, $pageNumber) as $chunk) {
                    if (count($chunks) >= self::MAX_PDF_CHUNKS) {
                        break 2;
                    }

                    $chunks[] = $chunk;
                }
            }
        }

        if (count($pages) > self::MAX_PDF_STORED_PAGES) {
            $metadata['pdf_processing_status'] = 'partial';
            $metadata['pdf_pages_truncated'] = true;
        }

        $metadata['pdf_pages'] = $pageMetadata;
        $metadata['pdf_chunks'] = $chunks;
        $metadata['pdf_chunk_count'] = count($chunks);
        $metadata['pdf_text_available'] = $metadata['pdf_text_chars'] > 0;

        $visualPageLimit = min(count($pages), $this->pdfVisionPageLimit());
        $visualPageNumbers = $this->selectInitialPdfVisualPages($pageMetadata, count($pages), $visualPageLimit);
        $render = $this->renderPdfPages($path, $visualPageLimit, $visualPageNumbers);
        $metadata = [
            ...$metadata,
            ...$render,
            'pdf_visual_page_strategy' => $visualPageNumbers === [] ? 'none' : 'smart_select_v1',
            'pdf_visual_selected_pages' => $visualPageNumbers,
        ];

        $ocr = $this->ocrSparsePdfPages($metadata['pdf_pages'], $metadata['pdf_rendered_pages']);
        $metadata['pdf_ocr_status'] = $ocr['status'];
        if ($ocr['pages'] !== []) {
            $metadata['pdf_ocr_pages'] = $ocr['pages'];
            $pageBlocks = $this->mergeOcrPageBlocks($pageBlocks, $ocr['pages']);
        }

        $metadata['pdf_pages'] = $this->enrichPdfPageVisuals($metadata['pdf_pages'], $metadata['pdf_rendered_pages'], $ocr['pages']);
        $metadata['pdf_visual_understanding_status'] = $metadata['pdf_rendered_pages'] !== [] ? 'ready' : 'metadata_only';

        return [
            'text' => implode("\n\n", $pageBlocks),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pdfPageChunks(string $pageText, int $pageNumber): array
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

    private function classifyPdfPage(string $text): string
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
     * @param  array<int,int>  $pageNumbers
     * @return array{pdf_render_status:string,pdf_rendered_page_count:int,pdf_rendered_pages:array<int,array<string,mixed>>}
     */
    private function renderPdfPages(string $path, int $pageLimit, array $pageNumbers = [], bool $clearExisting = true): array
    {
        $result = [
            'pdf_render_status' => 'unavailable',
            'pdf_rendered_page_count' => 0,
            'pdf_rendered_pages' => [],
        ];

        if ($pageLimit <= 0) {
            return $result;
        }

        $pageNumbers = $this->normalizePageNumbers($pageNumbers, $pageLimit);
        if ($pageNumbers === []) {
            $pageNumbers = range(1, $pageLimit);
        }

        $binary = (new ExecutableFinder())->find('pdftoppm');
        if (! $binary) {
            return $result;
        }

        $renderDir = storage_path('app/ai/attachments/pdf-pages/'.pathinfo($path, PATHINFO_FILENAME));
        File::ensureDirectoryExists($renderDir);
        $prefix = $renderDir.'/page';
        if ($clearExisting) {
            foreach (glob($prefix.'-*.png') ?: [] as $stalePage) {
                if (is_string($stalePage)) {
                    File::delete($stalePage);
                }
            }
        }

        $renderSucceeded = true;
        foreach ($pageNumbers as $pageNumber) {
            $process = new Process([
                $binary,
                '-png',
                '-r',
                '144',
                '-f',
                (string) $pageNumber,
                '-l',
                (string) $pageNumber,
                $path,
                $prefix,
            ], base_path());
            $process->setTimeout(20);
            $process->run();
            if (! $process->isSuccessful()) {
                $renderSucceeded = false;
            }
        }

        $files = glob($prefix.'-*.png') ?: [];
        natsort($files);

        $rendered = [];
        foreach (array_values($files) as $file) {
            if (! is_string($file) || ! File::isFile($file)) {
                continue;
            }

            preg_match('/-(\d+)\.png$/', $file, $matches);
            $page = isset($matches[1]) ? (int) $matches[1] : count($rendered) + 1;
            $rendered[] = [
                'page' => $page,
                ...$this->preprocessRenderedPage($file),
                'mime_type' => 'image/png',
            ];
        }

        if ($rendered === []) {
            return [
                ...$result,
                'pdf_render_status' => $renderSucceeded ? 'empty' : 'failed',
            ];
        }

        return [
            'pdf_render_status' => count($rendered) >= count($pageNumbers) ? 'rendered' : 'partial',
            'pdf_rendered_page_count' => count($rendered),
            'pdf_rendered_pages' => $rendered,
        ];
    }

    /**
     * Seleciona paginas visuais com alto valor informacional:
     * abertura, paginas escaneadas/sparse, tabelas, imagens/graficos e amostras
     * distribuidas. Isso aumenta cobertura de PDFs longos sem renderizar tudo.
     *
     * @param  array<int,array<string,mixed>>  $pages
     * @return array<int,int>
     */
    private function selectInitialPdfVisualPages(array $pages, int $totalPages, int $limit): array
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
            foreach ($this->evenlySampledPages($totalPages, $sampleCount) as $page) {
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
    private function evenlySampledPages(int $totalPages, int $count): array
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
     * @param  array<int,int>  $pageNumbers
     * @return array<int,int>
     */
    private function normalizePageNumbers(array $pageNumbers, int $limit): array
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

    private function pdfVisionPageLimit(): int
    {
        return max(0, (int) config('atlas.attachments.pdf.vision_page_limit', self::DEFAULT_PDF_RENDERED_PAGES));
    }

    /**
     * @param  array<int,array<string,mixed>>  $pages
     * @return array<int,int>
     */
    private function selectQueryPdfVisualPages(array $pages, string $query, int $totalPages, int $limit): array
    {
        $explicitPages = $this->explicitPageReferences($query, $totalPages);
        $terms = $this->queryTerms($query);
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
    private function explicitPageReferences(string $query, int $totalPages): array
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
    private function queryTerms(string $query): array
    {
        $query = mb_strtolower($this->normalizeText($query));
        preg_match_all('/[\pL\pN]{4,}/u', $query, $matches);

        return collect($matches[0] ?? [])
            ->reject(fn (string $term): bool => in_array($term, ['sobre', 'para', 'como', 'qual', 'quais', 'esse', 'essa', 'documento', 'arquivo'], true))
            ->unique()
            ->take(24)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $pages
     * @param  array<int,array<string,mixed>>  $renderedPages
     * @return array{status:string,pages:array<int,array<string,mixed>>}
     */
    private function ocrSparsePdfPages(array $pages, array $renderedPages): array
    {
        $binary = (new ExecutableFinder())->find('tesseract');
        if (! $binary) {
            return ['status' => 'unavailable', 'pages' => []];
        }

        $renderedByPage = [];
        foreach ($renderedPages as $rendered) {
            if (is_numeric($rendered['page'] ?? null) && is_string($rendered['ocr_path'] ?? $rendered['path'] ?? null)) {
                $renderedByPage[(int) $rendered['page']] = (string) ($rendered['ocr_path'] ?? $rendered['path']);
            }
        }

        $language = $this->pdfOcrLanguages();
        $ocrPages = [];
        foreach ($pages as $page) {
            $pageNumber = is_numeric($page['page'] ?? null) ? (int) $page['page'] : null;
            $textChars = is_numeric($page['text_chars'] ?? null) ? (int) $page['text_chars'] : 0;
            if (! $pageNumber || $textChars >= self::PDF_OCR_TEXT_THRESHOLD_CHARS || ! isset($renderedByPage[$pageNumber])) {
                continue;
            }

            $process = new Process([$binary, $renderedByPage[$pageNumber], 'stdout', '-l', $language, '--psm', '6'], base_path());
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                continue;
            }

            $text = $this->normalizeText($process->getOutput());
            if ($text === '') {
                continue;
            }

            $ocrPages[] = [
                'page' => $pageNumber,
                'text_excerpt' => mb_substr($text, 0, self::MAX_PDF_PAGE_EXCERPT_CHARS),
                'text_chars' => mb_strlen($text),
                'text_truncated' => mb_strlen($text) > self::MAX_PDF_PAGE_EXCERPT_CHARS,
            ];
        }

        return [
            'status' => $ocrPages === [] ? 'skipped' : 'processed',
            'pages' => $ocrPages,
        ];
    }

    private function pdfOcrLanguages(): string
    {
        $language = config('atlas.attachments.pdf.ocr_languages', 'por+eng');
        if (! is_string($language) || trim($language) === '') {
            return 'por+eng';
        }

        $language = preg_replace('/[^A-Za-z0-9_+.-]+/', '', $language) ?: 'por+eng';

        return $language;
    }

    /**
     * @return array{path:string,original_path:string,ocr_path:string,bytes:int,sha256:string,ocr_preprocess_status:string,orientation_degrees:int|null}
     */
    private function preprocessRenderedPage(string $path): array
    {
        $base = [
            'path' => $path,
            'original_path' => $path,
            'ocr_path' => $path,
            'bytes' => File::size($path),
            'sha256' => hash_file('sha256', $path),
            'ocr_preprocess_status' => 'unavailable',
            'orientation_degrees' => null,
        ];

        $binary = (new ExecutableFinder())->find('magick') ?: (new ExecutableFinder())->find('convert');
        if (! $binary) {
            return $base;
        }

        $rotation = $this->detectImageRotation($path);
        $target = preg_replace('/\.png$/', '.ocr.png', $path) ?: ($path.'.ocr.png');
        $command = [$binary];
        if (basename($binary) === 'magick') {
            $command[] = $path;
        } else {
            $command[] = $path;
        }

        if ($rotation !== null && $rotation !== 0) {
            $command[] = '-rotate';
            $command[] = (string) $rotation;
        }

        array_push($command, '-auto-orient', '-colorspace', 'Gray', '-normalize', '-deskew', '40%', '-sharpen', '0x1', $target);

        $process = new Process($command, base_path());
        $process->setTimeout(20);
        $process->run();

        if (! $process->isSuccessful() || ! File::isFile($target) || File::size($target) <= 0) {
            File::delete($target);

            return [
                ...$base,
                'ocr_preprocess_status' => 'failed',
                'orientation_degrees' => $rotation,
            ];
        }

        return [
            'path' => $target,
            'original_path' => $path,
            'ocr_path' => $target,
            'bytes' => File::size($target),
            'sha256' => hash_file('sha256', $target),
            'ocr_preprocess_status' => 'processed',
            'orientation_degrees' => $rotation,
        ];
    }

    private function detectImageRotation(string $path): ?int
    {
        $binary = (new ExecutableFinder())->find('tesseract');
        if (! $binary) {
            return null;
        }

        $process = new Process([$binary, $path, 'stdout', '-l', 'osd', '--psm', '0'], base_path());
        $process->setTimeout(12);
        $process->run();

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        if (preg_match('/Rotate:\s*(\d+)/i', $output, $matches) !== 1) {
            return null;
        }

        $rotate = (int) $matches[1];

        return in_array($rotate, [0, 90, 180, 270], true) ? $rotate : null;
    }

    /**
     * @return array<int,int>
     */
    private function pdfImageCounts(string $path): array
    {
        $binary = (new ExecutableFinder())->find('pdfimages');
        if (! $binary) {
            return [];
        }

        $process = new Process([$binary, '-list', $path], base_path());
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        $counts = [];
        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+\d+\s+/', $line, $matches) === 1) {
                $page = (int) $matches[1];
                $counts[$page] = ($counts[$page] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array{count:int,excerpt:string,markdown:string,confidence:string,column_count:int}
     */
    private function detectTables(string $text): array
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

        $markdown = $this->tableRowsToMarkdown($rows);
        $columnCount = $this->tableColumnCount($rows);
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
    private function tableRowsToMarkdown(array $rows): string
    {
        $parsed = collect(array_slice($rows, 0, 12))
            ->map(fn (string $row): array => $this->splitTableRow($row))
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
    private function tableColumnCount(array $rows): int
    {
        return collect($rows)
            ->map(fn (string $row): int => count($this->splitTableRow($row)))
            ->max() ?: 0;
    }

    /**
     * @return array<int,string>
     */
    private function splitTableRow(string $row): array
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
     * @param  array{count:int,excerpt:string}  $tables
     * @return array<string,mixed>
     */
    private function pageStructure(string $text, array $tables, int $imageCount): array
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
    private function enrichPdfPageVisuals(array $pages, array $renderedPages, array $ocrPages): array
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
                $caption = $this->visualCaptionForPage($pageNumber, $textChars, $tableCount, $imageCount, is_array($ocr) ? (int) ($ocr['text_chars'] ?? 0) : 0);

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

    private function visualCaptionForPage(int $page, int $textChars, int $tableCount, int $imageCount, int $ocrChars): string
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

    /**
     * @return array{office_render_status:string,office_rendered_page_count:int,office_rendered_pages:array<int,array<string,mixed>>,office_pdf_path?:string}
     */
    private function renderOfficeDocument(string $path, string $extension): array
    {
        $result = [
            'office_render_status' => 'unavailable',
            'office_rendered_page_count' => 0,
            'office_rendered_pages' => [],
        ];

        $binary = (new ExecutableFinder())->find('soffice') ?: (new ExecutableFinder())->find('libreoffice');
        if (! $binary) {
            return $result;
        }

        $workDir = storage_path('app/ai/attachments/office-previews/'.pathinfo($path, PATHINFO_FILENAME).'-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($workDir);
        $process = new Process([
            $binary,
            '--headless',
            '--nologo',
            '--nofirststartwizard',
            '--convert-to',
            'pdf',
            '--outdir',
            $workDir,
            $path,
        ], base_path());
        $process->setTimeout(60);
        $process->run();

        $pdfs = glob($workDir.'/*.pdf') ?: [];
        if (! $process->isSuccessful() || $pdfs === []) {
            return [
                ...$result,
                'office_render_status' => 'failed',
            ];
        }

        $pdfPath = $pdfs[0];
        $rendered = $this->renderPdfPages($pdfPath, self::MAX_OFFICE_RENDERED_PAGES);
        $pages = $rendered['pdf_rendered_pages'];

        return [
            'office_render_status' => $pages === [] ? 'empty' : $rendered['pdf_render_status'],
            'office_rendered_page_count' => count($pages),
            'office_rendered_pages' => $pages,
            'office_pdf_path' => $pdfPath,
            'office_source_extension' => $extension,
        ];
    }

    /**
     * @param  array<int,string>  $pageBlocks
     * @param  array<int,array<string,mixed>>  $ocrPages
     * @return array<int,string>
     */
    private function mergeOcrPageBlocks(array $pageBlocks, array $ocrPages): array
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

    private function readPdf(string $path): string
    {
        try {
            return $this->analyzePdf($path)['text'];
        } catch (Throwable) {
            return '';
        }
    }

    private function readXlsx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        try {
            $sharedStrings = $this->xlsxSharedStrings($zip);
            $parts = [];

            foreach ($this->xlsxSheetEntries($zip) as $sheetName => $entry) {
                $xml = $zip->getFromName($entry);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $rows = $this->xlsxRows($xml, $sharedStrings);
                if ($rows === []) {
                    continue;
                }

                $parts[] = "Planilha: {$sheetName}\n".implode("\n", $rows);
            }

            return implode("\n\n", $parts);
        } finally {
            $zip->close();
        }
    }

    private function readPptx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        try {
            $parts = [];
            foreach ($this->zipEntriesMatching($zip, '/^ppt\/slides\/slide\d+\.xml$/') as $index => $entry) {
                $xml = $zip->getFromName($entry);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $text = $this->textNodesFromXml($xml);
                if ($text !== '') {
                    $parts[] = 'Slide '.($index + 1).":\n".$text;
                }
            }

            $notes = [];
            foreach ($this->zipEntriesMatching($zip, '/^ppt\/notesSlides\/notesSlide\d+\.xml$/') as $index => $entry) {
                $xml = $zip->getFromName($entry);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $text = $this->textNodesFromXml($xml);
                if ($text !== '') {
                    $notes[] = 'Notas '.($index + 1).":\n".$text;
                }
            }

            return implode("\n\n", [...$parts, ...$notes]);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int,string>
     */
    private function zipEntriesMatching(ZipArchive $zip, string $pattern): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && preg_match($pattern, $name) === 1) {
                $entries[] = $name;
            }
        }

        natsort($entries);

        return array_values($entries);
    }

    /**
     * @return array<int,string>
     */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $dom = $this->loadXml($xml);
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
     * @return array<string,string>
     */
    private function xlsxSheetEntries(ZipArchive $zip): array
    {
        $fallback = [];
        foreach ($this->zipEntriesMatching($zip, '/^xl\/worksheets\/sheet\d+\.xml$/') as $index => $entry) {
            $fallback['Sheet '.($index + 1)] = $entry;
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! is_string($workbook) || $workbook === '' || ! is_string($rels) || $rels === '') {
            return $fallback;
        }

        $workbookDom = $this->loadXml($workbook);
        $relsDom = $this->loadXml($rels);
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
            if (is_string($entry) && $zip->locateName($entry) !== false) {
                $sheets[$name] = $entry;
            }
        }

        return $sheets !== [] ? $sheets : $fallback;
    }

    /**
     * @param  array<int,string>  $sharedStrings
     * @return array<int,string>
     */
    private function xlsxRows(string $xml, array $sharedStrings): array
    {
        $dom = $this->loadXml($xml);
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

                $value = $this->xlsxCellValue($xpath, $cellNode, $sharedStrings);
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
     * @param  array<int,string>  $sharedStrings
     */
    private function xlsxCellValue(\DOMXPath $xpath, \DOMElement $cell, array $sharedStrings): string
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

    private function textFromOoxml(string $xml): string
    {
        $xml = preg_replace('/<\/(?:w|a):(?:p|tr)>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/(?:w|a):tc>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<(?:w|a):(?:br|tab)\b[^>]*\/>/', "\n", $xml) ?? $xml;

        return html_entity_decode(trim(strip_tags($xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function textNodesFromXml(string $xml): string
    {
        $dom = $this->loadXml($xml);
        if (! $dom) {
            return $this->textFromOoxml($xml);
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

    private function loadXml(string $xml): ?\DOMDocument
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

    private function normalizeText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        return $this->safeOriginalNameString($file->getClientOriginalName());
    }

    private function safeOriginalNameString(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'arquivo';
        }

        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'arquivo';

        return mb_substr($name, 0, 180);
    }
}
