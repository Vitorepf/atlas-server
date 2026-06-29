<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricRoadmapGapMiner;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasTaskFabricRoadmapGapMiner::mine()} at the operator surface: mines
 * Self-Construction roadmap rows into candidate contract inputs (organ, capability, capability_gap, evidence,
 * suggested files), skipping resolved / cosmetic / evidence-less / out-of-scope rows.
 *
 * Pure + read-only + facts-only: it mines and reports candidates; it never authors a contract or mutates anything.
 */
final class AtlasLoopRoadmapGapsCommand extends Command
{
    protected $signature = 'atlas:loop:roadmap-gaps {--rows=} {--json}';

    protected $description = 'Read-only roadmap coverage-gap miner (candidate contract inputs from roadmap rows).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('rows'));
        if ($raw === '') {
            return $this->refuse('roadmap-gaps requires --rows=<JSON array of roadmap rows or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--rows must be a JSON array/object');
        }
        $rows = isset($decoded['rows']) && is_array($decoded['rows']) ? $decoded['rows'] : $decoded;
        if (! array_is_list($rows)) {
            return $this->refuse('--rows must be a JSON array of roadmap rows');
        }

        $gaps = app(AtlasTaskFabricRoadmapGapMiner::class)->mine(array_values($rows));

        $facts = [
            'schema' => 'atlas.loop.roadmap_gaps.v1',
            'gap_count' => count($gaps),
            'gaps' => $gaps,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('gap_count: '.$facts['gap_count']);
            foreach ($gaps as $g) {
                $this->line('  '.$g['organ'].' :: '.$g['capability']);
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
