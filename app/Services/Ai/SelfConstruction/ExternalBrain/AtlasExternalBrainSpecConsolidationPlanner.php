<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner: merges related low-scope candidates into one macro-task when
 * queue pressure is high, preventing small tasks from flooding the muscles.
 *
 * Grouping key: `theme` field on each candidate.
 * A group is consolidated only when ALL of these pass:
 *   - queue_pressure = 'high'
 *   - at least 2 candidates share a theme
 *   - no file collision (same path in two candidates' allowed_files)
 *   - no cross-group dependency (a candidate depends on another in the same group)
 *   - combined allowed_files count <= max_consolidated_files (default: MAX_FILES)
 *   - no mix of elevated risk_level ('high'/'critical') with lower risk in the same group
 *   - every candidate carries its own required_evidence (none dropped by the merge)
 *
 * On failure, every candidate in the group is marked unconsolidated with a reason.
 * Output: consolidated_tasks (macro-tasks), unconsolidated (ids), rejection_reasons.
 */
final class AtlasExternalBrainSpecConsolidationPlanner
{
    public const SCHEMA = 'atlas.external_brain.spec_consolidation_planner.v1';

    public const MAX_FILES = 8;

    public const MIN_GROUP_SIZE = 2;

    /**
     * @param  array<string,mixed>  $input  candidates + queue_pressure
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $pressure = (string) ($input['queue_pressure'] ?? 'normal');
        $maxFiles = (int) ($input['max_consolidated_files'] ?? self::MAX_FILES);

        if ($pressure !== 'high' || count($candidates) === 0) {
            return [
                'schema_version'         => self::SCHEMA,
                'consolidated_tasks'     => [],
                'unconsolidated'         => array_values(array_column($candidates, 'task_id')),
                'rejection_reasons'      => [],
                'max_allowed_files_used' => $maxFiles,
            ];
        }

        $byTheme = [];
        foreach ($candidates as $c) {
            $byTheme[(string) ($c['theme'] ?? 'default')][] = $c;
        }

        $consolidated = [];
        $unconsolidated = [];
        $rejectionReasons = [];

        foreach ($byTheme as $theme => $group) {
            $taskIds = array_column($group, 'task_id');

            if (count($group) < self::MIN_GROUP_SIZE) {
                foreach ($taskIds as $id) {
                    $unconsolidated[] = $id;
                }
                continue;
            }

            [$ok, $reason, $allFiles] = $this->validate($group, $taskIds, $maxFiles);

            if (! $ok) {
                foreach ($taskIds as $id) {
                    $unconsolidated[] = $id;
                    $rejectionReasons[$id] = $reason;
                }
                continue;
            }

            $acceptance = array_values(array_unique(array_merge(
                [],
                ...array_map(static fn (array $c): array => is_array($c['acceptance_criteria'] ?? null) ? $c['acceptance_criteria'] : [], $group)
            )));
            $deps = array_values(array_unique(array_merge(
                [],
                ...array_map(static fn (array $c): array => is_array($c['dependencies'] ?? null) ? $c['dependencies'] : [], $group)
            )));
            $evidence = array_values(array_unique(array_merge(
                [],
                ...array_map(static fn (array $c): array => is_array($c['required_evidence'] ?? null) ? $c['required_evidence'] : [], $group)
            )));

            $fileCount = count($allFiles);
            $taskCount = count($group);
            $consolidationScore = round(min(1.0, $taskCount / max(1, $fileCount + 1)), 3);

            $consolidated[] = [
                'macro_task_id'       => 'consolidated_' . $theme,
                'source_task_ids'     => $taskIds,
                'theme'               => $theme,
                'shared_theme'        => $theme,
                'consolidation_score' => $consolidationScore,
                'allowed_files'       => $allFiles,
                'acceptance_criteria' => $acceptance,
                'dependencies'        => array_values(array_unique($deps)),
                'required_evidence'   => array_values(array_unique($evidence)),
            ];
        }

        return [
            'schema_version'       => self::SCHEMA,
            'consolidated_tasks'   => $consolidated,
            'unconsolidated'       => $unconsolidated,
            'rejection_reasons'    => $rejectionReasons,
            'max_allowed_files_used' => $maxFiles,
        ];
    }

    /**
     * @param  array<array<string,mixed>>  $group
     * @param  string[]                    $taskIds
     * @return array{bool, string, string[]}  [ok, reason, allFiles]
     */
    private function validate(array $group, array $taskIds, int $maxFiles): array
    {
        $seen = [];
        $allFiles = [];

        foreach ($group as $c) {
            $files = is_array($c['allowed_files'] ?? null) ? $c['allowed_files'] : [];
            foreach ($files as $f) {
                if (isset($seen[$f])) {
                    return [false, 'file_collision', []];
                }
                $seen[$f] = true;
                $allFiles[] = $f;
            }
        }

        if (count($allFiles) > $maxFiles) {
            return [false, 'over_wide_task', []];
        }

        foreach ($group as $c) {
            $deps = is_array($c['dependencies'] ?? null) ? $c['dependencies'] : [];
            foreach ($deps as $dep) {
                if (in_array($dep, $taskIds, true)) {
                    return [false, 'incompatible_dependencies', []];
                }
            }
        }

        $risks = array_map(static fn (array $c): string => (string) ($c['risk_level'] ?? 'low'), $group);
        $elevated = array_filter($risks, static fn (string $r): bool => in_array($r, ['high', 'critical'], true));
        if (count($elevated) > 0 && count($elevated) < count($risks)) {
            return [false, 'hides_risk', []];
        }

        foreach ($group as $c) {
            $evidence = is_array($c['required_evidence'] ?? null) ? $c['required_evidence'] : [];
            if (count($evidence) === 0) {
                return [false, 'evidence_loss', []];
            }
        }

        return [true, '', $allFiles];
    }
}
