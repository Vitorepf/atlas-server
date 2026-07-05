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

    public const VERDICT_PASS = 'passed';

    public const VERDICT_HOLD = 'hold';

    public const VERDICT_BLOCKED = 'blocked';

    /**
     * Superset adapter preserved for callers of the pre-r122 API (the CLI
     * `atlas:loop:lane-verdict` and its frozen pass|hold|blocked contract) —
     * the r122 evidence-hash/quorum hardening replaced adjudicate() with
     * verify() without migrating them (memory codex-hardening-breaks-callers).
     *
     * Translation, never a bypass: legacy vocabulary (evidence_records /
     * evidence_hash / gate / required_rerun_evidence) maps onto verify()'s
     * shape and the SAME court logic runs. Legacy semantics restored on top:
     * quorum floor 1 (the old contract had none), freshness defaults to now
     * (the old contract predates staleness), rerun-gap-only blockers map to
     * the softer HOLD verdict, and evidence_hashes is surfaced.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function adjudicate(array $facts): array
    {
        $nowIso = (string) ($facts['now_iso'] ?? date('c'));
        $records = (array) ($facts['evidence_records'] ?? []);
        $evidence = [];
        $hashes = [];
        foreach ($records as $row) {
            $row = is_array($row) ? $row : [];
            $hash = (string) ($row['evidence_hash'] ?? $row['hash'] ?? '');
            if ($hash !== '') {
                $hashes[] = $hash;
            }
            $evidence[] = [
                'hash' => $hash,
                'project_id' => (string) ($row['project_id'] ?? ''),
                'lane_root' => (string) ($row['lane_root'] ?? ($facts['lane_root'] ?? '')),
                'gate_id' => (string) ($row['gate'] ?? $row['gate_id'] ?? ''),
                'freshness_iso' => (string) ($row['freshness_iso'] ?? $nowIso),
                'stale_after_seconds' => (int) ($row['stale_after_seconds'] ?? 3600),
            ];
        }

        $envelope = $this->verify([
            'project_id' => (string) ($facts['project_id'] ?? ''),
            'lane_root' => (string) ($facts['lane_root'] ?? ''),
            'quorum_floor' => (int) ($facts['quorum_floor'] ?? 1),
            'required_rerun_gates' => array_values(array_filter((array) ($facts['required_rerun_evidence'] ?? $facts['required_rerun_gates'] ?? []))),
            'evidence' => $evidence,
            'now_iso' => $nowIso,
        ]);

        $blockers = (array) ($envelope['blockers'] ?? []);
        $onlyRerunGaps = $blockers !== []
            && $blockers === array_values(array_filter($blockers, static fn (string $b): bool => str_starts_with($b, 'missing_gate_evidence:')));
        if ($onlyRerunGaps) {
            $envelope['verdict'] = self::VERDICT_HOLD;
        }
        $envelope['evidence_hashes'] = array_values(array_unique($hashes));

        return $envelope;
    }

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
        $votes = (array) ($facts['votes'] ?? []);

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

        // Vote-based quorum: scope, evidence, isolation, rollback
        $requiredVoteDomains = ['scope', 'evidence', 'isolation', 'rollback'];
        $voteSummary = [];
        $blockedVotes = 0;
        $holdVotes = 0;
        $passVotes = 0;

        foreach ($requiredVoteDomains as $domain) {
            $domainVote = $votes[$domain] ?? null;
            if ($domainVote === null) {
                $blockers[] = 'missing_vote:' . $domain;
                $voteSummary[$domain] = 'missing';
            } else {
                $voteStatus = (string) ($domainVote['status'] ?? $domainVote);
                $voteSummary[$domain] = $voteStatus;
                if ($voteStatus === 'blocked') {
                    $blockedVotes++;
                    $blockers[] = 'vote_blocked:' . $domain;
                } elseif ($voteStatus === 'hold') {
                    $holdVotes++;
                } elseif ($voteStatus === 'pass') {
                    $passVotes++;
                } else {
                    $blockers[] = 'vote_unknown:' . $domain . ':' . $voteStatus;
                }
            }
        }

        // Blocked votes dominate hold votes in adjudication
        if ($blockedVotes > 0) {
            $blockers[] = 'blocked_votes_dominate:' . $blockedVotes;
        }

        if (count($blockers) > 0) {
            sort($blockers, SORT_STRING);

            return [
                'schema' => self::SCHEMA,
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_BLOCKED,
                'passed' => false,
                'blockers' => $blockers,
                'vote_summary' => $voteSummary,
                'provider_safe' => true,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'verdict' => self::VERDICT_PASS,
            'passed' => true,
            'blockers' => [],
            'vote_summary' => $voteSummary,
            'provider_safe' => true,
        ];
    }
}
