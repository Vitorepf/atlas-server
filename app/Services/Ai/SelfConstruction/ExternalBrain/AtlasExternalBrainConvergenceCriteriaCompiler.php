<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure compiler that produces 95-percent readiness convergence criteria requiring
 * proof-backed evidence across autonomy, queue quality, outcome learning,
 * simplification, and model-amplifier dimensions.
 *
 * Maturity claims without evidence receipts are marked unproven.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainConvergenceCriteriaCompiler
{
    public const SCHEMA = 'atlas.external_brain.convergence_criteria.v1';

    public const CRITERIA_GROUPS = [
        'autonomy',
        'queue_quality',
        'outcome_learning',
        'simplification',
        'model_amplifier',
    ];

    /**
     * @param  array<string,array{maturity_claim?:string,evidence_receipt?:string}>  $dimensions
     * @return array{
     *   schema:string,
     *   all_proven:bool,
     *   groups:array<string,array{required:bool,proven:bool,proof_field:string,claim:string,receipt:?string}>,
     *   unproven:list<string>,
     * }
     */
    public function compile(array $dimensions): array
    {
        $groups = [];
        $unproven = [];

        foreach (self::CRITERIA_GROUPS as $group) {
            $entry = $dimensions[$group] ?? [];
            $claim = (string) ($entry['maturity_claim'] ?? 'not_declared');
            $receipt = $entry['evidence_receipt'] ?? null;
            $hasReceipt = is_string($receipt) && $receipt !== '';

            $proven = $hasReceipt && $claim !== 'not_declared';

            $groups[$group] = [
                'required' => true,
                'proven' => $proven,
                'proof_field' => $group . '.evidence_receipt',
                'claim' => $claim,
                'receipt' => $hasReceipt ? $receipt : null,
            ];

            if (! $proven) {
                $unproven[] = $group;
            }
        }

        $allProven = $unproven === [];

        return [
            'schema' => self::SCHEMA,
            'all_proven' => $allProven,
            'groups' => $groups,
            'unproven' => $unproven,
        ];
    }
}
