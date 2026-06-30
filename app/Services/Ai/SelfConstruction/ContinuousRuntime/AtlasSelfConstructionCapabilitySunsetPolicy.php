<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure policy: decides whether an autonomous self-construction capability should be
 * kept, frozen, merged into a replacement, or retired.
 *
 * Priority (first match wins):
 *   1. replacement_owner_id non-empty AND replacement_owner_ready=true  → merge
 *   2. value_proof < 3 AND usage < 3 AND maintenance_cost > 7
 *         + is_critical with no safety_evidence                         → keep (refused retire)
 *         + otherwise                                                    → retire
 *   3. value_proof < 3 (any other case)                                 → freeze
 *   4. default                                                          → keep
 *
 * refused=true means retirement was requested by the signal pattern but blocked by
 * the critical-capability safety gate.
 */
final class AtlasSelfConstructionCapabilitySunsetPolicy
{
    public const SCHEMA = 'atlas.self_construction.capability_sunset_policy.v1';

    public const DECISION_KEEP = 'keep';

    public const DECISION_FREEZE = 'freeze';

    public const DECISION_MERGE = 'merge';

    public const DECISION_RETIRE = 'retire';

    public const VALUE_LOW_THRESHOLD = 3.0;

    public const USAGE_LOW_THRESHOLD = 3.0;

    public const COST_HIGH_THRESHOLD = 7.0;

    /** @var array<string,list<string>> */
    private const SAFETY_CHECKS = [
        self::DECISION_RETIRE => ['confirm_no_active_consumers', 'confirm_replacement_tested'],
        self::DECISION_MERGE => ['confirm_migration_tested', 'confirm_replacement_owner_covers_behaviors'],
        self::DECISION_FREEZE => ['confirm_no_active_development'],
        self::DECISION_KEEP => [],
    ];

    /**
     * @param  array<string,mixed>  $capability  value_proof, usage_frequency, maintenance_cost,
     *                                            replacement_owner_id, replacement_owner_ready,
     *                                            is_critical, safety_evidence
     * @return array{schema_version:string, decision:string, refused:bool, reasons:list<string>, required_safety_checks:list<string>}
     */
    public function evaluate(array $capability): array
    {
        $valueProof = (float) ($capability['value_proof'] ?? 0.0);
        $usageFreq = (float) ($capability['usage_frequency'] ?? 0.0);
        $maintCost = (float) ($capability['maintenance_cost'] ?? 0.0);
        $replacementOwner = (string) ($capability['replacement_owner_id'] ?? '');
        $replacementReady = (bool) ($capability['replacement_owner_ready'] ?? false);
        $isCritical = (bool) ($capability['is_critical'] ?? false);
        $safetyEvidence = array_values(array_filter(
            array_map('strval', (array) ($capability['safety_evidence'] ?? [])),
            static fn (string $e): bool => $e !== '',
        ));

        $refused = false;
        $reasons = [];

        if ($replacementOwner !== '' && $replacementReady) {
            $decision = self::DECISION_MERGE;
            $reasons[] = 'replacement_owner_ready';
        } elseif ($valueProof < self::VALUE_LOW_THRESHOLD && $usageFreq < self::USAGE_LOW_THRESHOLD && $maintCost > self::COST_HIGH_THRESHOLD) {
            if ($isCritical && $safetyEvidence === []) {
                $decision = self::DECISION_KEEP;
                $reasons[] = 'critical_no_replacement';
                $reasons[] = 'retirement_refused';
                $refused = true;
            } else {
                $decision = self::DECISION_RETIRE;
                $reasons[] = 'low_value_proof';
                $reasons[] = 'low_usage';
                $reasons[] = 'high_maintenance_cost';
            }
        } elseif ($valueProof < self::VALUE_LOW_THRESHOLD) {
            $decision = self::DECISION_FREEZE;
            $reasons[] = 'low_value_proof';
        } else {
            $decision = self::DECISION_KEEP;
            $reasons[] = 'sufficient_value';
        }

        return [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'refused' => $refused,
            'reasons' => $reasons,
            'required_safety_checks' => self::SAFETY_CHECKS[$decision],
        ];
    }
}
