<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyConfirmationQueue;
use Illuminate\Console\Command;

final class AtlasFinanceStrategyConfirmationNextCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-confirmation-next
        {--dry-run-ledger : Read the dry-run confirmation queue}
        {--claim : Mark the next pending confirmation as claimed}
        {--json : Emit machine-readable output}';

    protected $description = 'Show the next sequential confirmation campaign for a promoted trading-strategy champion.';

    public function handle(): int
    {
        $queue = StrategyConfirmationQueue::default((bool) $this->option('dry-run-ledger'));
        $item = (bool) $this->option('claim') ? $queue->claimNext() : $queue->nextPending();
        $payload = [
            'schema_version' => 'atlas.finance.strategy_confirmation_next.v1',
            'status' => $item === null ? 'empty' : 'ready',
            'item' => $item,
            'parallelism_policy' => 'run_only_when_no_strategy_search_loop_is_active',
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        if ($item === null) {
            $this->info('No pending champion confirmation campaign.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Campaign', (string) ($item['confirmation_campaign']['campaign_id'] ?? ''));
        $this->components->twoColumnDetail('Command', (string) ($item['confirmation_campaign']['command'] ?? ''));
        $this->line('Run it only after the active strategy-search loop has stopped.');

        return self::SUCCESS;
    }
}
