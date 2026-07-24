<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Support\UtcIsoTimestamp;

/**
 * Sequential confirmation queue for near-certified champions.
 *
 * It records "run this independent campaign later" evidence. It never starts a
 * second loop by itself; the strategy-search lock remains the runtime guard.
 */
final class StrategyConfirmationQueue
{
    public function __construct(private readonly string $path) {}

    public static function default(bool $dryRun = false): self
    {
        return new self($dryRun
            ? storage_path('framework/atlas/finance/confirmation-queue.json')
            : storage_path('atlas/finance/confirmation-queue.json'));
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function enqueue(array $request): array
    {
        $queue = $this->load();
        $signature = (string) ($request['signature']['signature'] ?? '');
        $sourceCampaign = (string) ($request['source_campaign_id'] ?? '');

        foreach ($queue['items'] as $item) {
            if ((string) ($item['signature']['signature'] ?? '') === $signature
                && (string) ($item['source_campaign_id'] ?? '') === $sourceCampaign
                && in_array((string) ($item['status'] ?? ''), ['pending', 'claimed'], true)) {
                return $item + ['duplicate' => true];
            }
        }

        $seed = (int) ($request['seed'] ?? random_int(1, PHP_INT_MAX));
        $symbol = strtoupper((string) ($request['symbol'] ?? 'BTCUSDT'));
        $interval = (string) ($request['interval'] ?? '1d');
        $family = (string) ($request['strategy_family'] ?? 'trend-breakout-v1');
        $featureSet = is_array($request['feature_set'] ?? null)
            ? $request['feature_set']
            : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        $featureSetId = (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY);
        $campaignId = StrategyCampaignStore::sanitizeId(sprintf(
            '%s-%s-%s-confirm-%s-s%d',
            $symbol,
            $interval,
            $family,
            $signature !== '' ? substr($signature, 0, 10) : substr(StrategyCampaignStore::hash($request), 0, 10),
            $seed,
        ));

        $item = [
            'schema_version' => 'atlas.finance.strategy_confirmation_queue_item.v1',
            'request_id' => substr(StrategyCampaignStore::hash([$request, $campaignId]), 0, 16),
            'status' => 'pending',
            'created_at' => UtcIsoTimestamp::now(),
            'source_campaign_id' => $sourceCampaign,
            'source_round' => $request['source_round'] ?? null,
            'symbol' => $symbol,
            'interval' => $interval,
            'strategy_family' => $family,
            'feature_set' => $featureSet,
            'signature' => $request['signature'] ?? [],
            'candidate_params' => $request['candidate_params'] ?? [],
            'required_independent_campaigns' => max(1, (int) ($request['required_independent_campaigns'] ?? 1)),
            'reason' => 'cross_campaign_rediscovery_required',
            'confirmation_campaign' => [
                'campaign_id' => $campaignId,
                'seed' => $seed,
                'feature_set' => $featureSet,
                'candidates_per_round' => max(10, (int) ($request['candidates_per_round'] ?? 600)),
                'max_rounds' => max(0, (int) ($request['max_rounds'] ?? 0)),
                'command' => $this->command($symbol, $interval, $family, $featureSetId, $campaignId, $seed, (int) ($request['candidates_per_round'] ?? 600), (int) ($request['max_rounds'] ?? 0)),
            ],
            'parallelism_policy' => 'sequential_only_never_parallel',
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];

        $queue['items'][] = $item;
        $queue['updated_at'] = UtcIsoTimestamp::now();
        $this->save($queue);

        return $item;
    }

    /** @return array<string,mixed>|null */
    public function nextPending(): ?array
    {
        foreach ($this->load()['items'] as $item) {
            if ((string) ($item['status'] ?? '') === 'pending') {
                return $item;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function pending(): array
    {
        return array_values(array_filter(
            $this->load()['items'],
            static fn (array $item): bool => (string) ($item['status'] ?? '') === 'pending',
        ));
    }

    /** @return array<string,mixed>|null */
    public function claimNext(): ?array
    {
        $queue = $this->load();
        foreach ($queue['items'] as $index => $item) {
            if ((string) ($item['status'] ?? '') !== 'pending') {
                continue;
            }
            $queue['items'][$index]['status'] = 'claimed';
            $queue['items'][$index]['claimed_at'] = UtcIsoTimestamp::now();
            $queue['updated_at'] = UtcIsoTimestamp::now();
            $this->save($queue);

            return $queue['items'][$index];
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return [
                'schema_version' => 'atlas.finance.strategy_confirmation_queue.v1',
                'created_at' => UtcIsoTimestamp::now(),
                'updated_at' => UtcIsoTimestamp::now(),
                'parallelism_policy' => 'one_active_strategy_search_loop',
                'items' => [],
            ];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($decoded)) {
            return ['items' => []];
        }

        $decoded['items'] = array_values(array_filter($decoded['items'] ?? [], 'is_array'));

        return $decoded + ['items' => []];
    }

    private function command(string $symbol, string $interval, string $family, string $featureSetId, string $campaignId, int $seed, int $candidates, int $maxRounds): string
    {
        $parts = [
            'php artisan atlas:finance:strategy-search',
            '--symbol='.$symbol,
            '--interval='.$interval,
            '--family='.$family,
            '--feature-set='.$featureSetId,
            '--campaign-id='.$campaignId,
            '--candidates='.max(10, $candidates),
            '--seed='.$seed,
            '--sleep=2',
        ];
        if ($maxRounds > 0) {
            $parts[] = '--max-rounds='.$maxRounds;
        }

        return implode(' ', $parts);
    }

    /** @param array<string,mixed> $queue */
    private function save(array $queue): void
    {
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
