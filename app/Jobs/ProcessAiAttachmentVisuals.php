<?php

namespace App\Jobs;

use App\Models\AiTrace;
use App\Services\Ai\Attachments\AiAttachmentIndexService;
use App\Services\Ai\Cli\AtlasFileAttachmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAiAttachmentVisuals implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly string $traceId) {}

    public function handle(AiAttachmentIndexService $index, AtlasFileAttachmentService $files): void
    {
        $trace = AiTrace::query()->with(['job', 'jobs'])->find($this->traceId);
        if (! $trace) {
            return;
        }

        foreach ($trace->jobs as $job) {
            $payload = is_array($job->payload ?? null) ? $job->payload : [];
            $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
            $fileAttachments = is_array($attachments['files'] ?? null) ? $attachments['files'] : [];
            if ($fileAttachments === []) {
                continue;
            }

            $attachments['files'] = collect($fileAttachments)
                ->map(fn (mixed $attachment): mixed => is_array($attachment) ? $files->enhanceStoredAttachment($attachment) : $attachment)
                ->values()
                ->all();
            $payload['attachments'] = $attachments;
            $job->update(['payload' => $payload]);
        }

        $trace->refresh()->load(['job', 'jobs']);
        $index->indexTrace($trace);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            report($exception);
        }
    }
}
