<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\TimeAwareWorldModelService;
use Illuminate\Console\Command;

final class AtlasLongHorizonWorldModelCommand extends Command
{
    protected $signature = 'atlas:long-horizon:world-model
        {--world-model-id= : Optional world model id or model_id}
        {--flow-id= : Optional flow filter}
        {--path-contains= : Optional path substring filter}
        {--limit=100 : Max nodes/edges per bucket}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'TEOS-I4 time-aware codebase world model read model.';

    public function handle(TimeAwareWorldModelService $service): int
    {
        $payload = $service->snapshot([
            'world_model_id' => $this->option('world-model-id'),
            'flow_id' => $this->option('flow-id'),
            'path_contains' => $this->option('path-contains'),
            'limit' => $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('<info>Atlas TEOS-I4 Time-Aware World Model</info>');
            $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
            $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->line('Snapshot hash: <comment>'.($payload['snapshot_hash'] ?? 'missing').'</comment>');
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== TimeAwareWorldModelService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
