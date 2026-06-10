<?php

namespace App\Console\Commands;

use App\Http\Resources\AiProviderCostRateResource;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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
        {--pricing-confidence= : Pricing confidence: official, research_preview_estimate, operator_estimate}
        {--pricing-source= : Human-readable pricing source}
        {--pricing-source-url= : Pricing source URL}
        {--pricing-note= : Short operator note about the rate}
        {--json : Print machine-readable JSON}';

    protected $description = 'List or upsert Atlas provider cost rates used by telemetry efficiency scoring.';

    public function handle(AiProviderCostRateService $rates): int
    {
        if (! DatabaseTableAvailability::has('ai_provider_cost_rates')) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'error' => [
                        'message' => 'ai_provider_cost_rates table is missing. Run php artisan migrate.',
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->error('ai_provider_cost_rates table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $synced = null;
        $upserted = null;
        $imported = null;
        $missingRates = null;
        $templatePath = null;

        try {
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
                        ...$this->pricingMetadata(),
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
                JsonFileStore::writeLine($templatePath, $this->templatePayload($missingRates ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable $exception) {
            return $this->renderError($exception);
        }

        $activeRates = $rates->queryActive()->limit(200)->get();
        $exitCode = $imported && $imported['errors'] !== [] ? self::FAILURE : self::SUCCESS;
        $payload = [
            'ok' => $exitCode === self::SUCCESS,
            'synced' => $synced,
            'imported' => $imported ? [
                'upserted' => count($imported['upserted']),
                'errors' => $imported['errors'],
            ] : null,
            'upserted' => $upserted ? (new AiProviderCostRateResource($upserted))->resolve() : null,
            'missing_rates' => $missingRates,
            'operator_action_plan' => is_array($missingRates) ? $this->operatorActionPlan($missingRates) : null,
            'template_path' => $templatePath,
            'active_rates' => AiProviderCostRateResource::collection($activeRates)->resolve(),
        ];

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

                $plan = $this->operatorActionPlan($missingRates);
                $commands = (array) ($plan['commands'] ?? []);
                if ($commands !== []) {
                    $this->newLine();
                    $this->warn('Next operator commands:');
                    foreach ($commands as $command) {
                        $this->line('- '.$command);
                    }
                    $this->newLine();
                    $this->line('Use only current provider pricing. Do not infer, synthesize, or auto-fill these values.');
                }
            }
        }

        if ($activeRates->isEmpty()) {
            $this->warn('No active AI cost rates configured.');

            return $exitCode;
        }

        $this->table(
            ['provider', 'model', 'input uUSD/1K', 'output uUSD/1K', 'currency', 'effective from'],
            $activeRates->map(fn ($rate): array => [
                $rate->provider,
                $rate->model,
                $rate->input_microusd_per_1k,
                $rate->output_microusd_per_1k,
                $rate->currency,
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
     * @return array<string,string>
     */
    private function pricingMetadata(): array
    {
        $metadata = [];

        $confidence = trim((string) $this->option('pricing-confidence'));
        if ($confidence !== '') {
            $allowed = ['official', 'research_preview_estimate', 'operator_estimate'];
            if (! in_array($confidence, $allowed, true)) {
                throw new \InvalidArgumentException('pricing-confidence must be one of: '.implode(', ', $allowed).'.');
            }

            $metadata['pricing_confidence'] = $confidence;
        }

        foreach ([
            'pricing-source' => 'pricing_source',
            'pricing-source-url' => 'pricing_source_url',
            'pricing-note' => 'pricing_note',
        ] as $option => $key) {
            $value = trim((string) $this->option($option));
            if ($value !== '') {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function renderError(\Throwable $exception): int
    {
        $message = $exception instanceof \InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to process cost rates: '.$exception->getMessage();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ok' => false,
                'error' => [
                    'message' => $message,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error('Invalid cost rate input: '.$message);

        return self::FAILURE;
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

    /**
     * @param  array<int,array<string,mixed>>  $missingRates
     * @return array<string,mixed>
     */
    private function operatorActionPlan(array $missingRates): array
    {
        $importable = collect($missingRates)
            ->filter(fn (array $row): bool => (bool) ($row['can_import_rate'] ?? false))
            ->values();

        return [
            'schema_version' => 'atlas.telemetry.cost_rates.operator_action_plan.v1',
            'status' => $importable->isEmpty() ? 'clear' : 'operator_rate_input_required',
            'operator_required' => ! $importable->isEmpty(),
            'missing_rate_count' => $importable->count(),
            'rules' => [
                'operator_supplied_rates_required' => true,
                'agent_must_not_infer_prices' => true,
                'external_price_lookup_not_performed' => true,
                'synthetic_rates_allowed' => false,
            ],
            'commands' => $importable
                ->map(fn (array $row): string => $this->configureCommand($row))
                ->all(),
            'items' => $importable
                ->map(fn (array $row): array => [
                    'provider' => $row['provider'] ?? null,
                    'model' => $row['model'] ?? null,
                    'reason' => $row['reason'] ?? null,
                    'traces' => $row['traces'] ?? null,
                    'latest_computed_at' => $row['latest_computed_at'] ?? null,
                    'configure_command' => $this->configureCommand($row),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function configureCommand(array $row): string
    {
        return 'php artisan atlas:ai:telemetry:cost-rates'
            .' --provider='.$this->shellArg((string) ($row['provider'] ?? ''))
            .' --model='.$this->shellArg((string) ($row['model'] ?? ''))
            .' --input-microusd=<current_input_microusd_per_1k>'
            .' --output-microusd=<current_output_microusd_per_1k>'
            .' --json';
    }

    private function shellArg(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
