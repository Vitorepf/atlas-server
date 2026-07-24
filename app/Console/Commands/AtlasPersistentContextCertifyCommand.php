<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\PersistentContext\AtlasPersistentContextCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasPersistentContextCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:persistent-context:certify
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless status is passed}';

    protected $description = 'Certify Atlas Persistent Context Runtime local wiring. No providers, rivals or benchmarks.';

    public function handle(AtlasPersistentContextCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('Atlas Persistent Context Runtime', (string) ($payload['schema_version'] ?? 'unknown'));
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Checks', json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasPersistentContextCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
