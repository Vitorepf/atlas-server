<?php

namespace App\Console\Commands;

use App\Services\Ai\RealitySandbox\AtlasAutonomousRealitySandboxCertificationService;
use Illuminate\Console\Command;

class AtlasAarsCertifyCommand extends Command
{
    protected $signature = 'atlas:aars:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AARS, the Atlas Autonomous Reality Sandbox.';

    public function handle(AtlasAutonomousRealitySandboxCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
