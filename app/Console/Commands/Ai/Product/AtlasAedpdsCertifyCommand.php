<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAedpdsInspectionService;
use Illuminate\Console\Command;

class AtlasAedpdsCertifyCommand extends Command
{
    protected $signature = 'atlas:aedpds:certify
        {--json : Print JSON}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Certifies AEDPDS doctrine, selector, gate, Dev, Forge, receipts, outcome, commands, and tests.';

    public function handle(AtlasAedpdsInspectionService $service): int
    {
        $payload = $service->certify();
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
