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

    /** Small governed ceiling on blast_radius_limit for a reversible self-modification experiment. */
    public const BLAST_RADIUS_CEILING = 25;

    /** Placeholder tokens that make an evidence_ref or verification command non-real proof. */
    private const PLACEHOLDER_PATTERN = '/\b(todo|tbd|fake|example|synthetic-demo|placeholder|n\/a|none)\b/i';

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
            } elseif ($this->allPlaceholder($evidenceRefs)) {
                // Non-empty, but every ref is a placeholder token (todo/tbd/fake/example/...) — no
                // real receipt/hash-like proof was actually bound. A single real ref among placeholders
                // is enough to pass; only an ALL-placeholder list is treated as no evidence at all.
                $blockers[] = 'reversible_experiment_placeholder_evidence_refs';
            }
            $canaryPlan = is_array($experiment['canary_plan'] ?? null) ? $experiment['canary_plan'] : [];
            if ($canaryPlan === []) {
                $blockers[] = 'reversible_experiment_missing_canary_plan';
            }

            $blastRadiusLimit = $experiment['blast_radius_limit'] ?? null;
            if ($blastRadiusLimit === null) {
                $blockers[] = 'reversible_experiment_missing_blast_radius_limit';
            } elseif (! $this->isBoundedPositiveInt($blastRadiusLimit)) {
                $blockers[] = 'reversible_experiment_invalid_blast_radius_limit';
            } elseif ((int) $blastRadiusLimit > self::BLAST_RADIUS_CEILING) {
                $blockers[] = 'reversible_experiment_blast_radius_limit_exceeds_ceiling';
            }

            $rollbackVerifyCmd = trim((string) ($experiment['rollback_verification_command'] ?? ''));
            if ($rollbackVerifyCmd === '') {
                $blockers[] = 'reversible_experiment_missing_rollback_verification_command';
            } elseif (! $this->looksLikeRunnableVerificationCommand($rollbackVerifyCmd)) {
                $blockers[] = 'reversible_experiment_weak_rollback_verification_command';
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

    /** @param  list<string>  $evidenceRefs */
    private function allPlaceholder(array $evidenceRefs): bool
    {
        foreach ($evidenceRefs as $ref) {
            if (preg_match(self::PLACEHOLDER_PATTERN, $ref) !== 1) {
                return false; // at least one real ref found
            }
        }

        return true;
    }

    private function isBoundedPositiveInt(mixed $value): bool
    {
        if (is_bool($value) || is_array($value)) {
            return false;
        }
        if (! is_numeric($value)) {
            return false;
        }
        $float = (float) $value;
        if ($float !== floor($float)) {
            return false; // non-integer (e.g. 2.5)
        }

        return (int) $float > 0;
    }

    /**
     * Accepts an artisan-style namespaced command (e.g. 'atlas:self:verify-rollback') or a direct
     * php/artisan invocation (e.g. 'php artisan atlas:task test-suite') — rejects placeholder text
     * and free-form prose that names no runnable command at all.
     */
    private function looksLikeRunnableVerificationCommand(string $command): bool
    {
        if (preg_match(self::PLACEHOLDER_PATTERN, $command) === 1) {
            return false;
        }

        if (preg_match('/^php\s+\S+/i', $command) === 1) {
            return true;
        }

        return preg_match('/^[a-z0-9_]+(:[a-z0-9_.-]+)+$/i', $command) === 1;
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
