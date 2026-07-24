<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAaelCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aael:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AAEL, the Atlas Autonomous Evolution Loop.';

    public function handle(AtlasAutonomousEvolutionCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('AAEL certification', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', ($payload['summary']['pass'] ?? 0).'/'.($payload['summary']['total'] ?? 0));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
