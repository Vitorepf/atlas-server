<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S91 · L7 Runtime Completion — Architecture Evolution Proposal ADMISSION.
 *
 * Admits STRUCTURAL self-refactor proposals (Atlas changing its own architecture)
 * ONLY when the proposal carries an invariant registry, a rollback plan and BOTH
 * an operator and an architect review. This is the admission gate, not the
 * applier: self-evolution admission is loop-implementable, but structural
 * redesign remains receipt-gated — `admit()` NEVER applies a proposal and NEVER
 * produces an apply side effect, even when fully approved.
 *
 * Pure decision: every returned field is computed from the `$proposal` input via
 * real rules (no I/O, DB, clock, randomness, provider calls). Identical input
 * yields identical output.
 *
 * Ordered admission rules (first failing rule sets the blocking reasons):
 *   1. structural    — non-structural proposals are not self-refactor and block.
 *   2. invariant     — a structural refactor with no invariant registry blocks.
 *   3. rollback      — a structural refactor with no rollback plan blocks.
 *   4. signatures    — invariant+rollback present but a missing operator OR
 *                      architect signature returns pending_human_review and
 *                      cannot apply (dual signature required).
 *   5. admitted      — structural + invariant + rollback + dual signature.
 *
 * Regardless of status, `apply_side_effect` is ALWAYS false (admission never
 * mutates anything) and `can_apply` is true ONLY for an `admitted` proposal.
 */
final class ArchitectureEvolutionProposalAdmissionService
{
    public const SCHEMA = 'atlas.architecture_evolution.proposal.v1';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PENDING_HUMAN_REVIEW = 'pending_human_review';

    public const STATUS_ADMITTED = 'admitted';

    public const BLOCKER_NOT_STRUCTURAL_SELF_REFACTOR = 'not_structural_self_refactor';

    public const BLOCKER_MISSING_INVARIANT_REGISTRY = 'missing_invariant_registry';

    public const BLOCKER_MISSING_ROLLBACK_PLAN = 'missing_rollback_plan';

    public const BLOCKER_MISSING_OPERATOR_SIGNATURE = 'missing_operator_signature';

    public const BLOCKER_MISSING_ARCHITECT_SIGNATURE = 'missing_architect_signature';

    /**
     * @param  array<string,mixed>  $proposal
     * @return array{
     *     schema_version: string,
     *     invariant_scope: list<string>,
     *     affected_layers: list<string>,
     *     rollback_plan: array<string,mixed>,
     *     operator_signature: bool,
     *     architect_signature: bool,
     *     admission_status: string,
     *     blockers: list<string>,
     *     can_apply: bool,
     *     apply_side_effect: bool,
     * }
     */
    public function admit(array $proposal): array
    {
        $isStructural = $this->isStructuralSelfRefactor($proposal);
        $invariantScope = $this->invariantScope($proposal);
        $affectedLayers = $this->affectedLayers($proposal);
        $rollbackPlan = $this->rollbackPlan($proposal);
        $hasRollback = $this->hasRollbackPlan($rollbackPlan);
        $operatorSignature = $this->hasSignature($proposal, 'operator_signature', 'operator_signed');
        $architectSignature = $this->hasSignature($proposal, 'architect_signature', 'architect_signed');

        $blockers = [];

        // Rule 1 — only structural self-refactor proposals are admissible here.
        if (! $isStructural) {
            $blockers[] = self::BLOCKER_NOT_STRUCTURAL_SELF_REFACTOR;
        }

        // Rule 2 — a structural refactor with no invariant registry blocks.
        if ($invariantScope === []) {
            $blockers[] = self::BLOCKER_MISSING_INVARIANT_REGISTRY;
        }

        // Rule 3 — a structural refactor with no rollback plan blocks.
        if (! $hasRollback) {
            $blockers[] = self::BLOCKER_MISSING_ROLLBACK_PLAN;
        }

        // Rule 4 — dual signature: BOTH operator and architect review are required.
        if (! $operatorSignature) {
            $blockers[] = self::BLOCKER_MISSING_OPERATOR_SIGNATURE;
        }
        if (! $architectSignature) {
            $blockers[] = self::BLOCKER_MISSING_ARCHITECT_SIGNATURE;
        }

        $admissionStatus = $this->resolveStatus($blockers, $operatorSignature, $architectSignature);
        $canApply = $admissionStatus === self::STATUS_ADMITTED;

        return [
            'schema_version' => self::SCHEMA,
            'invariant_scope' => $invariantScope,
            'affected_layers' => $affectedLayers,
            'rollback_plan' => $rollbackPlan,
            'operator_signature' => $operatorSignature,
            'architect_signature' => $architectSignature,
            'admission_status' => $admissionStatus,
            'blockers' => $blockers,
            // Authorization to apply requires the FULLY admitted state (dual
            // signature included). Missing a signature => cannot apply.
            'can_apply' => $canApply,
            // This service NEVER applies a proposal. Even an admitted proposal has
            // no apply side effect — structural redesign stays receipt-gated.
            'apply_side_effect' => false,
        ];
    }

    /**
     * The only status that is NOT blocked yet still not admitted is the case where
     * every structural/invariant/rollback requirement is met and the SOLE missing
     * piece is a signature — that returns pending_human_review (and cannot apply).
     * Any other blocker is a hard structural/registry/rollback block.
     *
     * @param  list<string>  $blockers
     */
    private function resolveStatus(array $blockers, bool $operatorSignature, bool $architectSignature): string
    {
        if ($blockers === []) {
            return self::STATUS_ADMITTED;
        }

        $signatureBlockers = [
            self::BLOCKER_MISSING_OPERATOR_SIGNATURE,
            self::BLOCKER_MISSING_ARCHITECT_SIGNATURE,
        ];

        $onlySignatureMissing = ! ($operatorSignature && $architectSignature);
        foreach ($blockers as $blocker) {
            if (! in_array($blocker, $signatureBlockers, true)) {
                $onlySignatureMissing = false;
                break;
            }
        }

        return $onlySignatureMissing
            ? self::STATUS_PENDING_HUMAN_REVIEW
            : self::STATUS_BLOCKED;
    }

    /**
     * A proposal is a structural self-refactor when it explicitly declares itself
     * structural (kind/proposal_type/change_class) AND targets Atlas itself
     * (self-refactor flag) OR names at least one affected architectural layer.
     *
     * @param  array<string,mixed>  $proposal
     */
    private function isStructuralSelfRefactor(array $proposal): bool
    {
        $kind = strtolower(trim((string) (
            $proposal['proposal_type']
            ?? $proposal['kind']
            ?? $proposal['change_class']
            ?? ''
        )));

        $declaredStructural = in_array($kind, ['structural', 'structural_self_refactor', 'self_refactor', 'architecture'], true)
            || ($proposal['structural'] ?? false) === true;

        $selfRefactor = ($proposal['self_refactor'] ?? false) === true
            || ($proposal['targets_self'] ?? false) === true
            || $this->affectedLayers($proposal) !== [];

        return $declaredStructural && $selfRefactor;
    }

    /**
     * The invariant registry: the sacred invariants this refactor must preserve.
     *
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function invariantScope(array $proposal): array
    {
        return $this->stringList(
            $proposal['invariant_scope']
            ?? $proposal['invariant_registry']
            ?? $proposal['invariants']
            ?? []
        );
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function affectedLayers(array $proposal): array
    {
        return $this->stringList(
            $proposal['affected_layers']
            ?? $proposal['layers']
            ?? []
        );
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    private function rollbackPlan(array $proposal): array
    {
        $plan = $proposal['rollback_plan'] ?? [];

        return is_array($plan) ? $plan : [];
    }

    /**
     * A rollback plan exists when it carries at least one concrete, non-empty step
     * or instruction — an empty array is not a plan.
     *
     * @param  array<string,mixed>  $rollbackPlan
     */
    private function hasRollbackPlan(array $rollbackPlan): bool
    {
        foreach ($rollbackPlan as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $proposal
     */
    private function hasSignature(array $proposal, string $objectKey, string $boolKey): bool
    {
        $object = $proposal[$objectKey] ?? null;
        if (is_array($object)) {
            return ($object['signed'] ?? false) === true
                && trim((string) ($object['signer'] ?? '')) !== '';
        }

        if ($object === true) {
            return true;
        }

        return ($proposal[$boolKey] ?? false) === true;
    }

    /**
     * Coerce a value into a strict list<string>: drop non-strings and empty
     * strings, and re-index with array_values so no int keys leak into the list.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn ($item): string => is_string($item) ? trim($item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
