<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAweosCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aweos:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AWEOS, the Atlas Autonomous Work Execution OS.';

    public function handle(AtlasAutonomousWorkExecutionCertificationService $certification): int
    {
        $payload = $certification->certify();
        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('AWEOS certification', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', $payload['summary']['pass'].'/'.$payload['summary']['total']);
            $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
