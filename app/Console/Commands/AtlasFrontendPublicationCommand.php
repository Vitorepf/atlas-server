<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use Illuminate\Console\Command;

class AtlasFrontendPublicationCommand extends Command
{
    protected $signature = 'atlas:frontend:publish
        {action=verify : verify or receipt-template}
        {--bundle= : Static product proof bundle directory for verify action}
        {--receipt= : Optional public publication receipt JSON}
        {--output= : Output directory for receipt-template action}
        {--strict : Fail verify unless public distribution is verified}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Verify Atlas Frontend product proof publication readiness.';

    public function handle(AtlasFrontendPublicationVerifierService $verifier): int
    {
        $payload = match ((string) $this->argument('action')) {
            'verify' => $verifier->verify((string) ($this->option('bundle') ?: ''), (string) ($this->option('receipt') ?: '')),
            'receipt-template' => $verifier->writeReceiptTemplate(
                (string) ($this->option('output') ?: storage_path('app/atlas/frontend-publication')),
                (string) ($this->option('bundle') ?: ''),
            ),
            default => [
                'schema_version' => AtlasFrontendPublicationVerifierService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Publication: '.$payload['status']);
        }

        if ((bool) $this->option('strict') && ((string) $this->argument('action')) === 'verify') {
            return ($payload['status'] ?? null) === 'public_verified' ? self::SUCCESS : self::FAILURE;
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
