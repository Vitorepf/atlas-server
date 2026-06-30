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

    public const COVERAGE_COVERED = 'covered';

    public const COVERAGE_THIN = 'thin';

    public const COVERAGE_STALE = 'stale';

    public const COVERAGE_MISSING = 'missing';

    public const COVERAGE_BLOCKED = 'blocked';

    /** @var list<string> */
    private const REQUIRED_EVIDENCE_CLASSES = ['implementation', 'gate', 'receipt', 'cli_or_readiness'];

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
}
