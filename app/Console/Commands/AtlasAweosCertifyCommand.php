<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionCertificationService;
use Illuminate\Console\Command;

class AtlasAweosCertifyCommand extends Command
{
    protected $signature = 'atlas:aweos:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AWEOS, the Atlas Autonomous Work Execution OS.';

    public function handle(AtlasAutonomousWorkExecutionCertificationService $certification): int
    {
        $payload = $certification->certify();
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
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
