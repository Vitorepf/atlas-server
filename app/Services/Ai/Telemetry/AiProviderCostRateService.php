<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiProviderCostRate;
use App\Models\AiTraceMetricSummary;
use App\Support\AtlasSecurity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiProviderCostRateService
{
    private const MAX_MICROUSD_PER_1K = 4294967295;

    /**
     * @param  array<string,mixed>  $data
     */
    public function upsert(array $data): AiProviderCostRate
    {
        if (! Schema::hasTable('ai_provider_cost_rates')) {
            throw new \RuntimeException('ai_provider_cost_rates table is not available.');
        }

        $provider = $this->requiredString($data['provider'] ?? null, 80, 'provider');
        $model = $this->requiredString($data['model'] ?? null, 120, 'model');
        $effectiveFrom = $this->date($data['effective_from'] ?? null, 'effective_from') ?? now()->toImmutable();
        $effectiveUntil = $this->date($data['effective_until'] ?? null, 'effective_until');
        if ($effectiveUntil !== null && $effectiveUntil->lt($effectiveFrom)) {
            throw new \InvalidArgumentException('effective_until must not be before effective_from.');
        }

        $payload = [
            'input_microusd_per_1k' => $this->nonNegativeInt($data['input_microusd_per_1k'] ?? null, 'input_microusd_per_1k'),
            'output_microusd_per_1k' => $this->nonNegativeInt($data['output_microusd_per_1k'] ?? null, 'output_microusd_per_1k'),
            'currency' => $this->currency($data['currency'] ?? 'USD'),
            'effective_until' => $effectiveUntil,
            'metadata' => AtlasSecurity::redactArray(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
        ];

        $existing = AiProviderCostRate::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('effective_from', $effectiveFrom)
            ->first();

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing->refresh();
        }

        return AiProviderCostRate::query()->create([
            'provider' => $provider,
            'model' => $model,
            'effective_from' => $effectiveFrom,
            'created_at' => now(),
            ...$payload,
        ]);
    }

    public function syncConfiguredRates(): int
    {
        $result = $this->upsertMany($this->configuredRates(), 'config');
        if ($result['errors'] !== []) {
            throw new \InvalidArgumentException(
                'Configured cost rates contain invalid rows: '.collect($result['errors'])
                    ->map(fn (array $error): string => "row {$error['index']}: {$error['message']}")
                    ->implode('; ')
            );
        }

        return count($result['upserted']);
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return array{upserted:array<int,AiProviderCostRate>,errors:array<int,array{index:int,message:string}>}
     */
    public function upsertMany(array $rows, string $source): array
    {
        $upserted = [];
        $errors = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                $errors[] = ['index' => $index, 'message' => 'Rate row must be an object.'];

                continue;
            }

            try {
                $upserted[] = $this->upsert($this->withMetadataSource($row, $source));
            } catch (\Throwable $exception) {
                $errors[] = ['index' => $index, 'message' => $exception->getMessage()];
            }
        }

        return [
            'upserted' => $upserted,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    public function ratesFromJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: '.json_last_error_msg());
        }

        $rates = is_array($decoded) && array_key_exists('rates', $decoded) ? $decoded['rates'] : $decoded;
        if (! is_array($rates) || ! array_is_list($rates)) {
            throw new \InvalidArgumentException('JSON must be an array of rates or an object with a rates array.');
        }

        return array_values($rates);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function missingRates(CarbonInterface $since, ?CarbonInterface $until = null, int $limit = 50): array
    {
        $until ??= now();
        $limit = max(1, min($limit, 200));

        if (! Schema::hasTable('ai_trace_metric_summaries') || ! Schema::hasTable('ai_provider_cost_rates')) {
            return [];
        }

        return AiTraceMetricSummary::query()
            ->select([
                'provider',
                'model',
                DB::raw('COUNT(*) as traces'),
                DB::raw('MAX(computed_at) as latest_computed_at'),
            ])
            ->where('computed_at', '>=', $since)
            ->where('computed_at', '<=', $until)
            ->where('cost_confidence', 'unknown')
            ->whereDoesntHave('trace', function (Builder $query): void {
                $query
                    ->whereNull('provider')
                    ->whereNull('model')
                    ->where('metadata->schema_version', 'atlas.ledger_projection.metadata.v1')
                    ->where('metadata->projection_id', 'ai_traces');
            })
            ->groupBy('provider', 'model')
            ->orderByDesc('traces')
            ->limit($limit)
            ->get()
            ->map(function ($row): ?array {
                $provider = is_string($row->provider) && trim($row->provider) !== '' ? (string) $row->provider : null;
                $model = is_string($row->model) && trim($row->model) !== '' ? (string) $row->model : null;
                $reason = $this->missingRateReason($provider, $model);

                if ($reason === null) {
                    return null;
                }

                return [
                    'provider' => $provider,
                    'model' => $model,
                    'reason' => $reason,
                    'can_import_rate' => $provider !== null && $model !== null,
                    'traces' => (int) $row->traces,
                    'latest_computed_at' => $row->latest_computed_at ? CarbonImmutable::parse((string) $row->latest_computed_at)->toJSON() : null,
                    'rate_template' => $provider !== null && $model !== null
                        ? $this->rateTemplate($provider, $model, (int) $row->traces)
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function withMetadataSource(array $data, string $source): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $data['metadata'] = [
            'source' => $source,
        ] + $metadata;

        return $data;
    }

    /**
     * @return Builder<AiProviderCostRate>
     */
    public function queryActive(?string $provider = null, ?string $model = null): Builder
    {
        $query = AiProviderCostRate::query()
            ->where('effective_from', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->latest('effective_from');

        if ($provider) {
            $query->where('provider', $provider);
        }

        if ($model) {
            $query->where('model', $model);
        }

        return $query;
    }

    /**
     * @return array<int,mixed>
     */
    private function configuredRates(): array
    {
        $rates = config('atlas.ai_metrics.cost_rates', []);

        return is_array($rates) ? array_values($rates) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function rateTemplate(string $provider, string $model, int $traces): array
    {
        return [
            'provider' => $provider,
            'model' => $model,
            'input_microusd_per_1k' => '<fill_current_input_microusd_per_1k>',
            'output_microusd_per_1k' => '<fill_current_output_microusd_per_1k>',
            'currency' => 'USD',
            'effective_from' => now()->toJSON(),
            'metadata' => [
                'source' => 'missing_cost_rate_template',
                'unknown_cost_traces' => $traces,
            ],
        ];
    }

    private function missingRateReason(?string $provider, ?string $model): ?string
    {
        if ($provider === null) {
            return 'missing_provider_identity';
        }

        if ($model === null) {
            return 'missing_model_identity';
        }

        return $this->queryActive($provider, $model)->exists() ? null : 'missing_active_cost_rate';
    }

    private function requiredString(mixed $value, int $limit, string $field): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new \InvalidArgumentException("{$field} is required.");
        }

        $value = trim((string) $value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} is required.");
        }

        if (strlen($value) > $limit) {
            throw new \InvalidArgumentException("{$field} must be {$limit} characters or fewer.");
        }

        return $value;
    }

    private function currency(mixed $value): string
    {
        if ($value === null) {
            return 'USD';
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException('currency must be a 3 to 8 character code.');
        }

        $value = strtoupper(trim($value));
        if ($value === '') {
            return 'USD';
        }

        if (! preg_match('/^[A-Z]{3,8}$/', $value)) {
            throw new \InvalidArgumentException('currency must be a 3 to 8 character code.');
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_string($value)) {
            $normalized = trim($value);
        } elseif (is_float($value)) {
            if ($value < 0) {
                throw new \InvalidArgumentException("{$field} must be greater than or equal to 0.");
            }

            throw new \InvalidArgumentException("{$field} must be an integer.");
        } elseif ($value === null) {
            throw new \InvalidArgumentException("{$field} is required.");
        } else {
            throw new \InvalidArgumentException("{$field} must be an integer.");
        }

        if ($normalized === '') {
            throw new \InvalidArgumentException("{$field} is required.");
        }

        if (str_starts_with($normalized, '-')) {
            throw new \InvalidArgumentException("{$field} must be greater than or equal to 0.");
        }

        if (! preg_match('/^\d+$/', $normalized)) {
            throw new \InvalidArgumentException("{$field} must be an integer.");
        }

        $normalized = ltrim($normalized, '0');
        if ($normalized === '') {
            return 0;
        }

        $max = (string) self::MAX_MICROUSD_PER_1K;
        if (strlen($normalized) > strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new \InvalidArgumentException("{$field} must be less than or equal to ".self::MAX_MICROUSD_PER_1K.'.');
        }

        return (int) $normalized;
    }

    private function date(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException("{$field} must be a valid datetime.");
        }
    }
}
