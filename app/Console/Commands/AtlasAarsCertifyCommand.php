<?php

namespace App\Console\Commands;

use App\Services\Ai\RealitySandbox\AtlasAutonomousRealitySandboxCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAarsCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aars:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AARS, the Atlas Autonomous Reality Sandbox.';

    public function handle(AtlasAutonomousRealitySandboxCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('AARS certification', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', ($payload['summary']['pass'] ?? 0).'/'.($payload['summary']['total'] ?? 0));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
