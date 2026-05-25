<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use Illuminate\Console\Command;

class AtlasFrontendDeliveryHandoffCommand extends Command
{
    protected $signature = 'atlas:frontend:handoff
        {action=compile : compile or template}
        {--run-certification= : Run certification JSON report}
        {--evidence-manifest= : Evidence pack manifest JSON}
        {--publication-report= : Optional publication verifier JSON report}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless handoff is ready}';

    protected $description = 'Compile an enterprise Atlas Frontend delivery handoff from certified evidence.';

    public function handle(AtlasFrontendDeliveryHandoffService $handoff): int
    {
        $payload = match ((string) $this->argument('action')) {
            'compile' => $handoff->compile(
                (string) ($this->option('run-certification') ?: ''),
                (string) ($this->option('evidence-manifest') ?: ''),
                (string) ($this->option('publication-report') ?: ''),
            ),
            'template' => $handoff->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-handoff'))),
            default => [
                'schema_version' => AtlasFrontendDeliveryHandoffService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Delivery Handoff: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
