<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendDesignRuntimeCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:certify
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless certification status is ready}';

    protected $description = 'Certify the Atlas Frontend design runtime, gates, docs, commands and tests.';

    public function handle(AtlasFrontendDesignRuntimeService $runtime): int
    {
        $payload = $runtime->certify();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Certification: '.($payload['status'] ?? 'unknown'));
            $this->line('Checks: '.data_get($payload, 'summary.pass', 0).'/'.data_get($payload, 'summary.total', 0));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
