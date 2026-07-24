<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxWindowOrchestratorService;
use App\Services\Ai\Cognition\AcosProgram\PromotionProtocol;
use DateTimeImmutable;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAcosWindowsCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:windows
        {--promotion-ledger= : Override PromotionProtocol flip ledger path}
        {--protocol= : Override protocol entries JSON path}
        {--registry= : Override measure-series registry JSON path}
        {--now= : Override current time (ISO-8601; tests/probes)}
        {--dead-after-days=3 : Days without associated series data before dead-window alert}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only ACOS window DAG with critical path, parallel windows, and dead-window watchdog.';

    public function handle(AcosMaxWindowOrchestratorService $service): int
    {
        $protocol = new PromotionProtocol($this->stringOption('promotion-ledger'));
        $payload = $service->report(
            $protocol,
            $this->jsonListOption('protocol'),
            $this->jsonListOption('registry') ?? (new AcosMaxMeasureSeriesRegistry)->entries(),
            $this->dateOption('now'),
            max(1, (int) $this->option('dead-after-days')),
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->line('ACOS windows: '.$payload['status']);
        $this->line('critical_path_days_remaining='.var_export(data_get($payload, 'critical_path.days_remaining'), true));
        foreach ((array) ($payload['watchdog']['alerts'] ?? []) as $alert) {
            $this->warn(sprintf(
                'dead-window %s (%s) silent_days=%s',
                (string) ($alert['flag_id'] ?? '?'),
                (string) ($alert['series'] ?? '?'),
                (string) ($alert['silent_days'] ?? '?'),
            ));
        }

        return self::SUCCESS;
    }


    /**
     * @return list<array<string,mixed>>|null
     */
    private function jsonListOption(string $name): ?array
    {
        $path = $this->stringOption($name);
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function dateOption(string $name): ?DateTimeImmutable
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
