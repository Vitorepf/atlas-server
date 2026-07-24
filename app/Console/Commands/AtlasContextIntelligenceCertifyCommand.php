<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextIntelligenceCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context-intelligence:certify
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless status is passed}';

    protected $description = 'Certify Atlas Context Intelligence Engine local wiring. No providers, rivals or benchmarks.';

    public function handle(AtlasContextIntelligenceCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('Atlas Context Intelligence Engine', (string) ($payload['schema_version'] ?? 'unknown'));
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Checks', json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasContextIntelligenceCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
