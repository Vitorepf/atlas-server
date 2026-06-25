<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

/**
 * Pure validator for worker COMPLETION EVIDENCE. The contract treats worker output as ALLEGATION only —
 * it NEVER emits verified=true. accepted=true means the allegation is well-formed enough for the court
 * to consider; the actual server-side verdict is delivered by the court (separate service).
 *
 * REQUIRED EVIDENCE FIELDS:
 *   { task_packet_id, lease_id, files_changed:list<string>, commands_run:list<array>,
 *     tests_or_gates_result:{passed:bool, gate?:string}, evidence_hash, scope_deviations:list,
 *     residual_risks:list<string>, runtime_owner }
 *
 * BLOCKER FAMILIES (deterministically sorted):
 *   - missing_task_packet_id / missing_lease_id / missing_files_changed / missing_commands_run /
 *     missing_evidence_hash
 *   - missing_tests_or_gates_result_passed
 *   - unacknowledged_scope_deviation:<path>
 *   - non_atlas_native_runtime_owner:<owner>
 *   - empty_command_proof   (commands_run had only entries without a name/exit_code)
 *
 * INVARIANTS:
 *   - PURE — no I/O, no provider call. DETERMINISTIC envelope.
 *   - The output schema explicitly carries `verified` set to NULL. Never true. Only the court grants
 *     verified=true downstream.
 */
final class AtlasVerificationCourtEvidenceContract
{
    public const SCHEMA = 'atlas.verificationcourt.evidence_contract.v1';

    public const RUNTIME_OWNER_NATIVE = 'atlas_native';

    /**
     * @param  array<string,mixed>  $evidence
     * @return array{schema:string, accepted:bool, verified:null, blockers:list<string>, allegation_summary:array<string,mixed>}
     */
    public function evaluate(array $evidence): array
    {
        $blockers = [];

        $taskId = (string) ($evidence['task_packet_id'] ?? '');
        if ($taskId === '') {
            $blockers[] = 'missing_task_packet_id';
        }
        $leaseId = (string) ($evidence['lease_id'] ?? '');
        if ($leaseId === '') {
            $blockers[] = 'missing_lease_id';
        }
        $filesChanged = is_array($evidence['files_changed'] ?? null) ? array_values(array_map('strval', $evidence['files_changed'])) : null;
        if ($filesChanged === null || $filesChanged === []) {
            $blockers[] = 'missing_files_changed';
        }
        $commandsRun = is_array($evidence['commands_run'] ?? null) ? array_values($evidence['commands_run']) : null;
        if ($commandsRun === null) {
            $blockers[] = 'missing_commands_run';
        } else {
            // Verify there's at least one command with a name (empty-command-proof guard).
            $hasNamedCommand = false;
            foreach ($commandsRun as $c) {
                if (is_array($c) && trim((string) ($c['name'] ?? '')) !== '') {
                    $hasNamedCommand = true;
                    break;
                }
            }
            if (! $hasNamedCommand) {
                $blockers[] = 'empty_command_proof';
            }
        }
        $gateResult = is_array($evidence['tests_or_gates_result'] ?? null) ? $evidence['tests_or_gates_result'] : null;
        if ($gateResult === null || ! array_key_exists('passed', $gateResult)) {
            $blockers[] = 'missing_tests_or_gates_result_passed';
        }
        $evHash = (string) ($evidence['evidence_hash'] ?? '');
        if ($evHash === '') {
            $blockers[] = 'missing_evidence_hash';
        }
        $scopeDevs = is_array($evidence['scope_deviations'] ?? null) ? array_values($evidence['scope_deviations']) : [];
        foreach ($scopeDevs as $dev) {
            if (! is_array($dev) || empty($dev['acknowledged'])) {
                $blockers[] = 'unacknowledged_scope_deviation:'.(string) ($dev['path'] ?? '?');
            }
        }
        $runtimeOwner = (string) ($evidence['runtime_owner'] ?? '');
        if ($runtimeOwner !== self::RUNTIME_OWNER_NATIVE) {
            $blockers[] = 'non_atlas_native_runtime_owner:'.($runtimeOwner === '' ? 'missing' : $runtimeOwner);
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $blockers === [],
            'verified' => null, // NEVER set to true here — only the court grants verified downstream.
            'blockers' => $blockers,
            'allegation_summary' => [
                'task_packet_id' => $taskId,
                'lease_id' => $leaseId,
                'files_changed_count' => is_array($filesChanged) ? count($filesChanged) : 0,
                'commands_run_count' => is_array($commandsRun) ? count($commandsRun) : 0,
                'evidence_hash_present' => $evHash !== '',
                'runtime_owner' => $runtimeOwner,
                'residual_risk_count' => count((array) ($evidence['residual_risks'] ?? [])),
            ],
        ];
    }
}
