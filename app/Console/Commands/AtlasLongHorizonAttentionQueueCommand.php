<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\OperatorAttentionQueueService;
use Illuminate\Console\Command;

final class AtlasLongHorizonAttentionQueueCommand extends Command
{
    protected $signature = 'atlas:long-horizon:attention-queue
        {--scope-type= : Optional long-horizon scope_type}
        {--scope-id= : Optional scope_id}
        {--intake= : Optional Forge intake id or uuid}
        {--limit=50 : Max attention items}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero when queue status is blocked}';

    protected $description = 'TEOS-I3 operator attention queue read model for long-horizon work.';

    public function handle(OperatorAttentionQueueService $service): int
    {
        $payload = $service->build([
            'scope_type' => $this->option('scope-type'),
            'scope_id' => $this->option('scope-id'),
            'intake' => $this->option('intake'),
            'limit' => $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('<info>Atlas TEOS-I3 Operator Attention Queue</info>');
            $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
            $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->line('Queue hash: <comment>'.($payload['queue_hash'] ?? 'missing').'</comment>');
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) === OperatorAttentionQueueService::STATUS_BLOCKED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
