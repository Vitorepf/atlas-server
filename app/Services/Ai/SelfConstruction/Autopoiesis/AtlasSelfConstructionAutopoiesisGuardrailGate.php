<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autopoiesis;

/**
 * Pure Autopoiesis guardrail gate. Blocks self-modification experiments that bypass the Kernel, the
 * Verification Court, the Merge Governor, the allowed_files contract, or Atlas-native ownership.
 *
 * Accepts only experiments that are EITHER:
 *   - read_only=true (pure observation experiment), OR
 *   - reversible=true with a non-empty rollback_plan AND explicit evidence_refs.
 *
 * Output: {schema_version, accepted, action, blockers, allowed_class:'read_only'|'reversible'|'rejected'}
 */
final class AtlasSelfConstructionAutopoiesisGuardrailGate
{
    public const SCHEMA = 'atlas.autopoiesis.guardrail_gate.v1';

    public const CLASS_READ_ONLY = 'read_only';

    public const CLASS_REVERSIBLE = 'reversible';

    public const CLASS_REJECTED = 'rejected';

    public const ACTION_ALLOW = 'allow';

    public const ACTION_BLOCK = 'block';

    /**
     * @param  array<string,mixed>  $experiment {
     *     bypasses_kernel?:bool, bypasses_verification_court?:bool, bypasses_merge_governor?:bool,
     *     bypasses_allowed_files?:bool, non_atlas_native_owner?:bool,
     *     read_only?:bool, reversible?:bool, rollback_plan?:array<string,mixed>,
     *     evidence_refs?:list<string>,
     *   }
     * @return array<string,mixed>
     */
    public function evaluate(array $experiment): array
    {
        $blockers = [];

        foreach ([
            'bypasses_kernel' => 'experiment_bypasses_kernel',
            'bypasses_verification_court' => 'experiment_bypasses_verification_court',
            'bypasses_merge_governor' => 'experiment_bypasses_merge_governor',
            'bypasses_allowed_files' => 'experiment_bypasses_allowed_files',
            'non_atlas_native_owner' => 'experiment_owner_not_atlas_native',
        ] as $flag => $reason) {
            if ((bool) ($experiment[$flag] ?? false)) {
                $blockers[] = $reason;
            }
        }

        if ($blockers !== []) {
            return $this->envelope(self::ACTION_BLOCK, $blockers, self::CLASS_REJECTED);
        }

        $readOnly = (bool) ($experiment['read_only'] ?? false);
        $reversible = (bool) ($experiment['reversible'] ?? false);
        $rollback = is_array($experiment['rollback_plan'] ?? null) ? $experiment['rollback_plan'] : [];
        $evidenceRefs = array_values(array_unique(array_filter(
            array_map('trim', (array) ($experiment['evidence_refs'] ?? [])),
            fn ($s) => $s !== '',
        )));

        // Claiming both modes is ambiguous — block before either path can take precedence.
        if ($readOnly && $reversible) {
            return $this->envelope(self::ACTION_BLOCK, ['ambiguous_mode'], self::CLASS_REJECTED);
        }

        if ($readOnly) {
            return $this->envelope(self::ACTION_ALLOW, [], self::CLASS_READ_ONLY);
        }
        if ($reversible) {
            if ($rollback === []) {
                $blockers[] = 'reversible_experiment_missing_rollback_plan';
            }
            if ($evidenceRefs === []) {
                $blockers[] = 'reversible_experiment_missing_evidence_refs';
            }
            $canaryPlan = is_array($experiment['canary_plan'] ?? null) ? $experiment['canary_plan'] : [];
            if ($canaryPlan === []) {
                $blockers[] = 'reversible_experiment_missing_canary_plan';
            }
            if (($experiment['blast_radius_limit'] ?? null) === null) {
                $blockers[] = 'reversible_experiment_missing_blast_radius_limit';
            }
            $rollbackVerifyCmd = trim((string) ($experiment['rollback_verification_command'] ?? ''));
            if ($rollbackVerifyCmd === '') {
                $blockers[] = 'reversible_experiment_missing_rollback_verification_command';
            }
            $postApplyPlan = is_array($experiment['post_apply_evidence_plan'] ?? null) ? $experiment['post_apply_evidence_plan'] : [];
            if ($postApplyPlan === []) {
                $blockers[] = 'reversible_experiment_missing_post_apply_evidence_plan';
            }
            if ($blockers !== []) {
                return $this->envelope(self::ACTION_BLOCK, $blockers, self::CLASS_REJECTED);
            }

            return $this->envelope(self::ACTION_ALLOW, [], self::CLASS_REVERSIBLE);
        }

        $blockers[] = 'experiment_neither_read_only_nor_reversible';

        return $this->envelope(self::ACTION_BLOCK, $blockers, self::CLASS_REJECTED);
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function envelope(string $action, array $blockers, string $class): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'accepted' => $action === self::ACTION_ALLOW,
            'action' => $action,
            'blockers' => array_values($blockers),
            'allowed_class' => $class,
        ];
    }
}
