<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\LedgerProjectionWorker;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiLedgerProjectionCommand extends Command
{
    protected $signature = 'atlas:ai:ledger-project
        {--hours= : Only project ledger events from the last N hours}
        {--limit=500 : Maximum source events to inspect}
        {--dry-run : Preview projection work without writing}
        {--json : Print machine-readable JSON}';

    protected $description = 'Project Evidence Ledger events into operational Atlas AI read models.';

    public function handle(LedgerProjectionWorker $worker): int
    {
        $report = $worker->project(
            limit: $this->integerOption('limit', 500, 1, 5000),
            hours: $this->nullableIntegerOption('hours', 1, 8760),
            dryRun: (bool) $this->option('dry-run'),
        );
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'ledger_projection' => $report,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Ledger Projection</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Dry run', YesNo::format($report['dry_run'] ?? false));
        $this->components->twoColumnDetail('Events inspected', (string) ($report['event_count'] ?? 0));
        $this->components->twoColumnDetail('Projected', (string) ($report['projected_count'] ?? 0));
        $this->components->twoColumnDetail('Skipped', (string) ($report['skipped_count'] ?? 0));

        $this->table(
            ['projection', 'event', 'status'],
            collect((array) ($report['projection_results'] ?? []))
                ->take(50)
                ->map(fn (array $result): array => [
                    (string) ($result['projection_id'] ?? ''),
                    (string) ($result['event_type'] ?? ''),
                    (string) ($result['status'] ?? ''),
                ])
                ->all(),
        );

        return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    private function integerOption(string $name, int $default, int $min, int $max): int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function nullableIntegerOption(string $name, int $min, int $max): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }
}
