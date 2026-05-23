<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductDeliveryEvidenceReplayLabService;
use Illuminate\Console\Command;

class AtlasProductDeliveryReplayLabCommand extends Command
{
    protected $signature = 'atlas:product-delivery:replay-lab
        {--workspace=atlas-server : Workspace slug/path}
        {--receipt-limit=25 : Maximum runtime receipts to inspect}
        {--json : Emit JSON}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Replays canonical AEDPDS delivery scenarios and runtime receipts without providers or writes.';

    public function handle(AtlasProductDeliveryEvidenceReplayLabService $service): int
    {
        $payload = $service->replay([
            'workspace' => (string) $this->option('workspace'),
            'receipt_limit' => (int) $this->option('receipt-limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Evidence Replay Lab', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('scenarios', sprintf(
                'passed=%d/%d',
                (int) ($payload['passed_scenario_count'] ?? 0),
                (int) ($payload['scenario_count'] ?? 0),
            ));
            $this->components->twoColumnDetail('hash', (string) $payload['replay_hash']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
