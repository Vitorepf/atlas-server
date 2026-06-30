<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

/**
 * Pure detector that REJECTS worker completion when evidence says green but replay/scope FACTS
 * contradict it.
 *
 * INPUT FACTS:
 *   { evidence_contract_result:{accepted:bool},
 *     replay_plan_result:{plan_status:string, commands:list<{id:string, name:string}>, blockers:list<string>},
 *     replay_outcomes:list<{command_id:string, name:string, passed:bool, output_present?:bool}>,
 *     changed_files:list<string>, allowed_files:list<string>,
 *     proxy_only_evidence?:bool }
 *
 * OUTPUT:
 *   { schema, verdict ∈ {passed,failed,blocked}, reasons:list<string>, summary:array }
 *
 * REASON FAMILIES:
 *   - evidence_contract_not_accepted        → blocked
 *   - replay_plan_blocked                   → blocked
 *   - replay_missing_for:<command_id>       → blocked
 *   - replay_red:<command_id>               → failed
 *   - replay_output_missing:<command_id>    → failed (claimed test, no command output)
 *   - changed_file_outside_allowed:<path>   → failed
 *   - proxy_only_evidence                   → failed
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope: reasons sorted byte-stably.
 *   - NO scalar score, NO mutation, NO shell, NO git, NO provider call.
 */
final class AtlasVerificationCourtFalseGreenDetector
{
    public const SCHEMA = 'atlas.verificationcourt.false_green_detector.v1';

    public const VERDICT_PASSED = 'passed';

    public const VERDICT_FAILED = 'failed';

    public const VERDICT_BLOCKED = 'blocked';

    /**
     * @param  array{
     *     evidence_contract_result?:array{accepted?:bool},
     *     replay_plan_result?:array{plan_status?:string, commands?:list<array{id?:string, name?:string}>, blockers?:list<string>},
     *     replay_outcomes?:list<array{command_id?:string, name?:string, passed?:bool, output_present?:bool}>,
     *     changed_files?:list<string>,
     *     allowed_files?:list<string>,
     *     proxy_only_evidence?:bool
     * }  $facts
     * @return array{schema:string, verdict:string, reasons:list<string>, summary:array<string,int|bool>}
     */
    public function detect(array $facts): array
    {
        $blockerReasons = [];
        $failedReasons = [];

        // 1. evidence contract gate
        $accepted = (bool) ($facts['evidence_contract_result']['accepted'] ?? false);
        if (! $accepted) {
            $blockerReasons[] = 'evidence_contract_not_accepted';
        }

        // 2. replay plan readiness
        $plan = is_array($facts['replay_plan_result'] ?? null) ? $facts['replay_plan_result'] : [];
        $planStatus = (string) ($plan['plan_status'] ?? '');
        if ($planStatus !== 'ready') {
            $blockerReasons[] = 'replay_plan_blocked';
            foreach ((array) ($plan['blockers'] ?? []) as $b) {
                $blockerReasons[] = 'replay_plan_blocker:'.(string) $b;
            }
        }

        // 3. every planned command must have a recorded outcome; conflicting duplicates fail-closed
        $plannedCmds = is_array($plan['commands'] ?? null) ? $plan['commands'] : [];
        $outcomes = is_array($facts['replay_outcomes'] ?? null) ? array_values($facts['replay_outcomes']) : [];

        $outcomesById = [];
        foreach ($outcomes as $o) {
            if (is_array($o) && isset($o['command_id'])) {
                $outcomesById[(string) $o['command_id']][] = $o;
            }
        }

        $conflictedIds = [];
        $outcomeById = [];
        foreach ($outcomesById as $cmdId => $rows) {
            $ref = $rows[0];
            $conflict = false;
            foreach (array_slice($rows, 1) as $row) {
                if (($row['passed'] ?? null) !== ($ref['passed'] ?? null)
                    || ($row['output_present'] ?? null) !== ($ref['output_present'] ?? null)) {
                    $conflict = true;
                    break;
                }
            }
            if ($conflict) {
                $conflictedIds[$cmdId] = true;
                $failedReasons[] = 'replay_conflict:'.$cmdId;
            } else {
                $outcomeById[$cmdId] = $ref;
            }
        }

        foreach ($plannedCmds as $cmd) {
            $cmdId = (string) ($cmd['id'] ?? '');
            if ($cmdId === '') {
                continue;
            }
            if (isset($conflictedIds[$cmdId])) {
                continue; // replay_conflict already added
            }
            if (! isset($outcomeById[$cmdId])) {
                $blockerReasons[] = 'replay_missing_for:'.$cmdId;

                continue;
            }
            $row = $outcomeById[$cmdId];
            if (($row['passed'] ?? null) !== true) {
                $failedReasons[] = 'replay_red:'.$cmdId;
            }
            if (array_key_exists('output_present', $row) && ($row['output_present'] === false)) {
                $failedReasons[] = 'replay_output_missing:'.$cmdId;
            }
        }

        // 4. scope-clean check
        $allowed = is_array($facts['allowed_files'] ?? null) ? array_values(array_map('strval', $facts['allowed_files'])) : [];
        $changed = is_array($facts['changed_files'] ?? null) ? array_values(array_map('strval', $facts['changed_files'])) : [];
        if ($allowed !== []) {
            foreach ($changed as $cf) {
                if (! in_array($cf, $allowed, true)) {
                    $failedReasons[] = 'changed_file_outside_allowed:'.$cf;
                }
            }
        }

        // 5. proxy-only evidence (anti-Goodhart)
        if (! empty($facts['proxy_only_evidence'])) {
            $failedReasons[] = 'proxy_only_evidence';
        }

        $allReasons = array_values(array_unique(array_merge($blockerReasons, $failedReasons)));
        sort($allReasons, SORT_STRING);

        $verdict = self::VERDICT_PASSED;
        if ($blockerReasons !== []) {
            $verdict = self::VERDICT_BLOCKED;
        } elseif ($failedReasons !== []) {
            $verdict = self::VERDICT_FAILED;
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'reasons' => $allReasons,
            'summary' => [
                'evidence_accepted' => $accepted,
                'planned_command_count' => count($plannedCmds),
                'outcome_count' => count($outcomes),
                'changed_file_count' => count($changed),
            ],
        ];
    }
}
