<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Builds a deterministic RESPEC PLAN from hidden-poison FACTS observed on a packet. NEVER edits queue
 * records — returns a proposed respec packet (rationale + affected fields + revalidation gates).
 *
 * INPUT:
 *   { packet_id, hidden_poison_facts:list<string>, missing_files:list<string>,
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
     * @return array{schema:string, packet_id:string, action:string, rationale:string, affected_fields:list<string>, revalidation_gates:list<string>, replaces_authority?:string}
     */
    public function build(array $facts): array
    {
        $id = (string) ($facts['packet_id'] ?? '');
        $poison = is_array($facts['hidden_poison_facts'] ?? null) ? array_values(array_map('strval', $facts['hidden_poison_facts'])) : [];
        $missing = is_array($facts['missing_files'] ?? null) ? array_values(array_map('strval', $facts['missing_files'])) : [];

        $envelope = function (string $action, string $rationale, array $affected = [], array $gates = [], ?string $replaces = null) use ($id): array {
            $payload = [
                'schema' => self::SCHEMA,
                'packet_id' => $id,
                'action' => $action,
                'rationale' => $rationale,
                'affected_fields' => $affected,
                'revalidation_gates' => $gates,
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
            return $envelope(self::ACTION_REWRITE_OBJECTIVE, 'acceptance_criteria are mutually contradictory', ['objective', 'acceptance_criteria'], ['atlas_task_quality_inspector', 'phpunit']);
        }

        if ((bool) ($facts['autonomy_regression'] ?? false)) {
            // Atlas-native replacement — never propose a human dependency.
            return $envelope(self::ACTION_SPLIT, 'autonomy regression: split into Atlas-native replacement slices', ['allowed_files', 'acceptance_criteria'], ['atlas_task_quality_inspector', 'phpunit'], 'non_atlas_native');
        }

        if ($missing !== []) {
            return $envelope(self::ACTION_ADD_FILE, 'missing impl files detected: '.implode(',', $missing), ['allowed_files'], ['atlas_task_quality_inspector']);
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
