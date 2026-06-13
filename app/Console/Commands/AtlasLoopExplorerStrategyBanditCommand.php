<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService;
use Illuminate\Console\Command;

/**
 * L6-3: measure and optionally write the explorer strategy bandit receipt.
 */
final class AtlasLoopExplorerStrategyBanditCommand extends Command
{
    protected $signature = 'atlas:loop:strategy-bandit
        {--hours= : Lookback window}
        {--write-receipt : Write the measurement receipt}
        {--strict : Exit non-zero unless a changed strategy distribution with token lift is proven}
        {--json : Machine-readable JSON output}';

    protected $description = 'Rank explorer scenario strategies by target type using UCB over historical certification-per-token outcomes.';

    public function handle(AtlasLoopExplorerStrategyBanditService $service): int
    {
        $payload = $service->measure(array_filter([
            'hours' => $this->intOption('hours'),
            'write_receipt' => (bool) $this->option('write-receipt'),
        ], static fn (mixed $value): bool => $value !== null));

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['completion_claim_allowed'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info('Atlas Loop explorer strategy bandit');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Recommendations', (string) count((array) ($payload['recommendations'] ?? [])));
        $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'artifacts.receipt_path', '-'));

        return $exit;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?? ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }
}
