<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService;
use Illuminate\Console\Command;

class AtlasAaelCertifyCommand extends Command
{
    protected $signature = 'atlas:aael:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AAEL, the Atlas Autonomous Evolution Loop.';

    public function handle(AtlasAutonomousEvolutionCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
