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

    // Real evidence types: prove an actual behavior/decision/quality change happened.
    public const PROOF_BEHAVIOR_PROOF = 'behavior_proof';
    public const PROOF_REGRESSION_PROOF = 'regression_proof';
    public const PROOF_RUNTIME_DECISION_CHANGE = 'runtime_decision_change';
    public const PROOF_MEASURABLE_QUEUE_QUALITY_IMPROVEMENT = 'measurable_queue_quality_improvement';

    public const ACCEPTED_REAL_PROOF_TYPES = [
        self::PROOF_BEHAVIOR_PROOF,
        self::PROOF_REGRESSION_PROOF,
        self::PROOF_RUNTIME_DECISION_CHANGE,
        self::PROOF_MEASURABLE_QUEUE_QUALITY_IMPROVEMENT,
    ];

    // Proxy evidence types: prove something compiles/runs/exists, never that behavior changed.
    public const PROOF_CLASS_EXISTS = 'class_exists';
    public const PROOF_SCHEMA_ONLY = 'schema_only';
    public const PROOF_WRAPPER_EXIT_ZERO = 'wrapper_exit_zero';
    public const PROOF_TEST_PRESENCE = 'test_presence';

    public const REJECTED_PROXY_PROOF_TYPES = [
        self::PROOF_CLASS_EXISTS,
        self::PROOF_SCHEMA_ONLY,
        self::PROOF_WRAPPER_EXIT_ZERO,
        self::PROOF_TEST_PRESENCE,
    ];

    private const BASE_BY_CLASS = [
        'command' => self::PROOF_COMMAND_SMOKE,
        'queue'   => self::PROOF_QUEUE_HEALTH,
        'doc'     => self::PROOF_DOC_PROPOSAL,
        'feature' => self::PROOF_FEATURE_TEST,
        'runtime' => self::PROOF_RUNTIME_RECEIPT,
    ];

    private const MINIMUM_EVIDENCE_DESCRIPTION = [
        self::PROOF_UNIT_TEST => 'A unit test that asserts a concrete, behavior-specific outcome (not just that the class exists).',
        self::PROOF_FEATURE_TEST => 'A feature test exercising the real code path end-to-end, asserting the changed behavior.',
        self::PROOF_COMMAND_SMOKE => 'A runnable artisan/CLI command transcript showing the command actually executes the new behavior.',
        self::PROOF_QUEUE_HEALTH => 'A before/after queue-health metric proving a measurable queue-quality improvement.',
        self::PROOF_COLLISION_SWEEP => 'A collision sweep confirming the change does not regress sibling callers.',
        self::PROOF_DOC_PROPOSAL => 'A doc proposal cross-checked against the canonical source it describes.',
        self::PROOF_RUNTIME_RECEIPT => 'A runtime receipt proving a real runtime decision changed as a result of this work.',
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

        $minimumRequiredEvidence = [];
        foreach ($requiredProofs as $proofType) {
            $minimumRequiredEvidence[$proofType] = self::MINIMUM_EVIDENCE_DESCRIPTION[$proofType]
                ?? 'A runnable proof that the change produced a real, observable behavior difference.';
        }

        return [
            'schema_version' => self::SCHEMA,
            'required_proofs' => $requiredProofs,
            'implementation_notes_sufficient' => $noteSufficient,
            'minimum_proof_rationale' => $rationale,
            'risk_level' => $riskLevel,
            'target_class' => $targetClass,
            'minimum_required_evidence' => [
                'task_family' => $targetClass,
                'evidence_by_proof_type' => $minimumRequiredEvidence,
            ],
        ];
    }

    /**
     * Judges a single submitted proof type: real evidence is accepted, a
     * proxy ("looks done") signal is rejected outright regardless of risk
     * level or any other context.
     *
     * @return array{accepted:bool,is_proxy:bool,reason:string}
     */
    public function verifySubmittedProof(string $proofType): array
    {
        if (in_array($proofType, self::REJECTED_PROXY_PROOF_TYPES, true)) {
            return [
                'accepted' => false,
                'is_proxy' => true,
                'reason' => "\"{$proofType}\" is a proxy signal (compiles/exists/runs) — it never proves the targeted behavior actually changed.",
            ];
        }

        if (in_array($proofType, self::ACCEPTED_REAL_PROOF_TYPES, true)) {
            return [
                'accepted' => true,
                'is_proxy' => false,
                'reason' => "\"{$proofType}\" demonstrates a real behavior, decision, or measurable quality change.",
            ];
        }

        return [
            'accepted' => false,
            'is_proxy' => false,
            'reason' => "\"{$proofType}\" is not a recognized proof type; it cannot be accepted without classification.",
        ];
    }
}
