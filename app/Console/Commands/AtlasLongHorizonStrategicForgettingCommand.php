<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\StrategicForgettingService;
use Illuminate\Console\Command;

final class AtlasLongHorizonStrategicForgettingCommand extends Command
{
    protected $signature = 'atlas:long-horizon:strategic-forgetting
        {--scope-type= : Optional AtlasMemoryEntry scope_type}
        {--scope-id= : Optional scope_id}
        {--limit=100 : Max memory entries to inspect}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'TEOS-I3 strategic forgetting read-only receipt over Atlas memory.';

    public function handle(StrategicForgettingService $service): int
    {
        $payload = $service->plan([
            'scope_type' => $this->option('scope-type'),
            'scope_id' => $this->option('scope-id'),
            'limit' => $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->renderHuman($payload);
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== StrategicForgettingService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->line(sprintf(
            '<info>Atlas TEOS-I3 Strategic Forgetting</info> (schema %s)',
            $payload['schema_version'] ?? 'unknown',
        ));
        $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
        $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->line('Receipt hash: <comment>'.($payload['receipt_hash'] ?? 'missing').'</comment>');
    }
}
