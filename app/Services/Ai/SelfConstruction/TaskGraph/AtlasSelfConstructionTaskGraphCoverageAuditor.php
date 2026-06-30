<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Deterministic, facts-only auditor that compares task records (claimable / claimed / completed)
 * against the canonical final architecture organ map. Pure - no DB / disk / provider calls.
 *
 * Inputs:
 *   $records — list of {status, task_packet:{tags?:list<string>, allowed_files?, acceptance_criteria?,
 *                                            required_evidence?, evidence_classes?:list<string>,
 *                                            organ_id?:string}, ...}
 *
 * Output (no scalar scoring):
 *   schema_version, status, passed, inspected_count, organ_coverage, missing_organs, thin_organs,
 *   stale_organs, blockers, proof_summary
 *
 * Coverage rules per organ:
 *   - covered  : >=1 self-sufficient (status NOT in {cancelled, lease_expired, blocked} AND
 *                acceptance non-empty AND required_evidence non-empty) record matching the organ
 *                (by required_task_tags / organ_id), AND every required evidence class is present.
 *   - thin     : >=1 matching record but missing one or more evidence classes
 *                ('implementation', 'gate', 'receipt', 'cli_or_readiness').
 *   - stale    : matching records exist but ALL are legacy-only (status='completed_dry_run' or
 *                marked metadata.legacy_only=true) AND no claimable/claimed/active record.
 *   - missing  : no matching record at all.
 */
final class AtlasSelfConstructionTaskGraphCoverageAuditor
{
    public const SCHEMA = 'atlas.self_construction.task_graph_coverage_auditor.v1';

    public const GAPS_SCHEMA = 'atlas.self_construction.task_graph_coverage_auditor.gaps.v1';

    public const COVERAGE_COVERED = 'covered';

    public const COVERAGE_THIN = 'thin';

    public const COVERAGE_STALE = 'stale';

    public const COVERAGE_MISSING = 'missing';

    public const COVERAGE_BLOCKED = 'blocked';

    /** @var array<string,string> */
    public const GAP_REASON_CODES = [
        'missing' => 'unimplemented',
        'thin'    => 'untested',
        'blocked' => 'blocked',
        'stale'   => 'stale_knowledge',
    ];

    /** @var list<string> */
    private const REQUIRED_EVIDENCE_CLASSES = ['implementation', 'gate', 'receipt', 'cli_or_readiness'];

    /**
     * Final external-brain organs grouped by lane.
     * Each lane must have ≥1 organ; an empty lane is an absent lane (coverage_pct = 0.0).
     *
     * @var array<string, list<string>>
     */
    public const FINAL_BRAIN_LANES = [
        'recovery'        => ['brain_recovery', 'exception_recovery_organ'],
        'lane-governor'   => ['brain_lane_governor', 'lane_execution_governor'],
        'task-fabric'     => ['brain_task_fabric', 'task_origination_organ'],
        'maestro-feedback'=> ['brain_maestro_feedback', 'maestro_outcome_ingestor'],
        'frontier'        => ['brain_frontier', 'frontier_scout_organ'],
        'compounding'     => ['brain_compounding', 'compounding_ledger_organ'],
        'final-certifier' => ['brain_final_certifier', 'certification_gate_organ'],
    ];

    /** @var list<string> */
    private const NON_SELF_SUFFICIENT_STATUSES = ['cancelled', 'lease_expired', 'blocked'];

    public function __construct(
        private readonly ?AtlasSelfConstructionFinalOrganMap $organMap = null,
        /** Optional override: a list of organ entries for tests; bypasses $organMap when set. */
        private readonly ?array $organsOverride = null,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function audit(array $records): array
    {
        if ($this->organsOverride !== null) {
            $organs = $this->organsOverride;
        } else {
            $organMap = ($this->organMap ?? new AtlasSelfConstructionFinalOrganMap)->describe();
            $organs = (array) $organMap['organs'];
        }

        $coverage = [];
        $missing = [];
        $thin = [];
        $stale = [];
        $blocked = [];

        foreach ($organs as $organ) {
            $organId = (string) $organ['organ_id'];
            $tags = array_values(array_map('strval', (array) $organ['required_task_tags']));
            $matchedRecords = $this->matchesForOrgan($records, $organId, $tags);

            if ($matchedRecords === []) {
                $coverage[$organId] = self::COVERAGE_MISSING;
                $missing[] = $organId;

                continue;
            }

            $selfSufficient = array_values(array_filter(
                $matchedRecords,
                fn (array $r): bool => $this->isSelfSufficient($r),
            ));
            if ($selfSufficient === []) {
                $hasBlocked = array_filter(
                    $matchedRecords,
                    static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), self::NON_SELF_SUFFICIENT_STATUSES, true),
                );
                if ($hasBlocked !== []) {
                    $coverage[$organId] = self::COVERAGE_BLOCKED;
                    $blocked[] = $organId;
                } else {
                    $coverage[$organId] = self::COVERAGE_STALE;
                    $stale[] = $organId;
                }

                continue;
            }

            $legacyOnly = array_filter(
                $selfSufficient,
                fn (array $r): bool => $this->isLegacyOnly($r),
            );
            if (count($legacyOnly) === count($selfSufficient)) {
                $coverage[$organId] = self::COVERAGE_STALE;
                $stale[] = $organId;

                continue;
            }

            // only live (non-legacy) records prove coverage; legacy must not donate evidence_classes
            $liveRecords = array_values(array_diff_key($selfSufficient, $legacyOnly));
            $missingClasses = $this->missingEvidenceClasses($liveRecords);
            if ($missingClasses !== []) {
                $coverage[$organId] = self::COVERAGE_THIN;
                $thin[] = ['organ_id' => $organId, 'missing_evidence_classes' => $missingClasses];

                continue;
            }
            $coverage[$organId] = self::COVERAGE_COVERED;
        }

        $blockers = [];
        foreach ($missing as $organId) {
            $blockers[] = 'missing_organ:'.$organId;
        }
        foreach ($thin as $row) {
            $blockers[] = 'thin_organ:'.$row['organ_id'];
        }
        foreach ($stale as $organId) {
            $blockers[] = 'stale_organ:'.$organId;
        }
        foreach ($blocked as $organId) {
            $blockers[] = 'blocked_organ:'.$organId;
        }

        $passed = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $passed ? 'covered' : 'incomplete',
            'passed' => $passed,
            'inspected_count' => count($records),
            'organ_coverage' => $coverage,
            'missing_organs' => $missing,
            'thin_organs' => $thin,
            'stale_organs' => $stale,
            'blocked_organs' => $blocked,
            'blockers' => $blockers,
            'proof_summary' => sprintf(
                'organs=%d covered=%d missing=%d thin=%d stale=%d blocked=%d',
                count($organs),
                count(array_filter($coverage, static fn (string $v): bool => $v === self::COVERAGE_COVERED)),
                count($missing),
                count($thin),
                count($stale),
                count($blocked),
            ),
        ];
    }

    /**
     * Audit final external-brain coverage grouped by lane.
     *
     * Each lane gets a deterministic coverage_pct = covered_organs / total_organs_in_lane.
     * A lane with zero organs defined is absent (coverage_pct=0.0, lane_absent=true) — never a hidden pass.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,list<string>>|null  $lanesOverride  Organ IDs per lane; defaults to FINAL_BRAIN_LANES
     * @return array<string,mixed>
     */
    public function auditByLane(array $records, ?array $lanesOverride = null): array
    {
        $lanes = $lanesOverride ?? self::FINAL_BRAIN_LANES;
        $laneResults = [];
        $allPassed = true;

        foreach ($lanes as $lane => $organIds) {
            if ($organIds === []) {
                $laneResults[$lane] = [
                    'lane' => $lane,
                    'organ_ids' => [],
                    'covered' => [],
                    'missing' => [],
                    'thin' => [],
                    'stale' => [],
                    'coverage_pct' => 0.0,
                    'passed' => false,
                    'lane_absent' => true,
                ];
                $allPassed = false;

                continue;
            }

            $covered = [];
            $missing = [];
            $thin = [];
            $stale = [];

            foreach ($organIds as $organId) {
                $matched = $this->matchesForOrgan($records, $organId, [$organId]);
                if ($matched === []) {
                    $missing[] = $organId;

                    continue;
                }
                $selfSufficient = array_values(array_filter($matched, fn (array $r): bool => $this->isSelfSufficient($r)));
                if ($selfSufficient === []) {
                    $stale[] = $organId;

                    continue;
                }
                $legacyOnly = array_filter($selfSufficient, fn (array $r): bool => $this->isLegacyOnly($r));
                if (count($legacyOnly) === count($selfSufficient)) {
                    $stale[] = $organId;

                    continue;
                }
                $liveRecords = array_values(array_diff_key($selfSufficient, $legacyOnly));
                if ($this->missingEvidenceClasses($liveRecords) !== []) {
                    $thin[] = $organId;

                    continue;
                }
                $covered[] = $organId;
            }

            $total = count($organIds);
            $coveragePct = $total > 0 ? round(count($covered) / $total, 4) : 0.0;
            $lanePassed = $missing === [] && $thin === [] && $stale === [];
            if (! $lanePassed) {
                $allPassed = false;
            }

            $laneResults[$lane] = [
                'lane' => $lane,
                'organ_ids' => $organIds,
                'covered' => $covered,
                'missing' => $missing,
                'thin' => $thin,
                'stale' => $stale,
                'coverage_pct' => $coveragePct,
                'passed' => $lanePassed,
                'lane_absent' => false,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'passed' => $allPassed,
            'lanes' => $laneResults,
        ];
    }

    /**
     * Produce structured gap entries grouped by organ, lane, dependency wave, and maturity risk.
     *
     * Each gap includes a reason code: unimplemented (missing), untested (thin),
     * blocked, or stale_knowledge (stale), plus the latest evidence ref from
     * any matching record.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  list<array<string,mixed>>  $organMeta  organ entries with optional lane/dependency_wave/maturity_risk;
     *                                                 if empty, falls back to organsOverride / organMap
     * @return array<string,mixed>
     */
    public function auditGaps(array $records, array $organMeta = []): array
    {
        if ($organMeta !== []) {
            $organs = $organMeta;
        } elseif ($this->organsOverride !== null) {
            $organs = $this->organsOverride;
        } else {
            $organMapData = ($this->organMap ?? new AtlasSelfConstructionFinalOrganMap)->describe();
            $organs = (array) $organMapData['organs'];
        }

        $gaps = [];
        foreach ($organs as $organ) {
            $organId = (string) ($organ['organ_id'] ?? '');
            if ($organId === '') {
                continue;
            }
            $tags = array_values(array_map('strval', (array) ($organ['required_task_tags'] ?? [$organId])));
            $matched = $this->matchesForOrgan($records, $organId, $tags);

            if ($matched === []) {
                $coverageStatus = self::COVERAGE_MISSING;
            } else {
                $selfSufficient = array_values(array_filter($matched, fn (array $r): bool => $this->isSelfSufficient($r)));
                if ($selfSufficient === []) {
                    $hasBlocked = array_filter(
                        $matched,
                        static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), self::NON_SELF_SUFFICIENT_STATUSES, true),
                    );
                    $coverageStatus = $hasBlocked !== [] ? self::COVERAGE_BLOCKED : self::COVERAGE_STALE;
                } else {
                    $legacyOnly = array_filter($selfSufficient, fn (array $r): bool => $this->isLegacyOnly($r));
                    if (count($legacyOnly) === count($selfSufficient)) {
                        $coverageStatus = self::COVERAGE_STALE;
                    } else {
                        $liveRecords = array_values(array_diff_key($selfSufficient, $legacyOnly));
                        $coverageStatus = $this->missingEvidenceClasses($liveRecords) !== [] ? self::COVERAGE_THIN : self::COVERAGE_COVERED;
                    }
                }
            }

            if ($coverageStatus === self::COVERAGE_COVERED) {
                continue;
            }

            $lane = (string) ($organ['lane'] ?? '');
            $dependencyWave = isset($organ['dependency_wave']) ? (int) $organ['dependency_wave'] : null;
            $maturityRisk = (string) ($organ['maturity_risk'] ?? '');
            $gaps[] = [
                'organ_id' => $organId,
                'reason' => self::GAP_REASON_CODES[$coverageStatus] ?? $coverageStatus,
                'lane' => $lane,
                'dependency_wave' => $dependencyWave,
                'maturity_risk' => $maturityRisk,
                'latest_evidence_ref' => $this->latestEvidenceRef($matched),
            ];
        }

        $byLane = [];
        $byWave = [];
        $byMaturityRisk = [];
        $reasonCodes = [];
        foreach ($gaps as $gap) {
            if ($gap['lane'] !== '') {
                $byLane[$gap['lane']][] = $gap['organ_id'];
            }
            if ($gap['dependency_wave'] !== null) {
                $byWave[(string) $gap['dependency_wave']][] = $gap['organ_id'];
            }
            if ($gap['maturity_risk'] !== '') {
                $byMaturityRisk[$gap['maturity_risk']][] = $gap['organ_id'];
            }
            $reasonCodes[$gap['reason']][] = $gap['organ_id'];
        }
        ksort($byLane, SORT_STRING);
        ksort($byWave, SORT_STRING);
        ksort($byMaturityRisk, SORT_STRING);
        ksort($reasonCodes, SORT_STRING);

        return [
            'schema_version' => self::GAPS_SCHEMA,
            'gaps' => $gaps,
            'gap_count' => count($gaps),
            'by_lane' => $byLane,
            'by_wave' => $byWave,
            'by_maturity_risk' => $byMaturityRisk,
            'reason_codes' => $reasonCodes,
        ];
    }

    /**
     * Final go/no-go readiness verdict across all lanes — the answer to "can the organ map be
     * declared final right now". A lane/organ can only contribute final_ready=true when it has a
     * LIVE (non-legacy, non-stale) record carrying every required evidence class; completed_dry_run
     * or metadata.legacy_only records never satisfy it on their own, matching audit()/auditByLane().
     *
     * Pure, facts-only — no scalar score; final_ready_reason is a deterministic, human-readable
     * concatenation of every blocking fact found, in lane/organ array order.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,list<string>>|null  $lanesOverride  Organ IDs per lane; defaults to FINAL_BRAIN_LANES
     * @return array{schema:string, final_ready:bool, final_ready_reason:?string, lane_coverage:array<string,float>, blocked_by_lane:array<string,list<string>>, next_missing_evidence_class:?string, productive_vs_stale:array{productive:int,stale:int}}
     */
    public function auditFinalReadiness(array $records, ?array $lanesOverride = null): array
    {
        $lanes = $lanesOverride ?? self::FINAL_BRAIN_LANES;

        $laneCoverage = [];
        $blockedByLane = [];
        $nextMissingEvidenceClass = null;
        $finalReady = true;
        $reasons = [];

        foreach ($lanes as $lane => $organIds) {
            if ($organIds === []) {
                $laneCoverage[$lane] = 0.0;
                $blockedByLane[$lane] = [];
                $finalReady = false;
                $reasons[] = sprintf("lane '%s' is absent (zero organs defined)", $lane);

                continue;
            }

            $covered = 0;
            $blockedOrgans = [];

            foreach ($organIds as $organId) {
                $matched = $this->matchesForOrgan($records, $organId, [$organId]);
                if ($matched === []) {
                    $blockedOrgans[] = $organId;
                    $finalReady = false;
                    $reasons[] = sprintf("organ '%s' in lane '%s' has no matching record", $organId, $lane);

                    continue;
                }

                $selfSufficient = array_values(array_filter($matched, fn (array $r): bool => $this->isSelfSufficient($r)));
                if ($selfSufficient === []) {
                    $blockedOrgans[] = $organId;
                    $finalReady = false;
                    $reasons[] = sprintf("organ '%s' in lane '%s' has no self-sufficient record (blocked or stale)", $organId, $lane);

                    continue;
                }

                $legacyOnly = array_filter($selfSufficient, fn (array $r): bool => $this->isLegacyOnly($r));
                if (count($legacyOnly) === count($selfSufficient)) {
                    $blockedOrgans[] = $organId;
                    $finalReady = false;
                    $reasons[] = sprintf("organ '%s' in lane '%s' only has legacy/stale evidence (completed_dry_run or legacy_only)", $organId, $lane);

                    continue;
                }

                $liveRecords = array_values(array_diff_key($selfSufficient, $legacyOnly));
                $missingClasses = $this->missingEvidenceClasses($liveRecords);
                if ($missingClasses !== []) {
                    $blockedOrgans[] = $organId;
                    $finalReady = false;
                    if ($nextMissingEvidenceClass === null) {
                        $nextMissingEvidenceClass = $missingClasses[0];
                    }
                    $reasons[] = sprintf("organ '%s' in lane '%s' missing evidence class '%s'", $organId, $lane, $missingClasses[0]);

                    continue;
                }

                $covered++;
            }

            $laneCoverage[$lane] = round($covered / count($organIds), 4);
            $blockedByLane[$lane] = $blockedOrgans;
        }

        $productive = 0;
        $stale = 0;
        foreach ($records as $record) {
            if ($this->isLegacyOnly($record)) {
                $stale++;
            } else {
                $productive++;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'final_ready' => $finalReady,
            'final_ready_reason' => $finalReady ? null : implode('; ', $reasons),
            'lane_coverage' => $laneCoverage,
            'blocked_by_lane' => $blockedByLane,
            'next_missing_evidence_class' => $nextMissingEvidenceClass,
            'productive_vs_stale' => ['productive' => $productive, 'stale' => $stale],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @param  list<string>  $tags
     * @return list<array<string,mixed>>
     */
    private function matchesForOrgan(array $records, string $organId, array $tags): array
    {
        $matches = [];
        foreach ($records as $record) {
            $packet = is_array($record['task_packet'] ?? null) ? $record['task_packet'] : [];
            $packetTags = array_values(array_map('strval', (array) ($packet['tags'] ?? [])));
            $packetOrgan = (string) ($packet['organ_id'] ?? '');
            // Match strictly on the organ id — sharing only the generic 'self_construction' tag
            // is NOT enough; the packet must carry the organ's own id either as a tag or as
            // explicit organ_id metadata.
            if (in_array($organId, $packetTags, true) || $packetOrgan === $organId) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function isSelfSufficient(array $record): bool
    {
        $status = (string) ($record['status'] ?? '');
        if (in_array($status, self::NON_SELF_SUFFICIENT_STATUSES, true)) {
            return false;
        }
        $packet = is_array($record['task_packet'] ?? null) ? $record['task_packet'] : [];
        $acceptance = (array) ($packet['acceptance_criteria'] ?? []);
        $evidence = (array) ($packet['required_evidence'] ?? []);

        return $acceptance !== [] && $evidence !== [];
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function isLegacyOnly(array $record): bool
    {
        $status = (string) ($record['status'] ?? '');
        if ($status === 'completed_dry_run') {
            return true;
        }
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];

        return (bool) ($metadata['legacy_only'] ?? false);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return list<string>
     */
    private function missingEvidenceClasses(array $records): array
    {
        $observed = [];
        foreach ($records as $record) {
            $packet = is_array($record['task_packet'] ?? null) ? $record['task_packet'] : [];
            $classes = array_map('strval', (array) ($packet['evidence_classes'] ?? []));
            $observed = array_merge($observed, $classes);
        }
        $observedSet = array_flip($observed);
        $missing = [];
        foreach (self::REQUIRED_EVIDENCE_CLASSES as $class) {
            if (! isset($observedSet[$class])) {
                $missing[] = $class;
            }
        }

        return $missing;
    }

    /**
     * Extract the latest non-empty evidence_hash or evidence_ref from matched records.
     *
     * @param  list<array<string,mixed>>  $records
     */
    private function latestEvidenceRef(array $records): ?string
    {
        foreach (array_reverse($records) as $rec) {
            $ref = (string) ($rec['evidence_hash'] ?? $rec['evidence_ref'] ?? '');
            if ($ref !== '') {
                return $ref;
            }
            $packet = is_array($rec['task_packet'] ?? null) ? $rec['task_packet'] : [];
            $ref = (string) ($packet['evidence_hash'] ?? $packet['evidence_ref'] ?? '');
            if ($ref !== '') {
                return $ref;
            }
        }

        return null;
    }
}
