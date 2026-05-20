<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\DevForgeRobustFlowCertificationService;
use Illuminate\Console\Command;

final class AtlasProgrammingDevForgeFlowCertifyCommand extends Command
{
    protected $signature = 'atlas:programming:dev-forge-flow-certify
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless status is passed}';

    protected $description = 'Certify Atlas Dev/Forge are wired to the strongest local programming flow. No providers, rivals or benchmarks.';

    public function handle(DevForgeRobustFlowCertificationService $certification): int
    {
        $payload = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Dev/Forge robust flow', (string) ($payload['schema_version'] ?? 'unknown'));
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Checks', json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->components->twoColumnDetail('Certification hash', (string) ($payload['certification_hash'] ?? 'missing'));

            foreach ((array) ($payload['checks'] ?? []) as $check) {
                if (! is_array($check)) {
                    continue;
                }

                $this->components->twoColumnDetail(
                    '- '.(string) ($check['id'] ?? 'check'),
                    (string) ($check['status'] ?? 'unknown'),
                );
            }
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== DevForgeRobustFlowCertificationService::STATUS_PASSED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
