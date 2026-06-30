<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure admission gate. Admits model-amplified proposals into the Task Fabric
 * only after passing all per-proposal and batch-level checks.
 *
 * Input facts:
 *   proposals      — list of {id, is_compliance_compliant, is_normalized, replay_cleared,
 *                    is_duplicate, task_fabric_ready}.
 *   batch_min_size — minimum admitted proposals for batch to proceed (default 1).
 *   batch_max_size — maximum proposals that may be submitted in one batch (default 20).
 *
 * AC2 — Per-proposal rejection checks (in order):
 *   1. compliance_check   : is_compliance_compliant !== true.
 *   2. normalization_check: is_normalized           !== true.
 *   3. replay_check       : replay_cleared          !== true.
 *   4. deduplication_check: is_duplicate            === true.
 *   5. task_fabric_check  : task_fabric_ready       !== true.
 *
 * AC3 — admit=true only when:
 *   - admitted count >= batch_min_size, AND
 *   - submitted count <= batch_max_size, AND
 *   - every admitted proposal passed all five checks.
 *
 * batch_action:
 *   'submit'          — admit=true, proceed.
 *   'hold_for_repair' — some admitted but batch below min, or some repairable rejections.
 *   'discard'         — all rejected or batch_max_size exceeded with no admittable set.
 *
 * AC4 outputs: admit, admitted_proposals, rejected_proposals, blocking_checks,
 *   batch_action.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasTaskFabricAmplifiedProposalAdmissionGate
{
    public const SCHEMA = 'atlas.task_fabric.amplified_proposal_admission_gate.v1';

    private const DEFAULT_BATCH_MIN = 1;
    private const DEFAULT_BATCH_MAX = 20;

    private const CHECKS = [
        'compliance_check'    => ['field' => 'is_compliance_compliant', 'expect' => true,  'invert' => false],
        'normalization_check' => ['field' => 'is_normalized',           'expect' => true,  'invert' => false],
        'replay_check'        => ['field' => 'replay_cleared',          'expect' => true,  'invert' => false],
        'deduplication_check' => ['field' => 'is_duplicate',            'expect' => false, 'invert' => true],
        'task_fabric_check'   => ['field' => 'task_fabric_ready',       'expect' => true,  'invert' => false],
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $proposals  = is_array($facts['proposals'] ?? null) ? $facts['proposals'] : [];
        $batchMin   = max(1, (int) ($facts['batch_min_size'] ?? self::DEFAULT_BATCH_MIN));
        $batchMax   = max(1, (int) ($facts['batch_max_size'] ?? self::DEFAULT_BATCH_MAX));

        $admitted         = [];
        $rejected         = [];
        $blockingCheckSet = [];
        $totalSubmitted   = count($proposals);

        // Hard-fail if batch submission itself exceeds maximum.
        $batchMaxExceeded = $totalSubmitted > $batchMax;

        foreach ($proposals as $proposal) {
            $id     = (string) ($proposal['id'] ?? '');
            $failed = [];

            foreach (self::CHECKS as $checkName => $spec) {
                $raw  = $proposal[$spec['field']] ?? null;
                $bool = (bool) $raw;

                // For non-inverted checks: fail when value is NOT true.
                // For inverted checks (is_duplicate): fail when value IS true.
                $fails = $spec['invert'] ? $bool : ! $bool;

                if ($fails) {
                    $failed[] = $checkName;
                    $blockingCheckSet[$checkName] = true;
                }
            }

            if (empty($failed) && ! $batchMaxExceeded) {
                $admitted[] = $id;
            } else {
                if ($batchMaxExceeded && empty($failed)) {
                    $failed[] = 'batch_max_exceeded';
                    $blockingCheckSet['batch_max_exceeded'] = true;
                }
                $rejected[] = ['id' => $id, 'failed_checks' => $failed];
            }
        }

        $admittedCount = count($admitted);
        $batchMinMet   = $admittedCount >= $batchMin;
        $admit         = $admittedCount > 0 && $batchMinMet && ! $batchMaxExceeded;

        $batchAction = $this->batchAction($admit, $admittedCount, $batchMinMet, $rejected);

        return [
            'schema_version'     => self::SCHEMA,
            'admit'              => $admit,
            'admitted_proposals' => $admitted,
            'rejected_proposals' => $rejected,
            'blocking_checks'    => array_values(array_keys($blockingCheckSet)),
            'batch_action'       => $batchAction,
        ];
    }

    private function batchAction(bool $admit, int $admittedCount, bool $batchMinMet, array $rejected): string
    {
        if ($admit) {
            return 'submit';
        }
        if ($admittedCount > 0 && ! $batchMinMet) {
            return 'hold_for_repair'; // some admitted but not enough
        }
        if (! empty($rejected) && $admittedCount === 0) {
            return 'discard';
        }

        return 'hold_for_repair';
    }
}
