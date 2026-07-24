<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAedpdsInspectionService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAedpdsInspectCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aedpds:inspect {--json : Print JSON}';

    protected $description = 'Shows AEDPDS docs, services, wiring, tests, and runtime status.';

    public function handle(AtlasAedpdsInspectionService $service): int
    {
        $payload = $service->inspect();
        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
