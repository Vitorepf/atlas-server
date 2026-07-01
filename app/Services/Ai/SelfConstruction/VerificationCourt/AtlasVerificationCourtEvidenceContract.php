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
 *   - missing_receipt_chain (evidence.receipt_chain absent)
 *   - receipt_chain_task_packet_id_mismatch / receipt_chain_lease_id_mismatch /
 *     receipt_chain_allowed_files_hash_mismatch / receipt_chain_command_hash_mismatch
 *     (receipt_chain.{field} absent OR differs from the evidence's own top-level claim)
 *
 * WORKER-FEED CONTINUITY (opt-in, only checked when evidence sets
 * `claims_autonomous_execution_quality=true` — a dry-run-only allegation with no such claim is
 * unaffected): the allegation must carry a `worker_feed_continuity` array with `claimable_depth`,
 * `active_worker_count`, and `no_claimable_task_repair_status`, and that array must be explicitly
 * marked `fresh=true` — a claim of autonomous execution quality backed by stale or absent
 * worker-feed evidence never gets to lean on the court's benefit of the doubt.
 *   - missing_worker_feed_continuity
 *   - missing_worker_feed_continuity_claimable_depth
 *   - missing_worker_feed_continuity_active_worker_count
 *   - missing_worker_feed_continuity_no_claimable_task_repair_status
 *   - worker_feed_continuity_not_fresh
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

    public const CLAIM_AUTONOMOUS_EXECUTION_QUALITY_KEY = 'claims_autonomous_execution_quality';

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array{task_packet_id?:string, allowed_files_hash?:string, command_hash?:string}  $expected
     *         Binding context for replay-protection checks. When a key is present its value must match.
     * @return array{schema:string, accepted:bool, verified:null, blockers:list<string>, allegation_summary:array<string,mixed>}
     */
    public function evaluate(array $evidence, array $expected = []): array
    {
        $blockers = [];

        $taskId = (string) ($evidence['task_packet_id'] ?? '');
        if ($taskId === '') {
            $blockers[] = 'missing_task_packet_id';
        } elseif (isset($expected['task_packet_id']) && $taskId !== (string) $expected['task_packet_id']) {
            $blockers[] = 'task_packet_id_mismatch';
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
            $hasNamedCommand = false;
            $missingExitCode = false;
            foreach ($commandsRun as $c) {
                if (! is_array($c)) {
                    continue;
                }
                if (trim((string) ($c['name'] ?? '')) !== '') {
                    $hasNamedCommand = true;
                    if (! array_key_exists('exit_code', $c) || ! is_int($c['exit_code'])) {
                        $missingExitCode = true;
                    }
                }
            }
            if (! $hasNamedCommand) {
                $blockers[] = 'empty_command_proof';
            } elseif ($missingExitCode) {
                $blockers[] = 'missing_command_exit_code';
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

        $receiptHash = (string) ($evidence['receipt_hash'] ?? '');
        if ($receiptHash === '') {
            $blockers[] = 'receipt_hash_missing';
        }

        $allowedFilesHash = (string) ($evidence['allowed_files_hash'] ?? '');
        if (isset($expected['allowed_files_hash']) && $allowedFilesHash !== (string) $expected['allowed_files_hash']) {
            $blockers[] = 'allowed_files_hash_mismatch';
        }

        $commandHash = (string) ($evidence['command_hash'] ?? '');
        if (isset($expected['command_hash']) && $commandHash !== (string) $expected['command_hash']) {
            $blockers[] = 'command_hash_mismatch';
        }

        // Receipt hash CHAIN: binds the receipt to the exact task/lease/allowed-files/command-proof
        // this allegation claims — a receipt is worthless proof if it could have been generated for a
        // different task, a different lease, different files, or different commands. Every link must
        // be present AND match the evidence's own top-level claim; the court cannot consider evidence
        // whose receipt chain does not provably cover what is being alleged.
        $receiptChain = is_array($evidence['receipt_chain'] ?? null) ? $evidence['receipt_chain'] : null;
        if ($receiptChain === null) {
            $blockers[] = 'missing_receipt_chain';
        } else {
            $chainTaskId = (string) ($receiptChain['task_packet_id'] ?? '');
            if ($chainTaskId === '' || $chainTaskId !== $taskId) {
                $blockers[] = 'receipt_chain_task_packet_id_mismatch';
            }
            $chainLeaseId = (string) ($receiptChain['lease_id'] ?? '');
            if ($chainLeaseId === '' || $chainLeaseId !== $leaseId) {
                $blockers[] = 'receipt_chain_lease_id_mismatch';
            }
            $chainAllowedFilesHash = (string) ($receiptChain['allowed_files_hash'] ?? '');
            if ($chainAllowedFilesHash === '' || $chainAllowedFilesHash !== $allowedFilesHash) {
                $blockers[] = 'receipt_chain_allowed_files_hash_mismatch';
            }
            $chainCommandHash = (string) ($receiptChain['command_hash'] ?? '');
            if ($chainCommandHash === '' || $chainCommandHash !== $commandHash) {
                $blockers[] = 'receipt_chain_command_hash_mismatch';
            }
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

        $claimsAutonomyQuality = (bool) ($evidence[self::CLAIM_AUTONOMOUS_EXECUTION_QUALITY_KEY] ?? false);
        if ($claimsAutonomyQuality) {
            $continuity = is_array($evidence['worker_feed_continuity'] ?? null) ? $evidence['worker_feed_continuity'] : null;
            if ($continuity === null) {
                $blockers[] = 'missing_worker_feed_continuity';
            } else {
                if (! array_key_exists('claimable_depth', $continuity)) {
                    $blockers[] = 'missing_worker_feed_continuity_claimable_depth';
                }
                if (! array_key_exists('active_worker_count', $continuity)) {
                    $blockers[] = 'missing_worker_feed_continuity_active_worker_count';
                }
                if (! array_key_exists('no_claimable_task_repair_status', $continuity)) {
                    $blockers[] = 'missing_worker_feed_continuity_no_claimable_task_repair_status';
                }
                if (($continuity['fresh'] ?? false) !== true) {
                    $blockers[] = 'worker_feed_continuity_not_fresh';
                }
            }
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
