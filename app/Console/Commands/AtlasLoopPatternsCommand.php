<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternMiner;
use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternReceiptLedger;
use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternStabilityChecker;
use Illuminate\Console\Command;

/**
 * Operator surface for cross-cycle pattern emergence.
 *
 *   atlas:loop:patterns mine    --n=<int> [--json]
 *   atlas:loop:patterns stable  --n=<int> --k=<int> [--json]
 *   atlas:loop:patterns history [--json]
 *
 * Delegates to the existing miner / stability checker / receipt ledger — NEVER computes patterns
 * itself. Episodes are resolved from the container binding `atlas.loop.patterns.episodes` (callable
 * returning list<episode>) so this command stays test-driveable.
 */
final class AtlasLoopPatternsCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:patterns {action : mine|stable|history}
        {--n=5 : episode window for mining}
        {--k=3 : consecutive-cycle stability threshold}
        {--cycles= : optional explicit cycle-ids filter (comma-separated)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Cross-cycle pattern emergence CLI: mine | stable | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'mine' => $this->mine(),
            'stable' => $this->stable(),
            'history' => $this->history(),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function mine(): int
    {
        $episodes = $this->loadEpisodes();
        $n = max(1, (int) ($this->option('n') ?? 5));
        $window = max(1, $n);
        $miner = new AtlasLoopCrossCyclePatternMiner($episodes, $window);
        $payload = $miner->mine();
        $cycleIds = $this->cycleIdsFromEpisodes($episodes);

        $this->emit([
            'action' => 'mine',
            'n' => $n,
            'cycle_ids' => $cycleIds,
            'patterns' => $payload['patterns'],
            'window' => $payload['window'],
        ]);

        return self::EXIT_OK;
    }

    private function stable(): int
    {
        $episodes = $this->loadEpisodes();
        $n = max(1, (int) ($this->option('n') ?? 5));
        $k = max(1, (int) ($this->option('k') ?? 3));
        $window = max(1, $n);

        $minerPayload = (new AtlasLoopCrossCyclePatternMiner($episodes, $window))->mine();
        $cycleIds = $this->cycleIdsFromEpisodes($episodes);
        $minerPayload['cycle_ids'] = $cycleIds;

        $checker = new AtlasLoopCrossCyclePatternStabilityChecker($minerPayload);
        try {
            $stabilityPayload = $checker->stabilityFor($k);
        } catch (\Throwable $e) {
            return $this->failWith('stability_check_failed:'.$e->getMessage());
        }

        // Align both payloads on the same cycle-id set so the receipt ledger accepts the run.
        $stabilityPayload['cycle_ids'] = $cycleIds;

        $ledger = $this->ledger();
        if ($ledger !== null) {
            try {
                $ledger->record($n, $k, $minerPayload, $stabilityPayload);
            } catch (\Throwable $e) {
                return $this->failWith('record_failed:'.$e->getMessage());
            }
        }

        $this->emit([
            'action' => 'stable',
            'n' => $n,
            'k' => $k,
            'cycle_ids' => $cycleIds,
            'window' => $minerPayload['window'] ?? $window,
            'stable_patterns' => $stabilityPayload['stable_patterns'] ?? [],
        ]);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $ledger = $this->ledger();
        $rows = $ledger !== null ? $ledger->readAll() : [];

        $this->emit(['action' => 'history', 'receipts' => $rows]);

        return self::EXIT_OK;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadEpisodes(): array
    {
        if (app()->bound('atlas.loop.patterns.episodes')) {
            $source = app('atlas.loop.patterns.episodes');
            if (is_callable($source)) {
                $rows = $source();

                return is_array($rows) ? array_values($rows) : [];
            }
            if (is_array($source)) {
                return array_values($source);
            }
        }

        return [];
    }

    /**
     * @param  list<array<string,mixed>>  $episodes
     * @return list<string>
     */
    private function cycleIdsFromEpisodes(array $episodes): array
    {
        $ids = [];
        foreach ($episodes as $ep) {
            if (! is_array($ep)) {
                continue;
            }
            $id = (string) ($ep['cycle_id'] ?? $ep['episode_id'] ?? $ep['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);

        return array_values(array_unique($ids));
    }

    private function ledger(): ?AtlasLoopCrossCyclePatternReceiptLedger
    {
        if (app()->bound(AtlasLoopCrossCyclePatternReceiptLedger::class)) {
            return app(AtlasLoopCrossCyclePatternReceiptLedger::class);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
