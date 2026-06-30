<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Facts-only sentinel that proves the live claimable queue carries no legacy simplicity-contract
 * gaps. Wraps {@see AtlasTaskSimplicityContractAuditor}, classifies the audit findings, and emits
 * an actionable, scalar-free envelope for final autonomy gates.
 *
 * Read-only by construction: accepts an injected record list (records the caller already loaded
 * from {@see AgentControlPlaneTaskPacketQueueRepository::list()}) and never mutates queue state.
 */
final class AtlasTaskSimplicityContractSentinel
{
    public const SCHEMA = 'atlas.task_serving.simplicity_contract_sentinel.v1';

    public const SAMPLE_LIMIT = 10;

    public function __construct(private readonly AtlasTaskSimplicityContractAuditor $auditor)
    {
    }

    /**
     * Evaluate a batch of live queue spec records for queue-level defects.
     * Returns facts only — no scalar score, no mutations.
     *
     * Defects detected:
     *  - broad_scope         : tasks with > OVER_BROAD_FILE_THRESHOLD allowed_files
     *  - test_only           : tasks where all allowed_files are test paths (singleton-test theater)
     *  - no_impl_file        : tasks with explicitly empty allowed_files (no target at all)
     *  - repeated_objectives : sets of tasks sharing an identical objective (template-farm repetition)
     *
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function evaluateBatch(array $records): array
    {
        $audit = $this->auditor->audit($records);
        $findings = (array) ($audit['findings'] ?? []);

        $broadScope = [];
        $testOnly = [];
        $noImplFile = [];

        foreach ($findings as $f) {
            $type = (string) ($f['finding_type'] ?? '');
            $id = (string) ($f['task_packet_id'] ?? '');
            match ($type) {
                'over_broad_allowed_files' => $broadScope[] = $id,
                'test_only_task' => $testOnly[] = $id,
                'missing_implementation_target' => $noImplFile[] = $id,
                default => null,
            };
        }

        // Cross-spec: repeated objective text → template-farm repetition.
        $objectiveMap = [];
        foreach ($records as $record) {
            $packet = is_array($record['task_packet'] ?? null) ? $record['task_packet'] : $record;
            $id = (string) ($packet['task_packet_id'] ?? '');
            $obj = trim((string) ($packet['objective'] ?? ''));
            if ($obj !== '') {
                $objectiveMap[$obj][] = $id;
            }
        }
        $repeatedObjectives = array_values(
            array_filter(array_values($objectiveMap), static fn (array $ids): bool => count($ids) > 1)
        );

        return [
            'schema_version' => self::SCHEMA,
            'broad_scope' => array_values(array_unique($broadScope)),
            'test_only' => array_values(array_unique($testOnly)),
            'no_impl_file' => array_values(array_unique($noImplFile)),
            'repeated_objectives' => $repeatedObjectives,
            'defect_count' => count($broadScope) + count($testOnly) + count($noImplFile) + count($repeatedObjectives),
            'mutates_queue' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function check(array $records): array
    {
        $audit = $this->auditor->audit($records);
        $inspected = (int) ($audit['inspected_count'] ?? 0);
        $missing = (int) ($audit['missing_count'] ?? 0);
        $drift = (int) ($audit['drift_count'] ?? 0);
        $findings = (array) ($audit['findings'] ?? []);

        $blockers = [];
        if ($missing > 0) {
            $blockers[] = 'simplicity_contract_missing_in_claimable_packets';
        }
        if ($drift > 0) {
            $blockers[] = 'simplicity_contract_drifted_in_claimable_packets';
        }

        $failures = array_values(array_filter(
            $findings,
            static fn (array $finding): bool => in_array(
                (string) ($finding['status'] ?? ''),
                [AtlasTaskSimplicityContractAuditor::STATUS_MISSING, AtlasTaskSimplicityContractAuditor::STATUS_DRIFTED],
                true,
            ),
        ));
        $sampleFindings = array_slice($failures, 0, self::SAMPLE_LIMIT);

        $passed = $blockers === [];

        return [
            'schema_version' => self::SCHEMA,
            'status' => $passed ? 'pass' : 'fail',
            'passed' => $passed,
            'inspected_count' => $inspected,
            'missing_count' => $missing,
            'drift_count' => $drift,
            'blockers' => $blockers,
            'sample_findings' => $sampleFindings,
            'proof_summary' => [
                'audit_schema' => (string) ($audit['schema_version'] ?? AtlasTaskSimplicityContractAuditor::SCHEMA),
                'records_total' => (int) ($audit['proof_summary']['records_total'] ?? count($records)),
                'failure_count' => count($failures),
                'sample_limit' => self::SAMPLE_LIMIT,
                'mutates_queue' => false,
            ],
        ];
    }
}
