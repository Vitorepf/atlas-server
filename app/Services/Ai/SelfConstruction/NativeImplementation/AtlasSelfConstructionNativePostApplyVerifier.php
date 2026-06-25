<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

/**
 * Native post-apply verifier. Takes apply receipts + gate command results and produces a verdict:
 *   - passed             — every expected gate ran AND every gate's exit_code is 0 AND the changed
 *                          files set matches the apply receipts byte-for-byte.
 *   - failed             — at least one expected gate ran with non-zero exit_code.
 *   - rollback_required — at least one expected gate is missing OR the apply receipts disagree with
 *                         the actually changed files (worker self-report cannot be trusted alone).
 *
 * Every verdict carries: task_packet_id, expected_gates, gates_summary, changed_files, evidence_hashes,
 * blockers (list<string>). Pure: no providers, no processes, no I/O.
 */
final class AtlasSelfConstructionNativePostApplyVerifier
{
    public const SCHEMA = 'atlas.native_implementation.post_apply_verifier.v1';

    public const VERDICT_PASSED = 'passed';
    public const VERDICT_FAILED = 'failed';
    public const VERDICT_ROLLBACK = 'rollback_required';

    /**
     * @param  array<string,mixed>  $facts {
     *   task_packet_id:string,
     *   expected_gates:list<string>,
     *   apply_receipts:list<{path:string, post_hash:string, bytes_written?:int}>,
     *   changed_files:list<{path:string, post_hash:string}>,
     *   gate_results:list<{gate:string, exit_code:int, evidence_hash?:string, stdout_hash?:string}>
     * }
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $taskId = (string) ($facts['task_packet_id'] ?? '');
        $expected = array_values(array_unique(array_map('strval', (array) ($facts['expected_gates'] ?? []))));
        $receipts = array_values((array) ($facts['apply_receipts'] ?? []));
        $changed = array_values((array) ($facts['changed_files'] ?? []));
        $gateResults = array_values((array) ($facts['gate_results'] ?? []));

        $blockers = [];
        if ($taskId === '') {
            $blockers[] = 'task_packet_id_missing';
        }

        $receiptMap = [];
        foreach ($receipts as $r) {
            if (! is_array($r) || ! isset($r['path'])) {
                continue;
            }
            $receiptMap[(string) $r['path']] = (string) ($r['post_hash'] ?? '');
        }
        $changedMap = [];
        foreach ($changed as $c) {
            if (! is_array($c) || ! isset($c['path'])) {
                continue;
            }
            $changedMap[(string) $c['path']] = (string) ($c['post_hash'] ?? '');
        }

        $changedFileMismatch = false;
        ksort($receiptMap);
        ksort($changedMap);
        if ($receiptMap !== $changedMap) {
            $changedFileMismatch = true;
            $blockers[] = 'changed_files_mismatch_apply_receipts';
        }

        $gateMap = [];
        foreach ($gateResults as $g) {
            if (! is_array($g) || ! isset($g['gate'])) {
                continue;
            }
            $gateMap[(string) $g['gate']] = $g;
        }

        $missingGates = [];
        $failedGates = [];
        $passedGates = [];
        foreach ($expected as $gate) {
            if (! isset($gateMap[$gate])) {
                $missingGates[] = $gate;
                $blockers[] = 'expected_gate_missing:'.$gate;

                continue;
            }
            $exit = (int) ($gateMap[$gate]['exit_code'] ?? -1);
            if ($exit === 0) {
                $passedGates[] = $gate;
            } else {
                $failedGates[] = $gate;
                $blockers[] = 'gate_failed:'.$gate.':exit='.$exit;
            }
        }

        $evidenceHashes = [];
        foreach ($expected as $gate) {
            $row = $gateMap[$gate] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $evidenceHashes[$gate] = (string) ($row['evidence_hash'] ?? ($row['stdout_hash'] ?? ''));
        }

        $verdict = self::VERDICT_PASSED;
        if ($missingGates !== [] || $changedFileMismatch) {
            $verdict = self::VERDICT_ROLLBACK;
        } elseif ($failedGates !== []) {
            $verdict = self::VERDICT_FAILED;
        }

        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => $taskId,
            'verdict' => $verdict,
            'expected_gates' => $expected,
            'gates_summary' => [
                'passed' => $passedGates,
                'failed' => $failedGates,
                'missing' => $missingGates,
            ],
            'changed_files' => array_keys($changedMap),
            'evidence_hashes' => $evidenceHashes,
            'blockers' => array_values($blockers),
        ];
    }
}
