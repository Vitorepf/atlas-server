<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopFrozenOutcomeBattery;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopFrozenOutcomeBattery::pairs()} + {@see ::rootHash()} at the operator
 * surface: dumps the frozen refactor-vs-leap outcome battery and its integrity root hash as deterministic facts.
 *
 * Pure + read-only: it reports the frozen pairs and their root hash; it mutates nothing.
 */
final class AtlasLoopFrozenBatteryCommand extends Command
{
    protected $signature = 'atlas:loop:frozen-battery {--json}';

    protected $description = 'Read-only dump of the frozen outcome battery pairs + their integrity root hash.';

    public function handle(): int
    {
        $battery = app(AtlasLoopFrozenOutcomeBattery::class);
        $pairs = $battery->pairs();

        $facts = [
            'schema' => AtlasLoopFrozenOutcomeBattery::SCHEMA_VERSION,
            'pair_count' => count($pairs),
            'root_hash' => $battery->rootHash(),
            'pairs' => $pairs,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('pair_count: '.$facts['pair_count']);
            $this->line('root_hash: '.$facts['root_hash']);
        }

        return self::SUCCESS;
    }
}
