<?php

namespace App\Console\Commands;

use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorCertificationService;
use Illuminate\Console\Command;

class AtlasRuntimeEfficiencyGovernorCertifyCommand extends Command
{
    protected $signature = 'atlas:runtime-efficiency:certify
        {--json : Emit JSON}
        {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AREG local runtime. No providers, no benchmarks, no external execution.';

    public function handle(AtlasRuntimeEfficiencyGovernorCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('AREG certify', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasRuntimeEfficiencyGovernorCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
