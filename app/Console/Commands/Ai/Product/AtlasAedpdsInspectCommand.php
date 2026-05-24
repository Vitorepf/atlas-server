<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAedpdsInspectionService;
use Illuminate\Console\Command;

class AtlasAedpdsInspectCommand extends Command
{
    protected $signature = 'atlas:aedpds:inspect {--json : Print JSON}';

    protected $description = 'Shows AEDPDS docs, services, wiring, tests, and runtime status.';

    public function handle(AtlasAedpdsInspectionService $service): int
    {
        $payload = $service->inspect();
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
