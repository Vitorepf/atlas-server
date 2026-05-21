<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasSoftwareTwinVerifiedEvolutionCertificationService;
use Illuminate\Console\Command;

final class AtlasSoftwareTwinVerifiedEvolutionCertifyCommand extends Command
{
    protected $signature = 'atlas:software-twin-verified-evolution:certify
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Certify ASTR and AVEOR, the Atlas Software Twin & Verified Evolution Runtime.';

    public function handle(AtlasSoftwareTwinVerifiedEvolutionCertificationService $service): int
    {
        $payload = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('ASTR/AVEOR certification', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', ($payload['summary']['passed'] ?? 0).'/'.($payload['summary']['total'] ?? 0));
            $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);
        }

        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
