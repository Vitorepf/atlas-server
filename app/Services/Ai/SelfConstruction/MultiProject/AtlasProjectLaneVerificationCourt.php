<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure verification court that requires lane promotion to show a quorum of fresh
 * server-side evidence hashes bound to lane roots, project ID, and required rerun gates.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasProjectLaneVerificationCourt
{
    public const SCHEMA = 'atlas.multi_project.lane_verification_court.v1';

    /**
     * @param  array{
     *   project_id?:string,
     *   lane_root?:string,
     *   quorum_floor?:int,
     *   required_rerun_gates?:list<string>,
     *   evidence?:list<array{
     *     hash?:string,
     *     project_id?:string,
     *     lane_root?:string,
     *     gate_id?:string,
     *     freshness_iso?:string,
     *     stale_after_seconds?:int,
     *   }>,
     *   now_iso?:string,
     * }  $facts
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   blockers:list<string>,
     * }
     */
    public function verify(array $facts): array
    {
        $projectId = (string) ($facts['project_id'] ?? '');
        $laneRoot = (string) ($facts['lane_root'] ?? '');
        $quorumFloor = max(1, (int) ($facts['quorum_floor'] ?? 2));
        $requiredGates = array_values(array_filter((array) ($facts['required_rerun_gates'] ?? [])));
        $evidence = (array) ($facts['evidence'] ?? []);
        $nowIso = (string) ($facts['now_iso'] ?? date('c'));

        $blockers = [];
        $validEvidence = [];

        foreach ($evidence as $ev) {
            $hash = (string) ($ev['hash'] ?? '');
            $evProjectId = (string) ($ev['project_id'] ?? '');
            $evLaneRoot = (string) ($ev['lane_root'] ?? '');
            $gateId = (string) ($ev['gate_id'] ?? '');
            $freshnessIso = (string) ($ev['freshness_iso'] ?? '');
            $staleAfter = (int) ($ev['stale_after_seconds'] ?? 3600);

            $evBlockers = [];

            if ($hash === '') {
                $evBlockers[] = 'missing_hash';
            }
            if ($evProjectId !== $projectId) {
                $evBlockers[] = 'project_id_mismatch';
            }
            if ($evLaneRoot !== $laneRoot) {
                $evBlockers[] = 'lane_root_mismatch';
            }

            // Staleness check
            if ($freshnessIso !== '') {
                $evTime = strtotime($freshnessIso);
                $nowTime = strtotime($nowIso);
                if ($evTime !== false && $nowTime !== false && ($nowTime - $evTime) > $staleAfter) {
                    $evBlockers[] = 'stale_evidence';
                }
            } else {
                $evBlockers[] = 'missing_freshness';
            }

            if ($evBlockers === []) {
                $validEvidence[] = $ev;
            }
        }

        // Quorum check
        if (count($validEvidence) < $quorumFloor) {
            $blockers[] = 'quorum_below_floor:' . count($validEvidence) . '<' . $quorumFloor;
        }

        // Required gate coverage check
        $coveredGates = [];
        foreach ($validEvidence as $ev) {
            $gateId = (string) ($ev['gate_id'] ?? '');
            if ($gateId !== '') {
                $coveredGates[$gateId] = true;
            }
        }

        foreach ($requiredGates as $gate) {
            if (! isset($coveredGates[$gate])) {
                $blockers[] = 'missing_gate_evidence:' . $gate;
            }
        }

        if (count($blockers) > 0) {
            sort($blockers, SORT_STRING);

            return [
                'schema' => self::SCHEMA,
                'verdict' => 'blocked',
                'blockers' => $blockers,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => 'passed',
            'blockers' => [],
        ];
    }
}
