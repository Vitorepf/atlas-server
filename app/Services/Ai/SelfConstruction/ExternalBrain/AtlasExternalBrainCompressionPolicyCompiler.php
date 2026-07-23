<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure proof-backed policy compiler: turns per-action proof/contract/rollback/risk FACTS into
 * an executable admission decision — never a passive checklist or static document.
 *
 * DECISION (first matching rule wins, per action):
 *   1. deny            — contract_preserved === false (a preserved contract would break).
 *   2. deny            — risk_level === 'high' AND proof_available === false. High-risk
 *                         actions without proof are DENIED outright, never merely warned —
 *                         a warning that still lets the action through is not a policy.
 *   3. require_prework — proof_available === false (any other risk level): gather proof first.
 *   4. require_prework — rollback_evidence_available === false: prove a rollback path first.
 *   5. allow           — every fact checks out.
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainCompressionPolicyCompiler
{
    public const SCHEMA = 'atlas.external_brain.compression_policy_compiler.v1';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_REQUIRE_PREWORK = 'require_prework';

    public const DECISION_DENY = 'deny';

    public const REASON_CONTRACT_VIOLATION = 'contract_violation';

    public const REASON_HIGH_RISK_WITHOUT_PROOF = 'high_risk_without_proof';

    public const REASON_MISSING_PROOF = 'missing_proof';

    public const REASON_MISSING_ROLLBACK_EVIDENCE = 'missing_rollback_evidence';

    private const VALID_RISK_LEVELS = ['low', 'medium', 'high'];

    /**
     * @param  array{actions?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $actions = is_array($input['actions'] ?? null) ? $input['actions'] : [];

        $decisions = [];
        $counts = [self::DECISION_ALLOW => 0, self::DECISION_REQUIRE_PREWORK => 0, self::DECISION_DENY => 0];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $decision = $this->decideOne($action);
            $decisions[] = $decision;
            $counts[$decision['decision']]++;
        }

        return [
            'schema'    => self::SCHEMA,
            'decisions' => $decisions,
            'summary'   => [
                'total'            => count($decisions),
                'allow_count'      => $counts[self::DECISION_ALLOW],
                'require_prework_count' => $counts[self::DECISION_REQUIRE_PREWORK],
                'deny_count'       => $counts[self::DECISION_DENY],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function decideOne(array $action): array
    {
        $actionId              = (string) ($action['action_id'] ?? '');
        $riskLevel              = strtolower(trim((string) ($action['risk_level'] ?? 'medium')));
        if (! in_array($riskLevel, self::VALID_RISK_LEVELS, true)) {
            $riskLevel = 'medium';
        }
        $proofAvailable        = (bool) ($action['proof_available'] ?? false);
        $contractPreserved     = (bool) ($action['contract_preserved'] ?? false);
        $rollbackEvidenceAvailable = (bool) ($action['rollback_evidence_available'] ?? false);

        [$decision, $reason] = match (true) {
            ! $contractPreserved => [self::DECISION_DENY, self::REASON_CONTRACT_VIOLATION],
            $riskLevel === 'high' && ! $proofAvailable => [self::DECISION_DENY, self::REASON_HIGH_RISK_WITHOUT_PROOF],
            ! $proofAvailable => [self::DECISION_REQUIRE_PREWORK, self::REASON_MISSING_PROOF],
            ! $rollbackEvidenceAvailable => [self::DECISION_REQUIRE_PREWORK, self::REASON_MISSING_ROLLBACK_EVIDENCE],
            default => [self::DECISION_ALLOW, null],
        };

        return [
            'action_id'                   => $actionId,
            'decision'                    => $decision,
            'reason'                      => $reason,
            'risk_level'                  => $riskLevel,
            'proof_available'             => $proofAvailable,
            'contract_preserved'          => $contractPreserved,
            'rollback_evidence_available' => $rollbackEvidenceAvailable,
        ];
    }
}
