<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryCandidate;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasMemoryGrowthReportCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:growth-report
        {--days=7 : Primary channel-health window}
        {--json : Print machine-readable JSON}';

    protected $description = 'Read-only weekly memory corpus growth and capture-channel health report.';

    public function handle(): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $payload = ['memory_growth' => $this->report($days)];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $growth = $payload['memory_growth'];
        $this->table(['metric', 'value'], [
            ['total', (string) $growth['total']],
            ['novas_'.$days.'d', (string) $growth['novas_7d']],
            ['novas_30d', (string) $growth['novas_30d']],
            ['auto_admitidas_'.$days.'d', (string) $growth['auto_admitidas_7d']],
            ['rejeitadas_por_check_'.$days.'d', (string) $growth['rejeitadas_por_check_7d']],
            ['capture_health', (string) $growth['capture_health']],
            ['alert', (string) ($growth['alert'] ?? '')],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function report(int $days): array
    {
        $since = now()->subDays($days);
        $since30 = now()->subDays(30);
        $entriesAvailable = DatabaseTableAvailability::has('atlas_memory_entries');
        $candidatesAvailable = DatabaseTableAvailability::has('atlas_memory_candidates');
        $newWindow = $entriesAvailable ? AtlasMemoryEntry::query()->where('created_at', '>=', $since)->count() : 0;
        $new30 = $entriesAvailable ? AtlasMemoryEntry::query()->where('created_at', '>=', $since30)->count() : 0;
        $total = $entriesAvailable ? AtlasMemoryEntry::query()->count() : 0;
        $admitted = $candidatesAvailable ? AtlasMemoryCandidate::query()->where('status', 'admitted')->where('created_at', '>=', $since)->count() : 0;
        $rejected = $candidatesAvailable ? AtlasMemoryCandidate::query()->where('status', 'rejected')->where('created_at', '>=', $since)->count() : 0;
        $captured = $candidatesAvailable ? AtlasMemoryCandidate::query()->where('created_at', '>=', $since)->count() : 0;

        return [
            'schema_version' => 'atlas.memory_growth_report.v1',
            'generated_at' => now()->toJSON(),
            'window_days' => $days,
            'total' => $total,
            'novas_7d' => $newWindow,
            'novas_30d' => $new30,
            'auto_admitidas_7d' => $admitted,
            'rejeitadas_por_check_7d' => $rejected,
            'capture_health' => $captured > 0 ? 'active' : 'stalled',
            'alert' => $newWindow === 0 ? 'capture_channel_stalled' : null,
            'read_only' => true,
        ];
    }
}
