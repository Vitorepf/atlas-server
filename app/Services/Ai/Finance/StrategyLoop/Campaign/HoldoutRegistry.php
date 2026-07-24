<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Support\UtcIsoTimestamp;

/**
 * Persistent holdout registry. Campaign-local files are not enough: the same
 * sealed slice can be re-opened by many campaigns and silently stop being fresh.
 * This registry makes reuse visible across campaigns and fails closed once a
 * holdout is consumed.
 */
final class HoldoutRegistry
{
    public function __construct(private readonly string $path) {}

    public static function default(bool $dryRun = false): self
    {
        return new self($dryRun
            ? storage_path('framework/atlas/finance/dry-run-holdouts/registry.json')
            : storage_path('atlas/finance/holdouts/registry.json'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function register(array $record): array
    {
        $registry = $this->load();
        $id = (string) ($record['holdout_id'] ?? '');
        $existing = $registry['holdouts'][$id] ?? null;
        if (is_array($existing)) {
            return $existing + $record;
        }

        $now = UtcIsoTimestamp::now();
        $stored = $record + [
            'reuse_count' => 0,
            'status' => (string) ($record['role'] ?? '') === 'confirmation'
                ? StrategyCampaignStore::HOLDOUT_RESERVED
                : StrategyCampaignStore::HOLDOUT_FRESH,
            'first_seen_at' => $now,
            'last_used_at' => null,
            'campaigns' => [],
        ];

        $registry['holdouts'][$id] = $stored;
        $registry['updated_at'] = $now;
        $this->save($registry);

        return $stored;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    public function recordUse(string $holdoutId, array $event): void
    {
        $registry = $this->load();
        $holdout = $registry['holdouts'][$holdoutId] ?? ['holdout_id' => $holdoutId, 'reuse_count' => 0, 'campaigns' => []];
        $maxReuse = max(1, (int) ($holdout['max_reuse'] ?? $event['max_reuse'] ?? 1));
        $reuseCount = max(0, (int) ($holdout['reuse_count'] ?? 0)) + 1;
        $holdout['reuse_count'] = $reuseCount;
        $holdout['max_reuse'] = $maxReuse;
        $holdout['last_used_at'] = UtcIsoTimestamp::now();
        $holdout['status'] = $reuseCount >= $maxReuse
            ? StrategyCampaignStore::HOLDOUT_EXHAUSTED
            : StrategyCampaignStore::HOLDOUT_ACTIVE;
        $campaignId = (string) ($event['campaign_id'] ?? '');
        if ($campaignId !== '') {
            $campaigns = array_values(array_unique(array_merge($holdout['campaigns'] ?? [], [$campaignId])));
            $holdout['campaigns'] = $campaigns;
        }
        $holdout['last_event'] = $event;

        $registry['holdouts'][$holdoutId] = $holdout;
        $registry['updated_at'] = UtcIsoTimestamp::now();
        $this->save($registry);
    }

    public function statusForUse(string $holdoutId, int $campaignUseNumber, int $maxReuse): string
    {
        $registry = $this->load();
        $holdout = $registry['holdouts'][$holdoutId] ?? null;
        if (is_array($holdout)) {
            $reuse = (int) ($holdout['reuse_count'] ?? 0);
            $limit = max(1, (int) ($holdout['max_reuse'] ?? $maxReuse));
            if ($reuse >= $limit) {
                return StrategyCampaignStore::HOLDOUT_EXHAUSTED;
            }

            if (($holdout['role'] ?? '') === 'confirmation' && $reuse === 0 && $campaignUseNumber === 0) {
                return StrategyCampaignStore::HOLDOUT_RESERVED;
            }

            return ($reuse + max(0, $campaignUseNumber)) === 0 ? StrategyCampaignStore::HOLDOUT_FRESH : StrategyCampaignStore::HOLDOUT_ACTIVE;
        }

        if (max(0, $campaignUseNumber) >= max(1, $maxReuse)) {
            return StrategyCampaignStore::HOLDOUT_EXHAUSTED;
        }

        return $campaignUseNumber === 0 ? StrategyCampaignStore::HOLDOUT_FRESH : StrategyCampaignStore::HOLDOUT_ACTIVE;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return $this->load();
    }

    /**
     * @return array<string,mixed>
     */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [
                'schema_version' => 'atlas.finance.holdout_registry.v1',
                'created_at' => UtcIsoTimestamp::now(),
                'updated_at' => UtcIsoTimestamp::now(),
                'holdouts' => [],
            ];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded + ['holdouts' => []] : ['holdouts' => []];
    }

    /**
     * @param  array<string,mixed>  $registry
     */
    private function save(array $registry): void
    {
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
