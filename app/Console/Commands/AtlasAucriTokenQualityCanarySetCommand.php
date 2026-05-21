<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasAucriTokenQualityCanarySetService;
use Illuminate\Console\Command;

final class AtlasAucriTokenQualityCanarySetCommand extends Command
{
    protected $signature = 'atlas:aucri:token-quality-canaries
        {--json : Emit canonical JSON}';

    protected $description = 'Emit AUCRI token/quality regression canary set.';

    public function handle(AtlasAucriTokenQualityCanarySetService $service): int
    {
        $payload = $service->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas AUCRI Token Quality Canaries', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Cases', (string) data_get($payload, 'summary.total_cases', 0));
        $this->components->twoColumnDetail('Canary hash', (string) $payload['canary_set_hash']);

        return self::SUCCESS;
    }
}
