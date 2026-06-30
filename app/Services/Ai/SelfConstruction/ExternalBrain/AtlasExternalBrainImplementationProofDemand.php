<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure policy: derives the minimum proof a muscle must provide before a task
 * is accepted, proportional to its real risk and value mechanism.
 *
 * Proof types: unit_test, feature_test, command_smoke, queue_health,
 *              collision_sweep, doc_proposal, runtime_receipt
 *
 * Base proof is selected from target_class (or value_mechanism as fallback):
 *   command→command_smoke, queue→queue_health, doc→doc_proposal,
 *   feature→feature_test, runtime→runtime_receipt, default→unit_test
 *
 * Risk escalation:
 *   medium → base + collision_sweep
 *   high   → base + collision_sweep + runtime_receipt
 *
 * property_gated (flag or value_mechanism) → always adds runtime_receipt.
 *
 * implementation_notes_sufficient: false for high-risk or property_gated tasks.
 */
final class AtlasExternalBrainImplementationProofDemand
{
    public const SCHEMA = 'atlas.external_brain.implementation_proof_demand.v1';

    public const PROOF_UNIT_TEST = 'unit_test';
    public const PROOF_FEATURE_TEST = 'feature_test';
    public const PROOF_COMMAND_SMOKE = 'command_smoke';
    public const PROOF_QUEUE_HEALTH = 'queue_health';
    public const PROOF_COLLISION_SWEEP = 'collision_sweep';
    public const PROOF_DOC_PROPOSAL = 'doc_proposal';
    public const PROOF_RUNTIME_RECEIPT = 'runtime_receipt';

    private const BASE_BY_CLASS = [
        'command' => self::PROOF_COMMAND_SMOKE,
        'queue'   => self::PROOF_QUEUE_HEALTH,
        'doc'     => self::PROOF_DOC_PROPOSAL,
        'feature' => self::PROOF_FEATURE_TEST,
        'runtime' => self::PROOF_RUNTIME_RECEIPT,
    ];

    /**
     * @param  array<string,mixed>  $input  task descriptor
     * @return array<string,mixed>
     */
    public function derive(array $input): array
    {
        $task = is_array($input['task'] ?? null) ? $input['task'] : [];
        $riskLevel = (string) ($task['risk_level'] ?? 'low');
        $targetClass = (string) ($task['target_class'] ?? 'logic');
        $valueMechanism = (string) ($task['value_mechanism'] ?? 'logic');
        $isPropertyGated = (bool) ($task['is_property_gated'] ?? false)
            || $valueMechanism === 'property_gated';

        $isHigh = $riskLevel === 'high';
        $isMedium = $riskLevel === 'medium';

        $base = self::BASE_BY_CLASS[$targetClass]
            ?? self::BASE_BY_CLASS[$valueMechanism]
            ?? self::PROOF_UNIT_TEST;

        $proofs = [$base];

        if ($isMedium) {
            $proofs[] = self::PROOF_COLLISION_SWEEP;
        }

        if ($isHigh) {
            $proofs[] = self::PROOF_COLLISION_SWEEP;
            $proofs[] = self::PROOF_RUNTIME_RECEIPT;
        }

        if ($isPropertyGated) {
            $proofs[] = self::PROOF_RUNTIME_RECEIPT;
        }

        $requiredProofs = array_values(array_unique($proofs));
        sort($requiredProofs);

        $noteSufficient = ! $isHigh && ! $isPropertyGated;

        $rationale = match (true) {
            $isHigh && $isPropertyGated => 'high_risk_property_gated: runnable gate mandatory',
            $isHigh                     => 'high_risk: runnable gate mandatory',
            $isPropertyGated            => 'property_gated: runtime receipt mandatory',
            $isMedium                   => 'medium_risk: collision sweep added',
            default                     => 'low_risk: base proof sufficient',
        };

        return [
            'schema_version' => self::SCHEMA,
            'required_proofs' => $requiredProofs,
            'implementation_notes_sufficient' => $noteSufficient,
            'minimum_proof_rationale' => $rationale,
            'risk_level' => $riskLevel,
            'target_class' => $targetClass,
        ];
    }
}
