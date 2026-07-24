<?php

namespace App\Console\Commands;

use App\Services\Ai\StrategicReality\AtlasStrategicRealityCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasStrategicRealityCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:strategic-reality:certify
        {--json : Emit JSON}
        {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify ASRE local runtime. No providers, no benchmarks, no external execution.';

    public function handle(AtlasStrategicRealityCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('ASRE certify', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasStrategicRealityCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
