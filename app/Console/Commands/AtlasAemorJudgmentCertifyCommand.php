<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use Illuminate\Console\Command;

class AtlasAemorJudgmentCertifyCommand extends Command
{
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
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('AEMOR judgment certify', (string) ($payload['status'] ?? 'unknown'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasAemorCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
