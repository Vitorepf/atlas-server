<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasOperation;
use App\Models\AtlasRequirement;
use App\Models\AtlasSddDriftReport;
use App\Models\AtlasSddTask;
use App\Models\AtlasSpec;
use App\Models\AtlasSpecTraceability;

/**
 * Compares spec, plan, tasks, traceability and evidence to detect drift.
 *
 * Drift types per drift-detector-and-learning.md:102-111:
 *   - code changed without spec
 *   - spec changed without test
 *   - test covers behavior without requirement
 *   - endpoint exists without contract
 *   - business rule exists only in code
 *   - design-system token violated
 *   - Decision Receipt allowed X but patch did Y
 *   - evidence missing for implemented requirement
 *
 * Output schema: atlas.sdd_drift.v1
 */
class SpecDriftDetector
{
    /**
     * @return array<string,mixed>  the persisted DriftReport payload (atlas.sdd_drift.v1)
     */
    public function inspect(AtlasSpec $spec, ?AtlasOperation $operation = null, string $source = 'manual'): array
    {
        $findings = [];

        $findings = array_merge($findings, $this->checkRequirementsHaveAcceptance($spec));
        $findings = array_merge($findings, $this->checkRequirementsHaveTraceability($spec));
        $findings = array_merge($findings, $this->checkTasksHaveAcceptanceRefs($spec));
        $findings = array_merge($findings, $this->checkApprovedSpecHasPlan($spec));
        $findings = array_merge($findings, $this->checkEvidenceForImplementedRequirements($spec));

        $status = $this->statusFromFindings($findings);
        $recommendedAction = $this->recommendAction($findings);

        $payload = [
            'schema_version' => 'atlas.sdd_drift.v1',
            'status' => $status,
            'spec_id' => $spec->id,
            'findings' => $findings,
            'recommended_action' => $recommendedAction,
        ];

        AtlasSddDriftReport::query()->create([
            'spec_id' => $spec->id,
            'operation_id' => $operation?->id,
            'status' => $status,
            'drift_findings_json' => $findings,
            'summary_json' => [
                'total_findings' => count($findings),
                'recommended_action' => $recommendedAction,
            ],
            'source' => $source,
        ]);

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     */
    private function statusFromFindings(array $findings): string
    {
        if ($findings === []) {
            return 'pass';
        }
        foreach ($findings as $f) {
            if (($f['severity'] ?? 'warn') === 'fail') {
                return 'fail';
            }
        }

        return 'warn';
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     */
    private function recommendAction(array $findings): string
    {
        if ($findings === []) {
            return 'none';
        }
        foreach ($findings as $f) {
            $type = (string) ($f['type'] ?? '');
            if (in_array($type, ['decision_receipt_diverged', 'business_rule_only_in_code'], true)) {
                return 'create_spec';
            }
            if (in_array($type, ['spec_changed_without_test', 'task_without_acceptance_ref'], true)) {
                return 'update_test';
            }
            if ($type === 'evidence_missing_for_implemented_requirement') {
                return 'repair';
            }
        }

        return 'proposal';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function checkRequirementsHaveAcceptance(AtlasSpec $spec): array
    {
        $findings = [];
        $reqs = $spec->requirements()->with('acceptanceCriteria')->get();
        foreach ($reqs as $req) {
            if ($req->acceptanceCriteria->isEmpty()) {
                $findings[] = [
                    'type' => 'requirement_without_acceptance',
                    'severity' => 'fail',
                    'requirement_code' => $req->code,
                    'detail' => 'requirement has no acceptance criteria',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function checkRequirementsHaveTraceability(AtlasSpec $spec): array
    {
        $findings = [];
        $traceMap = AtlasSpecTraceability::query()
            ->where('spec_id', $spec->id)
            ->get()
            ->groupBy('requirement_id');

        foreach ($spec->requirements as $req) {
            if (! $traceMap->has($req->id)) {
                $findings[] = [
                    'type' => 'requirement_without_traceability',
                    'severity' => 'warn',
                    'requirement_code' => $req->code,
                    'detail' => 'no traceability record links this requirement to a task or file',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function checkTasksHaveAcceptanceRefs(AtlasSpec $spec): array
    {
        $findings = [];
        $tasks = AtlasSddTask::query()->where('spec_id', $spec->id)->get();
        foreach ($tasks as $task) {
            if ((array) $task->acceptance_refs_json === [] && $task->type !== 'test') {
                $findings[] = [
                    'type' => 'task_without_acceptance_ref',
                    'severity' => 'warn',
                    'task_code' => $task->code,
                    'detail' => 'implementation task does not reference any acceptance criterion',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function checkApprovedSpecHasPlan(AtlasSpec $spec): array
    {
        if ($spec->status !== 'approved' && $spec->status !== 'implemented') {
            return [];
        }
        $hasPlan = $spec->plans()->exists();
        if (! $hasPlan) {
            return [[
                'type' => 'approved_spec_without_plan',
                'severity' => 'fail',
                'detail' => 'spec is approved/implemented but has no plan',
            ]];
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function checkEvidenceForImplementedRequirements(AtlasSpec $spec): array
    {
        $findings = [];
        $traceMap = AtlasSpecTraceability::query()
            ->where('spec_id', $spec->id)
            ->whereNotNull('requirement_id')
            ->get()
            ->groupBy('requirement_id');

        foreach ($spec->requirements as $req) {
            $records = $traceMap->get($req->id, collect());
            $hasEvidence = $records->contains(static fn (AtlasSpecTraceability $t): bool => ! empty($t->evidence_event_id));
            if (in_array($req->status, ['done', 'implemented'], true) && ! $hasEvidence) {
                $findings[] = [
                    'type' => 'evidence_missing_for_implemented_requirement',
                    'severity' => 'fail',
                    'requirement_code' => $req->code,
                    'detail' => 'requirement marked implemented but no evidence event linked',
                ];
            }
        }

        return $findings;
    }
}
