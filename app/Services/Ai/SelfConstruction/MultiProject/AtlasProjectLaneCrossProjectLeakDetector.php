<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure cross-project leak detector for Atlas project-stewardship lanes. Given (1) a map of lane manifests
 * keyed by project_id and (2) a bundle of inspected records (packets, receipts, release decisions), the
 * detector reports every FACT that crosses lane boundaries.
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NO SCORING: leaks are listed verbatim; no scalar passed/leak-rate; passed=true iff leaks===[].
 *   - LEAK FAMILIES detected:
 *       packet_project_id_mismatch        — packet.project_id ∉ provided lanes
 *       task_packet_id_namespace_mismatch — packet.task_packet_id carries another lane's namespace
 *       allowed_files_escape_lane         — packet.allowed_files[] outside that lane's allowed_scope_roots
 *       scope_in_escape_lane              — packet.scope_in[] outside that lane's allowed_scope_roots
 *       receipt_project_mismatch          — receipt.project_id ≠ lane it was filed under
 *       release_target_mismatch           — release.project_id ≠ release.target_project_id
 *   - SAMPLE BOUNDING: leak samples capped at MAX_SAMPLES to prevent envelope blow-up.
 */
final class AtlasProjectLaneCrossProjectLeakDetector
{
    public const SCHEMA = 'atlas.multiproject.cross_project_leak_detector.v1';

    public const MAX_SAMPLES = 50;

    /**
     * @param  array<string,array{project_id:string, namespace:string, allowed_scope_roots:list<string>}>  $lanesByProjectId
     * @param  array{packets?:list<array<string,mixed>>, receipts?:list<array<string,mixed>>, releases?:list<array<string,mixed>>}  $inspected
     * @return array{schema_version:string, status:string, passed:bool, inspected_count:int, leaks:list<array<string,mixed>>, blockers:list<string>, proof_summary:array<string,int>}
     */
    public function detect(array $lanesByProjectId, array $inspected): array
    {
        $packets = is_array($inspected['packets'] ?? null) ? array_values($inspected['packets']) : [];
        $receipts = is_array($inspected['receipts'] ?? null) ? array_values($inspected['receipts']) : [];
        $releases = is_array($inspected['releases'] ?? null) ? array_values($inspected['releases']) : [];

        $leaks = [];
        $blockerSet = [];

        // Lane manifest isolation: shared queue_namespace / evidence_ledger_path / memory_scope / code_index_scope
        foreach (['queue_namespace', 'evidence_ledger_path', 'memory_scope', 'code_index_scope'] as $field) {
            $seen = [];
            foreach ($lanesByProjectId as $pid => $lane) {
                $val = (string) ($lane[$field] ?? '');
                if ($val === '') {
                    continue;
                }
                if (isset($seen[$val])) {
                    $leaks[] = $this->leak($field.'_shared', ['field' => $field, 'value' => $val, 'lane_a' => $seen[$val], 'lane_b' => $pid]);
                    $blockerSet[$field.'_shared'] = true;
                } else {
                    $seen[$val] = $pid;
                }
            }
        }

        // Packet inspection.
        foreach ($packets as $p) {
            if (! is_array($p)) {
                continue;
            }
            $projId = (string) ($p['project_id'] ?? '');
            $lane = $lanesByProjectId[$projId] ?? null;
            if ($lane === null) {
                $leaks[] = $this->leak('packet_project_id_mismatch', ['packet_id' => (string) ($p['task_packet_id'] ?? ''), 'project_id' => $projId]);
                $blockerSet['packet_project_id_mismatch'] = true;

                continue;
            }
            $laneNs = (string) $lane['namespace'];
            $taskId = (string) ($p['task_packet_id'] ?? '');
            if ($taskId !== '' && str_starts_with($taskId, 'lane.')) {
                $colon = strpos($taskId, ':');
                $prefix = $colon === false ? $taskId : substr($taskId, 0, $colon);
                if ($prefix !== $laneNs) {
                    $leaks[] = $this->leak('task_packet_id_namespace_mismatch', ['packet_id' => $taskId, 'expected_namespace' => $laneNs, 'actual_namespace' => $prefix]);
                    $blockerSet['task_packet_id_namespace_mismatch'] = true;
                }
            }
            $laneRoots = array_map('strval', $lane['allowed_scope_roots'] ?? []);
            $allowedFiles = is_array($p['allowed_files'] ?? null) ? array_map('strval', $p['allowed_files']) : [];
            foreach ($allowedFiles as $file) {
                if (! $this->insideLane($file, $laneRoots)) {
                    $leaks[] = $this->leak('allowed_files_escape_lane', ['packet_id' => $taskId, 'project_id' => $projId, 'path' => $file]);
                    $blockerSet['allowed_files_escape_lane'] = true;
                }
            }
            $scopeIn = is_array($p['scope_in'] ?? null) ? array_map('strval', $p['scope_in']) : [];
            foreach ($scopeIn as $file) {
                if (! $this->insideLane($file, $laneRoots)) {
                    $leaks[] = $this->leak('scope_in_escape_lane', ['packet_id' => $taskId, 'project_id' => $projId, 'path' => $file]);
                    $blockerSet['scope_in_escape_lane'] = true;
                }
            }
        }

        // Receipt inspection.
        foreach ($receipts as $r) {
            if (! is_array($r)) {
                continue;
            }
            $declaredFor = (string) ($r['filed_under_project_id'] ?? ($r['lane_project_id'] ?? ''));
            $receiptProj = (string) ($r['project_id'] ?? '');
            if ($declaredFor !== '' && $receiptProj !== '' && $declaredFor !== $receiptProj) {
                $leaks[] = $this->leak('receipt_project_mismatch', ['filed_under_project_id' => $declaredFor, 'receipt_project_id' => $receiptProj, 'envelope_hash' => (string) ($r['envelope_hash'] ?? '')]);
                $blockerSet['receipt_project_mismatch'] = true;
            }
        }

        // Release inspection.
        foreach ($releases as $rel) {
            if (! is_array($rel)) {
                continue;
            }
            $proj = (string) ($rel['project_id'] ?? '');
            $target = (string) ($rel['target_project_id'] ?? $proj);
            if ($proj !== '' && $target !== '' && $proj !== $target) {
                $leaks[] = $this->leak('release_target_mismatch', ['project_id' => $proj, 'target_project_id' => $target]);
                $blockerSet['release_target_mismatch'] = true;
            }
        }

        $leaks = array_slice($leaks, 0, self::MAX_SAMPLES);
        $blockers = array_keys($blockerSet);
        sort($blockers, SORT_STRING);

        $proofSummary = [
            'packets_inspected' => count($packets),
            'receipts_inspected' => count($receipts),
            'releases_inspected' => count($releases),
            'leak_count' => count($leaks),
            'distinct_blocker_families' => count($blockers),
        ];

        return [
            'schema_version' => self::SCHEMA,
            'status' => $leaks === [] ? 'clean' : 'leaks_detected',
            'passed' => $leaks === [],
            'inspected_count' => count($packets) + count($receipts) + count($releases),
            'leaks' => $leaks,
            'blockers' => $blockers,
            'proof_summary' => $proofSummary,
        ];
    }

    /**
     * @return array{kind:string, fact:array<string,mixed>}
     */
    private function leak(string $kind, array $fact): array
    {
        return ['kind' => $kind, 'fact' => $fact];
    }

    /**
     * @param  list<string>  $laneRoots
     */
    private function insideLane(string $path, array $laneRoots): bool
    {
        if ($laneRoots === []) {
            return false;
        }
        foreach ($laneRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                return true;
            }
        }

        return false;
    }
}
