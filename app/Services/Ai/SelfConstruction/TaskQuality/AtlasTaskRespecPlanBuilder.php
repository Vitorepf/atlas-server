<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Builds a deterministic RESPEC PLAN from hidden-poison FACTS observed on a packet. NEVER edits queue
 * records — returns a proposed respec packet (rationale + affected fields + revalidation gates).
 *
 * INPUT:
 *   { packet_id, hidden_poison_facts:list<string>, missing_files:list<string>,
 *     forbidden_missing_files?:list<string>, contradicting_fields?:list<string>,
 *     too_many_deficiencies?:bool, contradictory_acceptance?:bool, cli_clobber?:bool,
 *     autonomy_regression?:bool }
 *
 * ACTION KINDS (priority-ordered; first match wins):
 *   quarantine_candidate           — too_many_deficiencies OR cli_clobber
 *   rewrite_objective              — contradictory_acceptance
 *   add_missing_allowed_file_candidate — missing_files !== []
 *   split_task_candidate           — autonomy_regression (Atlas-native replacement) OR
 *                                    hidden_poison_facts include 'too_broad_scope'
 *   give_back_hint                 — any other named hidden_poison_facts entry
 *   keep                           — no signal triggered
 *
 * Every plan also carries:
 *   - blocked_reason_mapping : the full static {blocked reason → action} table this builder
 *     uses, so callers/auditors can introspect the decision surface without re-deriving it.
 *   - proof_requirements     : concrete proof the respec author must supply before the action
 *     is trusted (never a vague confirmation).
 *   - rollback_plan          : {description, requires_confirmation} — how to undo the action if
 *     it proves wrong; requires_confirmation=true for the higher-blast-radius actions
 *     (quarantine_candidate, split_task_candidate).
 *   - validation_gates       : alias of revalidation_gates under the AC-facing name.
 *   - status                 : 'ready' unless the action proposes real respec work (anything but
 *     'keep') AND source_evidence or implementation_target is missing, in which case 'not_ready'.
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope.
 *   - PURE.
 *   - autonomy_regression suggestion always becomes an Atlas-native REPLACEMENT action
 *     (split_task_candidate with replaces_authority='non_atlas_native'); never proposes human
 *     dependency.
 */
final class AtlasTaskRespecPlanBuilder
{
    public const SCHEMA = 'atlas.task_quality.respec_plan.v1';

    public const ACTION_KEEP = 'keep';

    public const ACTION_GIVE_BACK = 'give_back_hint';

    public const ACTION_QUARANTINE = 'quarantine_candidate';

    public const ACTION_REWRITE_OBJECTIVE = 'rewrite_objective';

    public const ACTION_ADD_FILE = 'add_missing_allowed_file_candidate';

    public const ACTION_SPLIT = 'split_task_candidate';

    public const STATUS_READY = 'ready';

    public const STATUS_NOT_READY = 'not_ready';

    /** Full static {blocked reason → action} table, surfaced verbatim on every plan. */
    private const BLOCKED_REASON_TO_ACTION = [
        'too_many_deficiencies' => self::ACTION_QUARANTINE,
        'cli_clobber' => self::ACTION_QUARANTINE,
        'forbidden_or_petreo_missing_file' => self::ACTION_QUARANTINE,
        'contradictory_acceptance' => self::ACTION_REWRITE_OBJECTIVE,
        'autonomy_regression' => self::ACTION_SPLIT,
        'too_broad_scope' => self::ACTION_SPLIT,
        'missing_files' => self::ACTION_ADD_FILE,
        'named_hidden_poison' => self::ACTION_GIVE_BACK,
        'no_signal' => self::ACTION_KEEP,
    ];

    /** action => concrete proof the respec author must supply — never a vague confirmation. */
    private const ACTION_TO_PROOF_REQUIREMENTS = [
        self::ACTION_QUARANTINE => ['operator_review_confirmation'],
        self::ACTION_REWRITE_OBJECTIVE => ['acceptance_criteria_non_contradictory_proof', '/opt/homebrew/bin/php artisan test'],
        self::ACTION_ADD_FILE => ['file_exists_proof', '/opt/homebrew/bin/php artisan test'],
        self::ACTION_SPLIT => ['scope_boundary_proof', '/opt/homebrew/bin/php artisan test'],
        self::ACTION_GIVE_BACK => ['poison_fact_resolution_proof'],
        self::ACTION_KEEP => [],
    ];

    /** action => rollback description, if the action turns out wrong. */
    private const ACTION_TO_ROLLBACK_DESCRIPTION = [
        self::ACTION_QUARANTINE => 'revert the quarantine flag and restore the original packet status if the operator overturns it',
        self::ACTION_REWRITE_OBJECTIVE => 'restore the original objective/acceptance_criteria from packet history if the rewrite proves wrong',
        self::ACTION_ADD_FILE => 'remove the added allowed_files entries if the impl/test pair turns out unnecessary',
        self::ACTION_SPLIT => 'discard the split slices and restore the original packet if the Atlas-native replacement fails validation',
        self::ACTION_GIVE_BACK => 'no queue mutation is performed; give_back is itself reversible by re-claiming',
        self::ACTION_KEEP => 'no action taken; nothing to roll back',
    ];

    /** Higher-blast-radius actions whose rollback requires explicit confirmation before applying. */
    private const HIGH_RISK_ROLLBACK_ACTIONS = [self::ACTION_QUARANTINE, self::ACTION_SPLIT];

    /**
     * @param  array{
     *     packet_id?:string,
     *     hidden_poison_facts?:list<string>,
     *     missing_files?:list<string>,
     *     too_many_deficiencies?:bool,
     *     contradictory_acceptance?:bool,
     *     cli_clobber?:bool,
     *     autonomy_regression?:bool
     * }  $facts
     * @return array{schema:string, packet_id:string, action:string, rationale:string, affected_fields:list<string>, revalidation_gates:list<string>, validation_gates:list<string>, blocked_reason_mapping:array<string,string>, proof_requirements:list<string>, rollback_plan:array{description:string,requires_confirmation:bool}, status:string, replaces_authority?:string}
     */
    public function build(array $facts): array
    {
        $id = (string) ($facts['packet_id'] ?? '');
        $poison = is_array($facts['hidden_poison_facts'] ?? null) ? array_values(array_map('strval', $facts['hidden_poison_facts'])) : [];
        $missing = is_array($facts['missing_files'] ?? null) ? array_values(array_map('strval', $facts['missing_files'])) : [];

        $hasSourceEvidence = ! empty($facts['source_evidence'] ?? null);
        $hasImplementationTarget = trim((string) ($facts['implementation_target'] ?? '')) !== '';

        $envelope = function (string $action, string $rationale, array $affected = [], array $gates = [], ?string $replaces = null) use ($id, $hasSourceEvidence, $hasImplementationTarget): array {
            $status = ($action === self::ACTION_KEEP || ($hasSourceEvidence && $hasImplementationTarget))
                ? self::STATUS_READY
                : self::STATUS_NOT_READY;

            $payload = [
                'schema' => self::SCHEMA,
                'packet_id' => $id,
                'action' => $action,
                'rationale' => $rationale,
                'affected_fields' => $affected,
                'revalidation_gates' => $gates,
                'validation_gates' => $gates,
                'blocked_reason_mapping' => self::BLOCKED_REASON_TO_ACTION,
                'proof_requirements' => self::ACTION_TO_PROOF_REQUIREMENTS[$action] ?? [],
                'rollback_plan' => [
                    'description' => self::ACTION_TO_ROLLBACK_DESCRIPTION[$action] ?? 'no rollback plan defined',
                    'requires_confirmation' => in_array($action, self::HIGH_RISK_ROLLBACK_ACTIONS, true),
                ],
                'status' => $status,
            ];
            if ($replaces !== null) {
                $payload['replaces_authority'] = $replaces;
            }

            return $payload;
        };

        if ((bool) ($facts['too_many_deficiencies'] ?? false) || (bool) ($facts['cli_clobber'] ?? false)) {
            $why = (bool) ($facts['cli_clobber'] ?? false) ? 'cli_clobber' : 'too_many_deficiencies';

            return $envelope(self::ACTION_QUARANTINE, 'quarantine until respec: '.$why, ['status'], ['operator_review_visibility']);
        }

        if ((bool) ($facts['contradictory_acceptance'] ?? false)) {
            $contradictingFields = is_array($facts['contradicting_fields'] ?? null)
                ? array_values(array_map('strval', $facts['contradicting_fields']))
                : [];
            $affectedFields = array_values(array_unique(array_merge(['objective', 'acceptance_criteria'], $contradictingFields)));

            return $envelope(
                self::ACTION_REWRITE_OBJECTIVE,
                'acceptance_criteria are mutually contradictory; propose concrete rewrite of affected fields',
                $affectedFields,
                ['atlas_task_quality_inspector', '/opt/homebrew/bin/php artisan test'],
            );
        }

        if ((bool) ($facts['autonomy_regression'] ?? false)) {
            // Atlas-native replacement — never propose a human/operator/external-provider dependency.
            return $envelope(
                self::ACTION_SPLIT,
                'autonomy regression: split into Atlas-native replacement slices with runnable gates',
                ['allowed_files', 'acceptance_criteria'],
                ['atlas_task_quality_inspector', '/opt/homebrew/bin/php artisan test'],
                'non_atlas_native',
            );
        }

        // Forbidden/pétreo missing files must be quarantined, not silently added.
        $forbiddenMissing = is_array($facts['forbidden_missing_files'] ?? null)
            ? array_values(array_map('strval', $facts['forbidden_missing_files']))
            : [];
        if ($missing !== [] && array_intersect($missing, $forbiddenMissing) !== []) {
            return $envelope(self::ACTION_QUARANTINE, 'quarantine until respec: forbidden_or_petreo_file', ['allowed_files', 'status'], ['atlas_task_quality_inspector']);
        }

        if ($missing !== []) {
            return $envelope(self::ACTION_ADD_FILE, 'missing impl+test pair candidates: '.implode(',', $missing), ['allowed_files'], ['atlas_task_quality_inspector', '/opt/homebrew/bin/php artisan test']);
        }

        if (in_array('too_broad_scope', $poison, true)) {
            return $envelope(self::ACTION_SPLIT, 'too_broad_scope hidden poison fact', ['allowed_files', 'acceptance_criteria'], ['atlas_task_quality_inspector']);
        }

        if ($poison !== []) {
            return $envelope(self::ACTION_GIVE_BACK, 'hidden_poison_facts: '.implode(',', $poison), ['status'], ['atlas_task_quality_inspector']);
        }

        return $envelope(self::ACTION_KEEP, 'no poison signal detected', [], []);
    }
}
