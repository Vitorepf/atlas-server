<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use Illuminate\Console\Command;

class AtlasAemorCertifyCommand extends Command
{
    protected $signature = 'atlas:aemor:certify
        {--json : Emit JSON}
        {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AEMOR local runtime. No providers or benchmarks.';

    public function handle(AtlasAemorCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('AEMOR certify', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasAemorCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
