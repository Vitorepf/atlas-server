<?php

namespace App\Jobs;

use App\Services\Ai\Mobile\MobilePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FlushBatchedMobilePushes implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function handle(MobilePushService $push): void
    {
        $push->flushBatched();
    }
}
