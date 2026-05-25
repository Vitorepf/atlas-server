<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use Illuminate\Console\Command;

class AtlasFrontendProductProofCommand extends Command
{
    protected $signature = 'atlas:frontend:proof
        {action=catalog : catalog or build}
        {--output= : Output directory for build action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit Atlas Frontend product proof demo catalog.';

    public function handle(AtlasFrontendProductProofRuntimeService $proof): int
    {
        $payload = match ((string) $this->argument('action')) {
            'catalog' => $proof->catalog(),
            'build' => $proof->buildStaticBundle((string) ($this->option('output') ?: '')),
            default => [
                'schema_version' => AtlasFrontendProductProofRuntimeService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Product Proof: '.$payload['status']);
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
