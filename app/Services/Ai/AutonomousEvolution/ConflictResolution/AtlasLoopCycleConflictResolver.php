<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ConflictResolution;

final class AtlasLoopCycleConflictResolver
{
    public function resolve(AtlasLoopCycleConflictReport $report): AtlasLoopCycleConflictDecision
    {
        if (! $report->hasConflict()) {
            return new AtlasLoopCycleConflictDecision(
                AtlasLoopCycleConflictPolicy::DECISION_AUTO_MERGE_IDENTICAL,
                [],
                [],
            );
        }

        $mode = $this->dominantMode($report);

        return match ($mode) {
            'identical-bytes' => new AtlasLoopCycleConflictDecision(
                AtlasLoopCycleConflictPolicy::DECISION_AUTO_MERGE_IDENTICAL,
                [],
                $this->receipts($report, AtlasLoopCycleConflictPolicy::CLAUSE_IDENTICAL, 'identical-bytes')
            ),
            'disjoint-hunks' => new AtlasLoopCycleConflictDecision(
                AtlasLoopCycleConflictPolicy::DECISION_STITCH_PENDING_OPERATOR,
                [],
                $this->receipts($report, AtlasLoopCycleConflictPolicy::CLAUSE_STITCH, 'disjoint-hunks')
            ),
            default => new AtlasLoopCycleConflictDecision(
                AtlasLoopCycleConflictPolicy::DECISION_REFUSE,
                [$report->leftCycleId, $report->rightCycleId],
                $this->receipts($report, AtlasLoopCycleConflictPolicy::CLAUSE_DIVERGENT, 'divergent-bytes')
            ),
        };
    }

    private function dominantMode(AtlasLoopCycleConflictReport $report): string
    {
        $modes = array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['mode'] ?? ''), $report->perFile)));
        sort($modes, SORT_STRING);

        if (in_array('divergent-bytes', $modes, true)) {
            return 'divergent-bytes';
        }

        if (in_array('disjoint-hunks', $modes, true)) {
            return 'disjoint-hunks';
        }

        return 'identical-bytes';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function receipts(AtlasLoopCycleConflictReport $report, string $clause, string $mode): array
    {
        $receipts = [];
        foreach ($report->perFile as $row) {
            $receipts[] = [
                'schema_version' => 'atlas.decision_receipt.v2',
                'cycle_ids' => [$report->leftCycleId, $report->rightCycleId],
                'file_path' => (string) ($row['path'] ?? ''),
                'policy_clause' => $clause,
                'overlap_mode' => $mode,
                'decision' => match ($clause) {
                    AtlasLoopCycleConflictPolicy::CLAUSE_IDENTICAL => AtlasLoopCycleConflictPolicy::DECISION_AUTO_MERGE_IDENTICAL,
                    AtlasLoopCycleConflictPolicy::CLAUSE_STITCH => AtlasLoopCycleConflictPolicy::DECISION_STITCH_PENDING_OPERATOR,
                    default => AtlasLoopCycleConflictPolicy::DECISION_REFUSE,
                },
            ];
        }

        return $receipts;
    }
}
