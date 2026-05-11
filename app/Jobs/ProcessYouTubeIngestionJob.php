<?php

namespace App\Jobs;

use App\Services\Ai\YouTubeKnowledgeIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessYouTubeIngestionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public readonly string $url,
    ) {}

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('atlas-youtube-ingestion:'.sha1($this->url)))->expireAfter($this->timeout),
        ];
    }

    public function handle(YouTubeKnowledgeIngestionService $youtube): void
    {
        $youtube->ingestUrl($this->url, [
            'defer_audio_fallback' => false,
        ]);
    }
}
