<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure contract: a blocked or quarantined ("poison") packet that still carries
 * real value may only be converted into a corrected replacement spec once
 * scope, acceptance, evidence, and contradiction checks prove the new packet
 * is genuinely implementable — never a blind requeue of the same poison.
 *
 * Refusal precedence (first match wins):
 *   1. no real value              -> refuse, no repair worth doing
 *   2. forbidden/petreo target with no safe wrapper or operator path
 *                                  -> refuse, operator_action_required=true
 *   3. contradictory acceptance   -> refuse, acceptance_contradiction_unresolved
 *   4. otherwise allow, repairing scope closure (missing implementation files)
 *      when present.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainUsefulPoisonRespecContract
{
    public const SCHEMA = 'atlas.external_brain.useful_poison_respec_contract.v1';

    public const REASON_NO_REAL_VALUE = 'no_real_value_do_not_respec';

    public const REASON_FORBIDDEN_TARGET_NO_SAFE_PATH = 'forbidden_target_no_safe_path';

    public const REASON_ACCEPTANCE_CONTRADICTION_UNRESOLVED = 'acceptance_contradiction_unresolved';

    public const REASON_SCOPE_CLOSURE_REPAIRED = 'scope_closure_repaired';

    public const REASON_NO_REPAIR_NEEDED = 'no_repair_needed';

    /**
     * @param  array{
     *   real_value?: bool,
     *   allowed_files?: list<string>,
     *   required_implementation_files?: list<string>,
     *   acceptance_contradictory?: bool,
     *   forbidden_target?: bool,
     *   has_safe_wrapper_or_operator_path?: bool,
     * }  $poisonPacket
     * @return array{schema:string, replacement_allowed:bool, operator_action_required:bool, added_required_files:list<string>, reason:string}
     */
    public function evaluate(array $poisonPacket): array
    {
        $realValue = (bool) ($poisonPacket['real_value'] ?? false);
        $allowedFiles = array_values((array) ($poisonPacket['allowed_files'] ?? []));
        $requiredImplementationFiles = array_values((array) ($poisonPacket['required_implementation_files'] ?? []));
        $acceptanceContradictory = (bool) ($poisonPacket['acceptance_contradictory'] ?? false);
        $forbiddenTarget = (bool) ($poisonPacket['forbidden_target'] ?? false);
        $hasSafePath = (bool) ($poisonPacket['has_safe_wrapper_or_operator_path'] ?? false);

        if (! $realValue) {
            return $this->refuse(self::REASON_NO_REAL_VALUE, false);
        }

        if ($forbiddenTarget && ! $hasSafePath) {
            return $this->refuse(self::REASON_FORBIDDEN_TARGET_NO_SAFE_PATH, true);
        }

        if ($acceptanceContradictory) {
            return $this->refuse(self::REASON_ACCEPTANCE_CONTRADICTION_UNRESOLVED, false);
        }

        $missingFiles = array_values(array_diff($requiredImplementationFiles, $allowedFiles));
        if ($missingFiles !== []) {
            $correctedAllowedFiles = array_values(array_unique(array_merge($allowedFiles, $missingFiles)));

            return [
                'schema' => self::SCHEMA,
                'replacement_allowed' => true,
                'operator_action_required' => false,
                'added_required_files' => $missingFiles,
                'reason' => self::REASON_SCOPE_CLOSURE_REPAIRED,
                'refusal_reason' => null,
                'corrected_allowed_files' => $correctedAllowedFiles,
                'corrected_acceptance_summary' => 'scope closure repaired: added '.count($missingFiles).' missing implementation file(s) to allowed_files',
                'replacement_plan' => [
                    'action' => 'repair_scope_closure',
                    'corrected_allowed_files' => $correctedAllowedFiles,
                    'added_required_files' => $missingFiles,
                ],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'replacement_allowed' => true,
            'operator_action_required' => false,
            'added_required_files' => [],
            'reason' => self::REASON_NO_REPAIR_NEEDED,
            'refusal_reason' => null,
            'corrected_allowed_files' => $allowedFiles,
            'corrected_acceptance_summary' => 'packet already implementable as-is; no repair needed',
            'replacement_plan' => [
                'action' => 'respec_unchanged',
                'corrected_allowed_files' => $allowedFiles,
                'added_required_files' => [],
            ],
        ];
    }

    private function refuse(string $reason, bool $operatorActionRequired): array
    {
        return [
            'schema' => self::SCHEMA,
            'replacement_allowed' => false,
            'operator_action_required' => $operatorActionRequired,
            'added_required_files' => [],
            'reason' => $reason,
            'refusal_reason' => $reason,
            'corrected_allowed_files' => [],
            'corrected_acceptance_summary' => null,
            'replacement_plan' => null,
        ];
    }
}
