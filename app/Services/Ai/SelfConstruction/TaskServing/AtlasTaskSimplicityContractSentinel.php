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
