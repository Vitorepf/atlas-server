<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendDesignDossierCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:design-dossier
        {action=inspect : inspect or template}
        {--workspace= : Local company/product repository path}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless dossier is ready}';

    protected $description = 'Inspect or write the company-owned local repo design dossier required by Atlas Frontend.';

    public function handle(AtlasFrontendDesignDossierService $dossier): int
    {
        $workspace = (string) ($this->option('workspace') ?: base_path());
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $dossier->inspect($workspace),
            'template' => $dossier->writeTemplate($workspace),
            default => [
                'schema_version' => AtlasFrontendDesignDossierService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Design Dossier: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
