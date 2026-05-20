<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use Illuminate\Console\Command;

final class AtlasTeosFinalCertifyCommand extends Command
{
    protected $signature = 'atlas:teos:final-certify
        {--world-model-id= : Optional world model id/model_id}
        {--intake= : Optional Forge intake id/uuid}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'TEOS-I5 final local release certification. Does not run benchmarks or rivals.';

    public function handle(AtlasTeosFinalCertificationService $service): int
    {
        $payload = $service->certify([
            'world_model_id' => $this->option('world-model-id'),
            'intake' => $this->option('intake'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('<info>Atlas TEOS Final Certification</info>');
            $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
            $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->line('Certification hash: <comment>'.($payload['certification_hash'] ?? 'missing').'</comment>');
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasTeosFinalCertificationService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
