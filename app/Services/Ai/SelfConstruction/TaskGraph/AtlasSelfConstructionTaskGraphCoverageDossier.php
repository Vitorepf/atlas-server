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
            'final_95_gap_report' => $this->buildFinal95GapReport($blockedOrgans, $missingOrgans, $thinOrgans, $staleOrgans),
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
     * Rank the highest-leverage coverage gaps from a dossier envelope for autonomous task creation.
     *
     * PRIORITY ORDER (highest first):
     *   1. blocked_organs    — must unblock before work can flow
     *   2. missing_organs    — no implementation: biggest compounding deficit
     *   3. thin_organs       — implementation exists but test coverage is absent or thin
     *   4. stale_organs      — implementation + tests exist but evidence is outdated
     *
     * Returns a facts-only list of ranked gap records; no scalar score.
     *
     * @param  array<string,mixed>  $dossier  Output of {@see self::export}
     * @return list<array{organ_id:string, gap_kind:string, priority_rank:int}>
     */
    public function rankedNextGaps(array $dossier): array
    {
        $gaps = [];
        $rank = 1;
        foreach ([
            'blocked_organs' => 'blocked',
            'missing_organs' => 'missing_implementation',
            'thin_organs'    => 'missing_tests',
            'stale_organs'   => 'stale_evidence',
        ] as $field => $kind) {
            foreach (array_values((array) ($dossier[$field] ?? [])) as $organId) {
                $gaps[] = ['organ_id' => (string) $organId, 'gap_kind' => $kind, 'priority_rank' => $rank++];
            }
        }

        return $gaps;
    }

    /**
     * @param  list<string>  $blockedOrgans
     * @param  list<string>  $missingOrgans
     * @param  list<string>  $thinOrgans
     * @param  list<string>  $staleOrgans
     * @return list<array{organ_id:string, gap_kind:string, proof_required:string, next_task_family:string}>
     */
    private function buildFinal95GapReport(
        array $blockedOrgans,
        array $missingOrgans,
        array $thinOrgans,
        array $staleOrgans,
    ): array {
        static $meta = [
            'blocked'                => ['proof_required' => 'unblock_receipt_and_test_green',            'next_task_family' => 'coverage_unblock'],
            'missing_implementation' => ['proof_required' => 'implementation_present_and_test_green',     'next_task_family' => 'coverage_implementation'],
            'missing_tests'          => ['proof_required' => 'test_suite_minimum_3_assertions_and_green', 'next_task_family' => 'coverage_test_authoring'],
            'stale_evidence'         => ['proof_required' => 'fresh_evidence_and_index_updated',          'next_task_family' => 'coverage_evidence_refresh'],
        ];

        $rows = [];
        foreach ([
            'blocked'                => $blockedOrgans,
            'missing_implementation' => $missingOrgans,
            'missing_tests'          => $thinOrgans,
            'stale_evidence'         => $staleOrgans,
        ] as $kind => $organs) {
            foreach (array_values($organs) as $organId) {
                $rows[] = [
                    'organ_id'         => (string) $organId,
                    'gap_kind'         => $kind,
                    'proof_required'   => $meta[$kind]['proof_required'],
                    'next_task_family' => $meta[$kind]['next_task_family'],
                ];
            }
        }

        return $rows;
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
