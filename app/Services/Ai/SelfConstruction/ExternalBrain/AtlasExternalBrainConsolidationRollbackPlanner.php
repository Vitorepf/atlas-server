<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed rollback invariant gate: a compression wave may only be APPROVED when every
 * destructive action inside it (delete, merge, inline) carries CONCRETE restoration evidence —
 * the exact files to restore, the contracts that must still hold after restoring them, and a
 * runnable command that verifies the restoration actually worked. A bare `rollback_available =
 * true` boolean is never accepted as proof; each of the three parts must be named explicitly.
 *
 * Non-destructive actions (e.g. 'simplify', 'add') never require rollback evidence — only
 * delete/merge/inline actions remove or collapse something that would need restoring.
 *
 * Input shape:
 *   { actions: list<{
 *       action_id?:              string,
 *       kind?:                   string,  // 'delete'|'merge'|'inline'|other
 *       restoration_files?:      list<string>,
 *       restoration_contracts?:  list<string>,
 *       verification_commands?:  list<string>,
 *   }> }
 *
 * Verdict:
 *   approved — every destructive action has all three rollback parts present.
 *   hold     — at least one destructive action is missing one or more rollback parts; the exact
 *              missing parts are named per action, never a generic "rollback incomplete".
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainConsolidationRollbackPlanner
{
    public const SCHEMA = 'atlas.external_brain.consolidation_rollback_planner.v1';

    public const VERDICT_APPROVED = 'approved';

    public const VERDICT_HOLD = 'hold';

    private const DESTRUCTIVE_KINDS = ['delete', 'merge', 'inline'];

    /**
     * @param  array{actions?: list<array<string,mixed>>}  $wave
     * @return array{schema:string, verdict:string, action_results:list<array<string,mixed>>, missing_rollback_summary:list<string>}
     */
    public function plan(array $wave): array
    {
        $actions = is_array($wave['actions'] ?? null) ? $wave['actions'] : [];

        $actionResults = [];
        $missingSummary = [];

        foreach ($actions as $action) {
            if (! is_array($action) || ! isset($action['action_id'])) {
                continue;
            }

            $actionId = (string) $action['action_id'];
            $kind = strtolower(trim((string) ($action['kind'] ?? '')));
            $requiresRollback = in_array($kind, self::DESTRUCTIVE_KINDS, true);

            $restorationFiles = array_values(array_filter(array_map('strval', (array) ($action['restoration_files'] ?? []))));
            $restorationContracts = array_values(array_filter(array_map('strval', (array) ($action['restoration_contracts'] ?? []))));
            $verificationCommands = array_values(array_filter(array_map('strval', (array) ($action['verification_commands'] ?? []))));

            $missingParts = [];
            if ($requiresRollback) {
                if ($restorationFiles === []) {
                    $missingParts[] = 'missing_restoration_files';
                }
                if ($restorationContracts === []) {
                    $missingParts[] = 'missing_restoration_contracts';
                }
                if ($verificationCommands === []) {
                    $missingParts[] = 'missing_verification_commands';
                }
            }

            $rollbackComplete = ! $requiresRollback || $missingParts === [];

            $actionResults[] = [
                'action_id' => $actionId,
                'kind' => $kind,
                'requires_rollback' => $requiresRollback,
                'rollback_complete' => $rollbackComplete,
                'missing_rollback_parts' => $missingParts,
            ];

            foreach ($missingParts as $part) {
                $missingSummary[] = $actionId.':'.$part;
            }
        }

        $verdict = $missingSummary === [] ? self::VERDICT_APPROVED : self::VERDICT_HOLD;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'action_results' => $actionResults,
            'missing_rollback_summary' => $missingSummary,
        ];
    }
}
