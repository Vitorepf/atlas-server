<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviderHealthService;
use Illuminate\Console\Command;

class AiHealthCommand extends Command
{
    protected $signature = 'atlas:ai:health';

    protected $description = 'Check local Atlas providers and persist operational health snapshots.';

    public function handle(AiProviderHealthService $health): int
    {
        $snapshots = $health->checkAll();

        $this->info(json_encode($snapshots->map(fn ($snapshot): array => [
            'provider' => $snapshot->provider,
            'status' => $snapshot->status,
            'pain' => $snapshot->operational_pain_score,
            'message' => $snapshot->message,
        ])->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
