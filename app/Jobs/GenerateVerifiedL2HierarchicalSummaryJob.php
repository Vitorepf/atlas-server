<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ai\Compaction\VerifiedL2HierarchicalSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class GenerateVerifiedL2HierarchicalSummaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $compactionId)
    {
        $this->onConnection((string) config('atlas.compaction.l2_hierarchical_summary_queue_connection', 'database-long'));
        $this->onQueue((string) config('atlas.compaction.l2_hierarchical_summary_queue', 'compaction-l2'));
    }

    public function handle(VerifiedL2HierarchicalSummaryService $service): void
    {
        $service->generateForCompaction($this->compactionId);
    }
}
