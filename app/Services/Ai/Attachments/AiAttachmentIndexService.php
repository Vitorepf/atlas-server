<?php

namespace App\Services\Ai\Attachments;

use App\Models\AiAttachmentIndexEntry;
use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiAttachmentIndexService
{
    public function __construct(private readonly EmbeddingService $embeddings) {}

    public function indexTrace(AiTrace $trace): int
    {
        if (! DatabaseTableAvailability::has('ai_attachment_index_entries')) {
            return 0;
        }

        $trace->loadMissing(['job', 'jobs']);
        $jobs = $trace->jobs?->all() ?? [];
        if ($trace->job) {
            array_unshift($jobs, $trace->job);
        }

        $count = 0;
        foreach ($jobs as $job) {
            if ($job instanceof AiJob) {
                $count += $this->indexJob($trace, $job);
            }
        }

        return $count;
    }

    /**
     * @return Collection<int,AiAttachmentIndexEntry>
     */
    public function search(string $query, ?string $threadId = null, int $limit = 8): Collection
    {
        if (! DatabaseTableAvailability::has('ai_attachment_index_entries')) {
            return collect();
        }

        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        if (DB::getDriverName() === 'pgsql') {
            try {
                $vector = $this->embeddings->vectorLiteral($this->embeddings->embedText($query));
                $builder = AiAttachmentIndexEntry::query()
                    ->select('ai_attachment_index_entries.*')
                    ->selectRaw('(1 - (embedding <=> ?::vector)) AS score', [$vector])
                    ->whereNotNull('embedding');
                if ($threadId) {
                    $builder->where('thread_id', $threadId);
                }

                $rows = $builder
                    ->orderByRaw('embedding <=> ?::vector', [$vector])
                    ->limit($limit)
                    ->get();

                if ($rows->isNotEmpty()) {
                    return $rows;
                }
            } catch (\Throwable) {
                // lexical fallback below
            }
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
        $builder = AiAttachmentIndexEntry::query()
            ->where(function ($builder) use ($like): void {
                $builder
                    ->where('source_name', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('visual_caption', 'like', $like);
            })
            ->orderByDesc('indexed_at')
            ->limit($limit);
        if ($threadId) {
            $builder->where('thread_id', $threadId);
        }

        return $builder->get();
    }

    private function indexJob(AiTrace $trace, AiJob $job): int
    {
        $payload = is_array($job->payload ?? null) ? $job->payload : [];
        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        $entries = [
            ...$this->imageEntries($trace, $job, (array) ($attachments['images'] ?? [])),
            ...$this->fileEntries($trace, $job, (array) ($attachments['files'] ?? [])),
        ];

        foreach ($entries as $entry) {
            $this->upsertEntry($entry);
        }

        return count($entries);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function imageEntries(AiTrace $trace, AiJob $job, array $images): array
    {
        $entries = [];
        foreach (array_values($images) as $index => $image) {
            if (! is_array($image)) {
                continue;
            }

            $id = $this->attachmentId($image, 'image', $index);
            $caption = 'Imagem enviada ao Atlas para analise visual.';
            $entries[] = $this->entry($trace, $job, $image, $id, 'image', 'image', $index + 1, $caption, $caption, [
                'source' => $image['source'] ?? null,
            ]);
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fileEntries(AiTrace $trace, AiJob $job, array $files): array
    {
        $entries = [];
        foreach (array_values($files) as $index => $file) {
            if (! is_array($file)) {
                continue;
            }

            $id = $this->attachmentId($file, 'file', $index);
            $name = is_string($file['original_name'] ?? null) ? $file['original_name'] : 'arquivo';
            $pages = is_array($file['pdf_pages'] ?? null) ? $file['pdf_pages'] : [];
            if ($pages !== []) {
                foreach ($pages as $page) {
                    if (! is_array($page)) {
                        continue;
                    }

                    $pageNumber = is_numeric($page['page'] ?? null) ? (int) $page['page'] : null;
                    $excerpt = trim((string) ($page['text_excerpt'] ?? ''));
                    $caption = trim((string) ($page['visual_caption'] ?? ''));
                    $table = trim((string) ($page['table_excerpt'] ?? ''));
                    $tableMarkdown = trim((string) ($page['table_markdown'] ?? ''));
                    $body = trim(implode("\n", array_filter([$caption, $excerpt, $table, $tableMarkdown])));
                    if ($body === '') {
                        continue;
                    }

                    $entries[] = $this->entry($trace, $job, $file, $id, 'file', 'pdf_page', $pageNumber, "{$name} p. {$pageNumber}", $body, [
                        'page' => $pageNumber,
                        'classification' => $page['classification'] ?? null,
                        'image_count' => $page['image_count'] ?? null,
                        'table_count' => $page['table_count'] ?? null,
                        'table_confidence' => $page['table_confidence'] ?? null,
                        'table_column_count' => $page['table_column_count'] ?? null,
                        'visual_available' => $page['visual_available'] ?? null,
                        'visual_caption' => $caption !== '' ? $caption : null,
                        'vision_fallback_recommended' => $page['vision_fallback_recommended'] ?? null,
                    ]);
                }
                continue;
            }

            $excerpt = trim((string) ($file['text_excerpt'] ?? ''));
            if ($excerpt === '') {
                continue;
            }

            $officeUnits = $this->officeUnits($name, $excerpt);
            if ($officeUnits !== []) {
                foreach ($officeUnits as $unit) {
                    $entries[] = $this->entry($trace, $job, $file, $id, 'file', $unit['type'], $unit['number'], $unit['title'], $unit['excerpt'], [
                        'text_truncated' => (bool) ($file['text_truncated'] ?? false),
                        'office_render_status' => $file['office_render_status'] ?? null,
                    ]);
                }

                continue;
            }

            $entries[] = $this->entry($trace, $job, $file, $id, 'file', 'document', 1, $name, $excerpt, [
                'text_truncated' => (bool) ($file['text_truncated'] ?? false),
                'office_render_status' => $file['office_render_status'] ?? null,
            ]);
        }

        return $entries;
    }

    /**
     * @return array<int,array{type:string,number:int|null,title:string,excerpt:string}>
     */
    private function officeUnits(string $name, string $excerpt): array
    {
        $lower = mb_strtolower($name);
        if (str_ends_with($lower, '.xlsx')) {
            return $this->splitLabeledUnits($excerpt, '/^Planilha:\s*(.+)$/m', 'sheet', $name);
        }

        if (str_ends_with($lower, '.pptx')) {
            return $this->splitLabeledUnits($excerpt, '/^Slide\s+(\d+):\s*$/m', 'slide', $name);
        }

        return [];
    }

    /**
     * @return array<int,array{type:string,number:int|null,title:string,excerpt:string}>
     */
    private function splitLabeledUnits(string $text, string $pattern, string $type, string $sourceName): array
    {
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) {
            return [];
        }

        $units = [];
        $count = count($matches[0]);
        for ($index = 0; $index < $count; $index++) {
            $start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($text);
            $label = trim((string) ($matches[1][$index][0] ?? ($index + 1)));
            $number = is_numeric($label) ? (int) $label : $index + 1;
            $body = trim(substr($text, $start, max(0, $end - $start)));
            if ($body === '') {
                continue;
            }

            $units[] = [
                'type' => $type,
                'number' => $number,
                'title' => "{$sourceName} {$type} {$label}",
                'excerpt' => mb_substr($body, 0, 4000),
            ];
        }

        return $units;
    }

    /**
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    private function entry(AiTrace $trace, AiJob $job, array $attachment, string $id, string $kind, string $unitType, ?int $unitNumber, string $title, string $excerpt, array $metadata): array
    {
        $sourceName = is_string($attachment['original_name'] ?? null)
            ? $attachment['original_name']
            : (is_string($attachment['original_path'] ?? null) ? basename($attachment['original_path']) : null);
        $mime = is_string($attachment['mime_type'] ?? null) ? $attachment['mime_type'] : null;

        return [
            'trace_id' => $trace->id,
            'ai_job_id' => $job->id,
            'thread_id' => $trace->thread_id,
            'attachment_id' => $id,
            'attachment_kind' => $kind,
            'source_name' => $sourceName,
            'mime_type' => $mime,
            'unit_type' => $unitType,
            'unit_number' => $unitNumber,
            'title' => mb_substr($title, 0, 240),
            'excerpt' => mb_substr($excerpt, 0, 4000),
            'visual_caption' => $metadata['visual_caption'] ?? null,
            'metadata' => $metadata,
            'content_hash' => hash('sha256', implode('|', [$job->id, $id, $unitType, (string) $unitNumber, $excerpt])),
            'indexed_at' => now(),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function upsertEntry(array $entry): void
    {
        $embedding = null;
        $embeddingInfo = null;
        try {
            $vector = $this->embeddings->embedText(($entry['title'] ?? '')."\n".($entry['excerpt'] ?? ''));
            $embeddingInfo = $this->embeddings->lastInfo();
            $embedding = DB::getDriverName() === 'pgsql'
                ? $this->embeddings->vectorLiteral($vector)
                : json_encode($vector);
        } catch (\Throwable) {
            $embeddingInfo = ['provider' => 'unavailable'];
        }

        $entry['metadata'] = [
            ...((array) ($entry['metadata'] ?? [])),
            'embedding' => $embeddingInfo,
        ];

        $existing = AiAttachmentIndexEntry::query()
            ->where('content_hash', $entry['content_hash'])
            ->first();

        if ($existing) {
            $existing->fill($entry);
            $existing->save();
        } else {
            $existing = AiAttachmentIndexEntry::query()->create($entry);
        }

        if ($embedding !== null) {
            if (DB::getDriverName() === 'pgsql') {
                DB::update('UPDATE ai_attachment_index_entries SET embedding = ?::vector WHERE id = ?', [$embedding, $existing->id]);
            } else {
                $existing->update(['embedding' => $embedding]);
            }
        }
    }

    private function attachmentId(array $attachment, string $kind, int $index): string
    {
        return is_string($attachment['sha256'] ?? null) && $attachment['sha256'] !== ''
            ? $attachment['sha256']
            : $kind.'-'.($index + 1);
    }
}
