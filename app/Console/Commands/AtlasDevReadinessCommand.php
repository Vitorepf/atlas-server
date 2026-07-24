<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevReadinessService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasDevReadinessCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:readiness
        {--json : Emit machine-readable JSON}
        {--strict : Treat warnings as blockers}
        {--provider-safe : Redact local paths and emit provider-safe readiness details}';

    protected $description = 'Check Atlas Dev Desktop runtime readiness.';

    public function handle(AtlasDevReadinessService $readiness): int
    {
        $payload = $readiness->inspect(
            strict: (bool) $this->option('strict'),
            providerSafe: (bool) $this->option('provider-safe'),
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $payload['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Atlas Dev readiness: '.$payload['status']);
        foreach ($payload['checks'] as $check) {
            $line = sprintf('[%s] %s — %s', $check['status'], $check['id'], $check['message']);
            match ($check['status']) {
                'failed' => $this->error($line),
                'warning' => $this->warn($line),
                default => $this->line($line),
            };
        }

        return $payload['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
