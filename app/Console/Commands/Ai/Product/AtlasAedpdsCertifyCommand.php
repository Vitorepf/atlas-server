<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAedpdsInspectionService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAedpdsCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aedpds:certify
        {--json : Print JSON}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Certifies AEDPDS doctrine, selector, gate, Dev, Forge, receipts, outcome, commands, and tests.';

    public function handle(AtlasAedpdsInspectionService $service): int
    {
        $payload = $service->certify();
        $this->line($this->encode($payload));

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
