<?php

namespace App\Console\Commands;

use App\Http\Resources\AiProviderCostRateResource;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AiTelemetryCostRatesCommand extends Command
{
    protected $signature = 'atlas:ai:telemetry:cost-rates
        {--sync-config : Sync provider/model rates from ATLAS_AI_COST_RATES_JSON}
        {--import= : Import provider/model rates from a JSON file}
        {--missing : List provider/model pairs that generated unknown cost in recent rollups}
        {--hours=168 : Observation window for --missing and --write-template}
        {--write-template= : Write a JSON template for missing provider/model rates}
        {--provider= : Provider key, for example claude_cli}
        {--model= : Model/runtime identifier}
        {--input-microusd= : Input price in micro-USD per 1K tokens}
        {--output-microusd= : Output price in micro-USD per 1K tokens}
        {--currency=USD : Currency code}
        {--effective-from= : Effective start datetime}
        {--effective-until= : Optional effective end datetime}
        {--json : Print machine-readable JSON}';

    protected $description = 'List or upsert Atlas AI provider cost rates used by telemetry efficiency scoring.';

    public function handle(AiProviderCostRateService $rates): int
    {
        if (! Schema::hasTable('ai_provider_cost_rates')) {
            $this->error('ai_provider_cost_rates table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $synced = null;
        $upserted = null;
        $imported = null;
        $missingRates = null;
        $templatePath = null;

        if ((bool) $this->option('sync-config')) {
            $synced = $rates->syncConfiguredRates();
        }

        if ($this->option('import')) {
            $rows = $rates->ratesFromJson(File::get($this->pathOption('import')));
            $imported = $rates->upsertMany($rows, 'cli_import');
        }

        if ($this->option('provider') || $this->option('model') || $this->option('input-microusd') || $this->option('output-microusd')) {
            $upserted = $rates->upsert([
                'provider' => $this->option('provider'),
                'model' => $this->option('model'),
                'input_microusd_per_1k' => $this->option('input-microusd'),
                'output_microusd_per_1k' => $this->option('output-microusd'),
                'currency' => $this->option('currency'),
                'effective_from' => $this->option('effective-from'),
                'effective_until' => $this->option('effective-until'),
                'metadata' => [
                    'source' => 'cli',
                ],
            ]);
        }

        if ((bool) $this->option('missing') || $this->option('write-template')) {
            $missingRates = $rates->missingRates(
                now()->subHours(max(1, (int) $this->option('hours'))),
                now(),
                200,
            );
        }

        if ($this->option('write-template')) {
            $templatePath = $this->pathOption('write-template');
            File::ensureDirectoryExists(dirname($templatePath));
            File::put($templatePath, json_encode($this->templatePayload($missingRates ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }

        $activeRates = $rates->queryActive()->limit(200)->get();
        $payload = [
            'ok' => true,
            'synced' => $synced,
            'imported' => $imported ? [
                'upserted' => count($imported['upserted']),
                'errors' => $imported['errors'],
            ] : null,
            'upserted' => $upserted ? (new AiProviderCostRateResource($upserted))->resolve() : null,
            'missing_rates' => $missingRates,
            'template_path' => $templatePath,
            'active_rates' => AiProviderCostRateResource::collection($activeRates)->resolve(),
        ];
        $exitCode = $imported && $imported['errors'] !== [] ? self::FAILURE : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        if ($synced !== null) {
            $this->info("Synced {$synced} configured cost rate(s).");
        }

        if ($upserted) {
            $this->info("Upserted {$upserted->provider} / {$upserted->model}.");
        }

        if ($imported) {
            $this->info('Imported '.count($imported['upserted']).' cost rate(s).');
            foreach ($imported['errors'] as $error) {
                $this->warn("Import row {$error['index']}: {$error['message']}");
            }
        }

        if ($templatePath) {
            $this->info("Wrote cost rate template to {$templatePath}.");
        }

        if (is_array($missingRates)) {
            if ($missingRates === []) {
                $this->info('No missing cost rates found in the selected window.');
            } else {
                $this->warn('Missing cost rates:');
                $this->table(
                    ['provider', 'model', 'unknown cost traces', 'latest'],
                    collect($missingRates)->map(fn (array $row): array => [
                        $row['provider'],
                        $row['model'],
                        $row['traces'],
                        $row['latest_computed_at'],
                    ])->all(),
                );
            }
        }

        if ($activeRates->isEmpty()) {
            $this->warn('No active AI cost rates configured.');

            return $exitCode;
        }

        $this->table(
            ['provider', 'model', 'input uUSD/1K', 'output uUSD/1K', 'effective from'],
            $activeRates->map(fn ($rate): array => [
                $rate->provider,
                $rate->model,
                $rate->input_microusd_per_1k,
                $rate->output_microusd_per_1k,
                $rate->effective_from?->toJSON(),
            ])->all(),
        );

        return $exitCode;
    }

    private function pathOption(string $option): string
    {
        $path = (string) $this->option($option);
        if ($path === '') {
            throw new \InvalidArgumentException("--{$option} requires a path.");
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * @param  array<int,array<string,mixed>>  $missingRates
     * @return array<string,mixed>
     */
    private function templatePayload(array $missingRates): array
    {
        return [
            'generated_at' => now()->toJSON(),
            'instructions' => 'Replace placeholder prices with current provider pricing before importing. Atlas does not hardcode model prices.',
            'rates' => collect($missingRates)
                ->map(fn (array $row): array => (array) ($row['rate_template'] ?? []))
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
