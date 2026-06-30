<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GoalValue;

/**
 * FACTS-only value contract for Self-Construction work.
 *
 * Defines 7 named value dimensions. Each dimension is satisfied only by a structured fact carrying
 * concrete evidence (a non-empty `evidence_refs` list and a permitted `evidence_kind`). Anti-Goodhart
 * dimensions like task_count, line_churn, green_self_report, and cosmetic_docs are REJECTED — they
 * never count as sufficient value evidence by themselves.
 *
 * Output (FACTS only): {schema_version, real_leverage, dimensions:[{id,passed,reason}], proxy_only, blockers}
 *
 * NO scalar hype score, no ranking, no aggregate magnitude.
 */
final class AtlasGoalValueRealLeverageContract
{
    public const SCHEMA = 'atlas.self_construction.real_leverage_contract.v1';

    public const DIM_CAPABILITY_LIFT = 'capability_lift';

    public const DIM_AUTONOMY_LIFT = 'autonomy_lift';

    public const DIM_FAILURE_REMOVAL = 'failure_removal';

    public const DIM_QUALITY_HARDENING = 'quality_hardening';

    public const DIM_SPEED_LEVERAGE = 'speed_leverage';

    public const DIM_SIMPLIFICATION = 'simplification';

    public const DIM_MULTI_PROJECT_REUSE = 'multi_project_reuse';

    public const VALUE_DIMENSIONS = [
        self::DIM_CAPABILITY_LIFT,
        self::DIM_AUTONOMY_LIFT,
        self::DIM_FAILURE_REMOVAL,
        self::DIM_QUALITY_HARDENING,
        self::DIM_SPEED_LEVERAGE,
        self::DIM_SIMPLIFICATION,
        self::DIM_MULTI_PROJECT_REUSE,
    ];

    public const REJECTED_EVIDENCE_KINDS = [
        'task_count',
        'line_churn',
        'green_self_report',
        'cosmetic_docs',
    ];

    /**
     * @param  array<string, array{evidence_kind?:string, evidence_refs?:list<string>, fact?:array<string,mixed>}|null>  $dimensionEvidence
     * @return array<string,mixed>
     */
    public function evaluate(array $dimensionEvidence): array
    {
        $dimResults = [];
        $passCount = 0;
        $proxyOnly = true;
        $blockers = [];

        foreach (self::VALUE_DIMENSIONS as $dim) {
            $row = $dimensionEvidence[$dim] ?? null;
            $result = $this->evaluateDimension($dim, is_array($row) ? $row : []);
            $dimResults[] = $result;
            if ($result['passed']) {
                $passCount++;
                if (! in_array($result['evidence_kind'] ?? '', self::REJECTED_EVIDENCE_KINDS, true)) {
                    $proxyOnly = false;
                }
            } else {
                $blockers[] = $result['reason'];
            }
        }

        $realLeverage = $passCount > 0 && ! $proxyOnly;
        if (! $realLeverage && $passCount === 0) {
            $blockers[] = 'no_dimension_passed';
        }

        return [
            'schema_version' => self::SCHEMA,
            'real_leverage' => $realLeverage,
            'proxy_only' => $proxyOnly,
            'dimensions' => $dimResults,
            'passed_count' => $passCount,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array{id:string, passed:bool, reason:string, evidence_kind:?string}
     */
    private function evaluateDimension(string $dimId, array $evidence): array
    {
        $kind = isset($evidence['evidence_kind']) ? (string) $evidence['evidence_kind'] : null;
        $refs = (array) ($evidence['evidence_refs'] ?? []);

        if ($kind === null || $kind === '') {
            return ['id' => $dimId, 'passed' => false, 'reason' => $dimId.':no_evidence', 'evidence_kind' => null];
        }
        if (in_array($kind, self::REJECTED_EVIDENCE_KINDS, true)) {
            return ['id' => $dimId, 'passed' => false, 'reason' => $dimId.':rejected_proxy_kind:'.$kind, 'evidence_kind' => $kind];
        }
        if ($refs === []) {
            return ['id' => $dimId, 'passed' => false, 'reason' => $dimId.':missing_evidence_refs', 'evidence_kind' => $kind];
        }

        $beforeFact = $evidence['before_fact'] ?? null;
        $afterFact = $evidence['after_fact'] ?? null;
        if ($beforeFact === null || $beforeFact === '' || $afterFact === null || $afterFact === '') {
            return ['id' => $dimId, 'passed' => false, 'reason' => $dimId.':missing_outcome_delta', 'evidence_kind' => $kind];
        }

        return ['id' => $dimId, 'passed' => true, 'reason' => $dimId.':accepted', 'evidence_kind' => $kind];
    }
}
