<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassMiner;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Throwable;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopTaskClassMiner::mine()} at the operator surface: gathers a campaign's packet
 * records and mines task-class clusters (cluster_id, member packets, cohesion, success_rate, evidence
 * histogram) against the projection outcome ledger, emitting them as deterministic facts. Read-only.
 *
 * Records come from an injectable seam (best-effort serving-store read by default).
 */
final class AtlasLoopTaskClassMineCommand extends Command
{
    /** Container key for an injected record source (test/integration seam): list<array>|callable(string):list<array>. */
    private const RECORDS_BINDING = 'atlas.loop.task_class.records';

    protected $signature = 'atlas:loop:task-class-mine {--campaign=} {--json}';

    protected $description = 'Read-only task-class cluster miner for a campaign (discovered task classes + success rates).';

    public function handle(): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'task-class-mine requires --campaign=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $clusters = (new AtlasLoopTaskClassMiner)->mine($campaign, $this->records($campaign));

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.task_class_mine.v1',
            'campaign' => $campaign,
            'clusters_count' => count($clusters),
            'clusters' => $clusters,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function records(string $campaign): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::RECORDS_BINDING)) {
            $bound = $app->make(self::RECORDS_BINDING);
            if (is_callable($bound)) {
                $bound = $bound($campaign);
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return $this->fromServingStore();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fromServingStore(): array
    {
        try {
            $records = [];
            foreach (['resolved', 'released', 'blocked'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $records[] = (array) (data_get($row, 'task_packet') ?? $row);
                }
            }

            return $records;
        } catch (Throwable) {
            return [];
        }
    }
}
