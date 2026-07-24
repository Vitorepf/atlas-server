<?php

namespace App\Console\Commands;

use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasRuntimeEfficiencyGovernorCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:runtime-efficiency:certify
        {--json : Emit JSON}
        {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AREG local runtime. No providers, no benchmarks, no external execution.';

    public function handle(AtlasRuntimeEfficiencyGovernorCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
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
