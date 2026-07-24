<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviderHealthService;
use App\Services\Ai\Cli\AtlasCliProviderStrategyService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasCliProvidersCommand extends Command
{
    protected $signature = 'atlas:cli:providers
        {--mode=direct : direct, plan, review, dev, debug or research}
        {--critical : Prefer council/dual-review when available}
        {--refresh : Run provider health checks before recommending}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas CLI provider health and recommendation strategy.';

    public function handle(AtlasCliProviderStrategyService $strategy, AiProviderHealthService $health): int
    {
        if ((bool) $this->option('refresh')) {
            $health->checkAll();
        }

        $payload = $strategy->recommend((string) $this->option('mode'), (bool) $this->option('critical'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_merge(['ok' => true], $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Strategy</>', (string) $payload['recommended_provider']);
        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Critical', YesNo::format((bool) $payload['critical']));
        $this->components->twoColumnDetail('Fallback', (string) ($payload['fallback_provider'] ?: '-'));
        $this->line((string) $payload['reason']);

        $providers = (array) ($payload['providers'] ?? []);
        if ($providers !== []) {
            $this->newLine();
            $this->table(
                ['provider', 'status', 'pain', 'p50', 'checked'],
                collect($providers)->map(fn (array $provider): array => [
                    $provider['provider'] ?? '-',
                    $provider['status'] ?? '-',
                    $provider['pain'] ?? '-',
                    $provider['p50_latency_ms'] ?? '-',
                    $provider['checked_at'] ?? '-',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
