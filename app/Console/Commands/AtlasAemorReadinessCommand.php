<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorReadinessCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:readiness {--json : Emit JSON}';

    protected $description = 'Readiness report for AEMOR.';

    public function handle(AtlasAemorCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('AEMOR readiness', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Checks', json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        return ($payload['status'] ?? null) === AtlasAemorCertificationService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
