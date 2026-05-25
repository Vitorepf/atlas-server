<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationAttestationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use Illuminate\Console\Command;

class AtlasFrontendPublicationCommand extends Command
{
    protected $signature = 'atlas:frontend:publish
        {action=verify : verify, attest or receipt-template}
        {--bundle= : Static product proof bundle directory for verify action}
        {--receipt= : Optional public publication receipt JSON}
        {--output= : Output directory for receipt-template action}
        {--strict : Fail verify/attest unless public distribution is verified}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Verify Atlas Frontend product proof publication readiness.';

    public function handle(AtlasFrontendPublicationVerifierService $verifier, AtlasFrontendPublicationAttestationService $attestation): int
    {
        $payload = match ((string) $this->argument('action')) {
            'verify' => $verifier->verify((string) ($this->option('bundle') ?: ''), (string) ($this->option('receipt') ?: '')),
            'attest' => $attestation->attest($verifier->verify(
                (string) ($this->option('bundle') ?: ''),
                (string) ($this->option('receipt') ?: ''),
            ), [
                'rerun_action' => 'rerun_atlas_frontend_publish_attest',
            ]),
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

        if ((bool) $this->option('strict') && in_array((string) $this->argument('action'), ['verify', 'attest'], true)) {
            return (bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed') ? self::SUCCESS : self::FAILURE;
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
