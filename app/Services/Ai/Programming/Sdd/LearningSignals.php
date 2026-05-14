<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasOperation;
use App\Models\AtlasSddDriftReport;
use App\Models\AtlasSddLearningProposal;
use App\Services\Ai\Programming\Sdd\Pipeline\ExecutionResult;

/**
 * Proposal-only learning service.
 *
 * Hard law (drift-detector-and-learning.md:137-138):
 *   Learning can update templates after approval. It cannot silently alter
 *   Kernel, Policy, provider routing, memory truth or runtime critical
 *   behaviour.
 *
 * Output: AtlasSddLearningProposal rows in `proposed` status awaiting human
 * review. Never auto-applied.
 */
class LearningSignals
{
    /**
     * @param  array<string,mixed>  $driftReport
     */
    public function proposeIfUseful(
        AtlasOperation $operation,
        ExecutionResult $execution,
        array $driftReport,
        ?AtlasSddDriftReport $driftRow = null,
    ): ?AtlasSddLearningProposal {
        $findings = (array) ($driftReport['findings'] ?? []);
        if ($findings === []) {
            return null;
        }

        $repeatedFindingTypes = $this->repeatedFindingTypes($findings);
        if ($repeatedFindingTypes === []) {
            return null;
        }

        $type = $repeatedFindingTypes[0];

        return AtlasSddLearningProposal::query()->create([
            'operation_id' => $operation->id,
            'drift_report_id' => $driftRow?->id,
            'proposal_type' => $type,
            'summary' => $this->summaryForType($type, count($findings)),
            'observation_json' => [
                'finding_types' => array_count_values(array_column($findings, 'type')),
                'execution_status' => $execution->status,
            ],
            'proposal_json' => $this->proposalForType($type),
            'evidence_refs_json' => array_values(array_filter([
                $driftRow?->id ? "drift_report:{$driftRow->id}" : null,
                "operation:{$operation->id}",
                ...array_map(static fn (string $ref): string => "exec:{$ref}", $execution->evidenceRefs),
            ])),
            'status' => 'proposed',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<string>
     */
    private function repeatedFindingTypes(array $findings): array
    {
        $counts = array_count_values(array_filter(array_map(
            static fn (array $f): ?string => isset($f['type']) ? (string) $f['type'] : null,
            $findings,
        )));
        $repeated = [];
        foreach ($counts as $type => $n) {
            if ($n >= 2) {
                $repeated[] = $type;
            }
        }
        if ($repeated !== []) {
            return $repeated;
        }
        // Single finding can still be a candidate when the type is high-leverage.
        $highLeverage = array_intersect(array_keys($counts), [
            'evidence_missing_for_implemented_requirement',
            'approved_spec_without_plan',
            'business_rule_only_in_code',
        ]);

        return array_values($highLeverage);
    }

    private function summaryForType(string $type, int $occurrences): string
    {
        return match ($type) {
            'requirement_without_acceptance' => "{$occurrences} requirements lack acceptance criteria — propose template hardening.",
            'requirement_without_traceability' => "{$occurrences} requirements have no traceability — propose plan-task generator update.",
            'task_without_acceptance_ref' => "{$occurrences} tasks miss acceptance refs — propose TaskCompiler enhancement.",
            'evidence_missing_for_implemented_requirement' => "{$occurrences} implemented requirements have no evidence — propose evidence ledger gate.",
            'approved_spec_without_plan' => 'Approved spec without plan — propose plan-on-approve hook.',
            default => "Repeated drift finding `{$type}` ({$occurrences} occurrences) — propose policy review.",
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function proposalForType(string $type): array
    {
        return [
            'kind' => match (true) {
                str_contains($type, 'template') => 'template_update',
                $type === 'evidence_missing_for_implemented_requirement' => 'gate_addition',
                default => 'policy_review',
            },
            'requires_human_review' => true,
            'allowed_targets' => ['template', 'policy_proposal'],
            'forbidden_targets' => ['kernel', 'provider_routing', 'memory_truth', 'critical_runtime'],
        ];
    }
}
