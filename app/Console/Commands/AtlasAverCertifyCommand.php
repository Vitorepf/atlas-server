<?php

namespace App\Console\Commands;

use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAverCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aver:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AVER, the Atlas Verified Execution Runtime.';

    public function handle(AtlasVerifiedExecutionCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('AVER certification', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', ($payload['summary']['pass'] ?? 0).'/'.($payload['summary']['total'] ?? 0));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
