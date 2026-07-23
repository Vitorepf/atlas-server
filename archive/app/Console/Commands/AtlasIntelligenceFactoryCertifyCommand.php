<?php

namespace App\Console\Commands;

use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryCertificationService;
use Illuminate\Console\Command;

class AtlasIntelligenceFactoryCertifyCommand extends Command
{
    protected $signature = 'atlas:intelligence-factory:certify
        {--json : Emit JSON}
        {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify Atlas Intelligence Factory OS local runtime. No providers or benchmarks.';

    public function handle(AtlasIntelligenceFactoryCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Intelligence Factory certify', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasIntelligenceFactoryCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
