<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\HoldoutRegistry;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use PHPUnit\Framework\TestCase;

final class HoldoutRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/atlas-holdout-registry-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_registry_tracks_reuse_and_exhausts_holdout_globally(): void
    {
        $registry = new HoldoutRegistry($this->path);
        $record = $registry->register([
            'holdout_id' => 'h1',
            'role' => 'validation',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'range' => ['bars' => 100],
            'data_sha' => 'abc',
            'max_reuse' => 2,
        ]);

        $this->assertSame(StrategyCampaignStore::HOLDOUT_FRESH, $record['status']);
        $this->assertSame(StrategyCampaignStore::HOLDOUT_ACTIVE, $registry->statusForUse('h1', 1, 2));

        $registry->recordUse('h1', ['campaign_id' => 'c1', 'max_reuse' => 2]);
        $registry->recordUse('h1', ['campaign_id' => 'c2', 'max_reuse' => 2]);

        $snapshot = $registry->snapshot();
        $this->assertSame(2, $snapshot['holdouts']['h1']['reuse_count']);
        $this->assertSame(StrategyCampaignStore::HOLDOUT_EXHAUSTED, $snapshot['holdouts']['h1']['status']);
        $this->assertSame(StrategyCampaignStore::HOLDOUT_EXHAUSTED, $registry->statusForUse('h1', 0, 2));
    }

    public function test_confirmation_holdout_starts_reserved(): void
    {
        $record = (new HoldoutRegistry($this->path))->register([
            'holdout_id' => 'confirm-1',
            'role' => 'confirmation',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'range' => ['bars' => 50],
            'data_sha' => 'abc',
            'max_reuse' => 1,
        ]);

        $this->assertSame(StrategyCampaignStore::HOLDOUT_RESERVED, $record['status']);
    }
}
