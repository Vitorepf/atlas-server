<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Deterministic Self-Construction task graph coverage dossier.
 *
 * Pure, facts-only. Composes:
 *   - $facts['coverage']  : output of {@see AtlasSelfConstructionTaskGraphCoverageAuditor::audit}
 *   - $facts['planner']   : output of {@see AtlasSelfConstructionMissingOrganTaskPlanner::plan}
 *   - $facts['organ_map'] : (optional) output of {@see AtlasSelfConstructionFinalOrganMap::describe}
 *
 * Emits a compact JSON evidence artifact for final autonomy review. No scalar scoring.
 *
 * Status:
 *   - ready   : coverage auditor passed AND no blocking withheld gaps in planner output.
 *   - hold    : coverage auditor reports only refresh-style gaps (thin / stale / missing) AND
 *               planner emitted drafts to refresh them; no blocked or withheld_gap entries.
 *   - blocked : coverage auditor reports blocked organs OR planner withheld gaps that cannot be
 *               drafted safely.
 */
final class AtlasSelfConstructionTaskGraphCoverageDossier
{
    public const SCHEMA = 'atlas.self_construction.task_graph_coverage_dossier.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_HOLD = 'hold';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function export(array $facts): array
    {
        $coverage = is_array($facts['coverage'] ?? null) ? $facts['coverage'] : [];
        $planner = is_array($facts['planner'] ?? null) ? $facts['planner'] : [];
        $organMap = is_array($facts['organ_map'] ?? null) ? $facts['organ_map'] : [];

        $coveragePassed = (bool) ($coverage['passed'] ?? false);
        $missingOrgans = array_values((array) ($coverage['missing_organs'] ?? []));
        $thinOrgans = array_values((array) ($coverage['thin_organs'] ?? []));
        $staleOrgans = array_values((array) ($coverage['stale_organs'] ?? []));
        $blockedOrgans = array_values((array) ($coverage['blocked_organs'] ?? []));
        $organCoverage = (array) ($coverage['organ_coverage'] ?? []);

        $drafts = array_values((array) ($planner['drafts'] ?? []));
        $withheld = array_values((array) ($planner['withheld_gaps'] ?? []));

        $organSummary = [
            'total' => count($organCoverage),
            'covered_count' => count(array_filter($organCoverage, static fn (string $v): bool => $v === 'covered')),
            'missing_count' => count($missingOrgans),
            'thin_count' => count($thinOrgans),
            'stale_count' => count($staleOrgans),
            'blocked_count' => count($blockedOrgans),
            'per_organ' => $organCoverage,
        ];

        $draftSummary = [
            'draft_count' => count($drafts),
            'withheld_count' => count($withheld),
            'drafts' => array_map(static fn (array $d): array => [
                'task_packet_id' => (string) ($d['task_packet_id'] ?? ''),
                'objective' => (string) ($d['objective'] ?? ''),
                'allowed_files' => array_values((array) ($d['allowed_files'] ?? [])),
                'wave' => (string) ($d['wave'] ?? ''),
            ], $drafts),
            'withheld_gaps' => $withheld,
        ];

        $blockers = [];
        if ($blockedOrgans !== []) {
            foreach ($blockedOrgans as $organId) {
                $blockers[] = 'coverage_blocked_organ:'.$organId;
            }
        }
        foreach ($withheld as $row) {
            $blockers[] = 'planner_withheld_gap:'.(string) ($row['organ_id'] ?? '');
        }

        if ($coveragePassed && $blockers === []) {
            $status = self::STATUS_READY;
        } elseif ($blockers !== []) {
            $status = self::STATUS_BLOCKED;
        } else {
            $status = self::STATUS_HOLD;
        }

        $dossierId = $this->dossierId($status, $organSummary, $draftSummary, $blockers);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'dossier_id' => $dossierId,
            'status' => $status,
            'organ_map_schema_version' => (string) ($organMap['schema_version'] ?? ''),
            'organ_summary' => $organSummary,
            'missing_organs' => $missingOrgans,
            'thin_organs' => $thinOrgans,
            'stale_organs' => $staleOrgans,
            'blocked_organs' => $blockedOrgans,
            'draft_summary' => $draftSummary,
            'blockers' => $blockers,
            'proof_summary' => sprintf(
                'status=%s organs=%d covered=%d missing=%d thin=%d stale=%d blocked=%d drafts=%d withheld=%d',
                $status,
                $organSummary['total'],
                $organSummary['covered_count'],
                $organSummary['missing_count'],
                $organSummary['thin_count'],
                $organSummary['stale_count'],
                $organSummary['blocked_count'],
                $draftSummary['draft_count'],
                $draftSummary['withheld_count'],
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $organSummary
     * @param  array<string,mixed>  $draftSummary
     * @param  list<string>  $blockers
     */
    private function dossierId(string $status, array $organSummary, array $draftSummary, array $blockers): string
    {
        $canonical = json_encode([
            'status' => $status,
            'organ_summary' => $organSummary,
            'draft_summary' => $draftSummary,
            'blockers' => $blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'atlas-coverage-dossier_'.substr(hash('sha256', (string) $canonical), 0, 24);
    }
}
