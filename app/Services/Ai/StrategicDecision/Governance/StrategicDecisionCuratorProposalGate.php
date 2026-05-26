<?php

declare(strict_types=1);

namespace App\Services\Ai\StrategicDecision\Governance;

/**
 * Strategic Decision — QL-5 Curator Proposal Gate.
 *
 * Closes the matrix gap "Strategic Decision: QL-5 curator/mutation e
 * simulacoes robustas". Honours the canon guardrail explicitly: "Curator
 * nao deve mutar sistema sem Proposal Inbox/review".
 *
 * QL-5 (Qualitative Level 5) is the highest tier of curator activity in
 * the Atlas Strategic Decision domain. At QL-5 the curator is allowed to
 * propose system mutations (e.g., autonomy promotion, new mission types,
 * provider preference shifts) but NEVER applies them directly. This
 * gate is the single chokepoint: every mutation proposal must pass it
 * before reaching the human-review Inbox.
 */
final class StrategicDecisionCuratorProposalGate
{
    public const SCHEMA_VERSION = 'atlas.strategic_decision.curator_proposal_gate.v1';

    public const REQUIRED_FIELDS = [
        'proposal_id',
        'qualitative_level',
        'mutation_class',
        'evidence_refs',
        'rollback_plan',
        'simulation_summary',
        'review_required_actors',
    ];

    public const ALLOWED_MUTATION_CLASSES = [
        'autonomy_promotion',
        'mission_type_addition',
        'provider_preference_shift',
        'gate_threshold_change',
        'curriculum_extension',
    ];

    public const FORBIDDEN_AUTO_APPLY = true;

    /**
     * @param  array<string,mixed>  $proposal
     * @return array{
     *   schema_version: string,
     *   proposal_id: ?string,
     *   gate_decision: string,
     *   qualitative_level: ?int,
     *   mutation_class: ?string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   review_required_actors: list<string>,
     *   auto_apply_allowed: bool,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $proposal): array
    {
        $passed = [];
        $failed = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $proposal[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $failed[$field] = "required field '{$field}' missing or empty";
            } else {
                $passed[] = $field.'_present';
            }
        }

        $ql = $proposal['qualitative_level'] ?? null;
        if (! is_int($ql) || $ql < 1 || $ql > 5) {
            $failed['qualitative_level'] = 'must be int 1..5 (Atlas QL ladder)';
        } else {
            $passed[] = 'qualitative_level_in_range';
        }

        $mutationClass = (string) ($proposal['mutation_class'] ?? '');
        if ($mutationClass !== '' && ! in_array($mutationClass, self::ALLOWED_MUTATION_CLASSES, true)) {
            $failed['mutation_class'] = sprintf(
                'mutation_class "%s" not in allowed list [%s]',
                $mutationClass,
                implode(',', self::ALLOWED_MUTATION_CLASSES),
            );
        } elseif ($mutationClass !== '') {
            $passed[] = 'mutation_class_valid';
        }

        // QL-5 requires AT LEAST 2 review actors (canon: never single-reviewer mutation)
        $actors = (array) ($proposal['review_required_actors'] ?? []);
        if ($ql === 5 && count($actors) < 2) {
            $failed['review_required_actors'] = 'QL-5 mutation proposals require >= 2 reviewer actors (no single-reviewer rule)';
        } elseif ($ql === 5 && count($actors) >= 2) {
            $passed[] = 'ql5_multi_reviewer_enforced';
        }

        // Evidence refs must include simulation backing for ANY mutation
        $evidenceRefs = (array) ($proposal['evidence_refs'] ?? []);
        $hasSimulationEvidence = false;
        foreach ($evidenceRefs as $ref) {
            if (is_array($ref) && (($ref['kind'] ?? null) === 'simulation' || str_contains((string) ($ref['ref'] ?? ''), 'simulation'))) {
                $hasSimulationEvidence = true;

                break;
            }
        }
        if (! $hasSimulationEvidence) {
            $failed['evidence_refs.simulation'] = 'mutation proposals require simulation evidence backing';
        } else {
            $passed[] = 'simulation_evidence_present';
        }

        // Refuse any flag claiming auto-apply allowed — never legitimate.
        if (($proposal['auto_apply_claimed'] ?? null) === true) {
            $failed['auto_apply_claimed'] = 'auto-apply is FORBIDDEN at every QL; proposal must go to Inbox';
        }

        $decision = $failed === [] ? 'queued_for_review' : 'rejected_at_gate';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'proposal_id' => isset($proposal['proposal_id']) && is_string($proposal['proposal_id']) ? $proposal['proposal_id'] : null,
            'gate_decision' => $decision,
            'qualitative_level' => is_int($ql) ? $ql : null,
            'mutation_class' => $mutationClass !== '' ? $mutationClass : null,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'review_required_actors' => array_values(array_filter($actors, 'is_string')),
            'auto_apply_allowed' => false, // hard canonical invariant
            'detail' => $decision === 'queued_for_review'
                ? sprintf('Proposal "%s" passed gate; queued for human-review Inbox.', $proposal['proposal_id'] ?? 'unknown')
                : sprintf('Proposal rejected: %d check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
