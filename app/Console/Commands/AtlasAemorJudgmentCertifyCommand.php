<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorJudgmentCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:judgment-certify
        {--json : Emit JSON}
        {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AEMOR Judgment & Learning Guard.';

    public function handle(AtlasAemorCertificationService $certification): int
    {
        $payload = $certification->certify();
        $payload['schema_version'] = 'atlas.aemor.judgment_certification.v1';
        $payload['scope']['covers'] = 'AEMOR Judgment & Learning Guard smoke, anti-false-learning gate and policy safety.';

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('AEMOR judgment certify', (string) ($payload['status'] ?? 'unknown'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasAemorCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
