<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use Illuminate\Console\Command;

class AtlasFrontendEvidencePackCommand extends Command
{
    protected $signature = 'atlas:frontend:evidence
        {action=verify : verify or template}
        {--manifest= : Evidence pack manifest path for verify action}
        {--root= : Evidence pack root directory for verify action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Verify Atlas Frontend evidence packs or write governed templates.';

    public function handle(AtlasFrontendEvidencePackVerifierService $verifier): int
    {
        $payload = match ((string) $this->argument('action')) {
            'verify' => $verifier->verify((string) ($this->option('manifest') ?: ''), (string) ($this->option('root') ?: '')),
            'template' => $verifier->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-evidence-pack'))),
            default => [
                'schema_version' => AtlasFrontendEvidencePackVerifierService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Evidence Pack: '.$payload['status']);
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
