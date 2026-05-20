<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\AtlasTeosRuntimeSmokeService;
use Illuminate\Console\Command;

final class AtlasTeosRuntimeSmokeCommand extends Command
{
    protected $signature = 'atlas:teos:runtime-smoke
        {--goal= : Optional smoke goal text}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'Materialize local TEOS runtime smoke data and run final certification. No providers, rivals or benchmarks.';

    public function handle(AtlasTeosRuntimeSmokeService $service): int
    {
        $payload = $service->run([
            'goal' => $this->option('goal'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('<info>Atlas TEOS Runtime Smoke</info>');
            $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
            $this->line('Writes: <comment>'.(($payload['writes'] ?? false) ? 'yes' : 'no').'</comment>');
            $this->line('Smoke hash: <comment>'.($payload['smoke_hash'] ?? 'missing').'</comment>');
            $this->line('Final certification: <comment>'.data_get($payload, 'final_certification.status', 'n/a').'</comment>');
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasTeosRuntimeSmokeService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
