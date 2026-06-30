<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Project-lane verification COURT — evaluates verification evidence for ONE admitted project lane
 * WITHOUT trusting worker self-report. Every verdict stays bound to (project_id, lane_roots,
 * verification_commands, evidence_hashes).
 *
 * VERDICTS:
 *   pass    — verification policy passes AND every evidence record matches project_id AND every
 *             evidence row carries a non-empty evidence_hash AND no leak facts present.
 *   hold    — required rerun evidence is missing (cannot conclude — neither pass nor fail).
 *   blocked — explicit mismatch (project_id drift / missing hash) or leak facts present.
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NO scalar score / rank.
 */
final class AtlasProjectLaneVerificationCourt
{
    public const SCHEMA = 'atlas.multiproject.lane_verification_court.v1';

    public const VOTES_SCHEMA = 'atlas.multiproject.lane_verification_court.votes.v1';

    public const VERDICT_PASS = 'pass';

    public const VERDICT_HOLD = 'hold';

    public const VERDICT_BLOCKED = 'blocked';

    /** @var list<string> */
    public const VOTE_IDS = [
        'isolation',
        'runnable_proof',
        'knowledge_freshness',
        'queue_namespace_safety',
        'evidence_completeness',
    ];

    /**
     * Evaluate lane promotion eligibility as five independent critical votes.
     *
     * Any critical vote that fails blocks lane_promotion_allowed and is listed in
     * blocked_vote_ids with a repair_hint describing what to fix.
     *
     * Votes: isolation, runnable_proof, knowledge_freshness, queue_namespace_safety,
     * evidence_completeness.
     *
     * @param  array<string,mixed>  $input  same shape as adjudicate()
     * @return array<string,mixed>
     */
    public function evaluateVotes(array $input): array
    {
        $projectId = (string) ($input['project_id'] ?? '');
        $records = is_array($input['evidence_records'] ?? null) ? array_values($input['evidence_records']) : [];
        $requiredRerun = is_array($input['required_rerun_evidence'] ?? null) ? array_values(array_map('strval', $input['required_rerun_evidence'])) : [];
        $laneRoots = is_array($input['lane_roots'] ?? null) ? array_map('strval', $input['lane_roots']) : [];

        $hasServerSideGreen = false;
        $hasStale = false;
        $hasProjectIdMismatch = false;
        $pathOutsideLane = false;
        $providedGates = [];

        foreach ($records as $rec) {
            if (! is_array($rec)) {
                continue;
            }
            $recProj = (string) ($rec['project_id'] ?? '');
            if ($recProj !== '' && $recProj !== $projectId) {
                $hasProjectIdMismatch = true;
            }
            if ((bool) ($rec['server_side_green'] ?? $rec['passed'] ?? false)) {
                $hasServerSideGreen = true;
            }
            if (! empty($rec['stale'])) {
                $hasStale = true;
            }
            $path = (string) ($rec['path'] ?? '');
            if ($path !== '' && $laneRoots !== []) {
                $inside = false;
                foreach ($laneRoots as $root) {
                    $root = rtrim($root, '/');
                    if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                        $inside = true;
                        break;
                    }
                }
                if (! $inside) {
                    $pathOutsideLane = true;
                }
            }
            if (isset($rec['gate'])) {
                $providedGates[] = (string) $rec['gate'];
            }
        }

        $missingGates = array_values(array_diff($requiredRerun, $providedGates));

        $votes = [
            [
                'id' => 'isolation',
                'passed' => $laneRoots !== [] && ! $pathOutsideLane,
                'critical' => true,
                'repair_hint' => 'supply lane_roots and ensure all evidence paths are within the declared lane boundaries',
            ],
            [
                'id' => 'runnable_proof',
                'passed' => $hasServerSideGreen,
                'critical' => true,
                'repair_hint' => 'provide at least one server_side_green=true evidence record from an authoritative run',
            ],
            [
                'id' => 'knowledge_freshness',
                'passed' => ! $hasStale,
                'critical' => true,
                'repair_hint' => 'remove or refresh stale evidence records before promoting the lane',
            ],
            [
                'id' => 'queue_namespace_safety',
                'passed' => $projectId !== '' && ! $hasProjectIdMismatch,
                'critical' => true,
                'repair_hint' => 'ensure project_id is set and all evidence records belong to the same project',
            ],
            [
                'id' => 'evidence_completeness',
                'passed' => $missingGates === [],
                'critical' => true,
                'repair_hint' => $missingGates !== []
                    ? 'add rerun evidence for missing gates: '.implode(', ', $missingGates)
                    : 'all required gates present',
            ],
        ];

        $blockedVoteIds = [];
        $repairHints = [];
        foreach ($votes as $vote) {
            if ($vote['critical'] && ! $vote['passed']) {
                $blockedVoteIds[] = $vote['id'];
                $repairHints[$vote['id']] = $vote['repair_hint'];
            }
        }

        return [
            'schema_version' => self::VOTES_SCHEMA,
            'lane_promotion_allowed' => $blockedVoteIds === [],
            'blocked_vote_ids' => $blockedVoteIds,
            'repair_hints' => $repairHints,
            'votes' => $votes,
        ];
    }

    /**
     * @param  array{
     *     project_id:string,
     *     verification_policy?:array<string,mixed>,
     *     evidence_records?:list<array{project_id?:string, gate?:string, evidence_hash?:string, passed?:bool}>,
     *     required_rerun_evidence?:list<string>,
     *     leak_facts?:list<array<string,mixed>>
     * }  $input
     * @return array{schema_version:string, verdict:string, passed:bool, project_id:string, blockers:list<string>, verification_facts:array<string,mixed>, evidence_hashes:list<string>, proof_summary:array<string,int>}
     */
    public function adjudicate(array $input): array
    {
        $projectId = (string) ($input['project_id'] ?? '');
        $policy = is_array($input['verification_policy'] ?? null) ? $input['verification_policy'] : [];
        $records = is_array($input['evidence_records'] ?? null) ? array_values($input['evidence_records']) : [];
        $requiredRerun = is_array($input['required_rerun_evidence'] ?? null) ? array_values(array_map('strval', $input['required_rerun_evidence'])) : [];
        $leakFacts = is_array($input['leak_facts'] ?? null) ? array_values($input['leak_facts']) : [];
        $laneRoots = is_array($input['lane_roots'] ?? null) ? array_map('strval', $input['lane_roots']) : [];

        $blockers = [];
        if ($projectId === '') {
            $blockers[] = 'missing_project_id';
        }

        $policyPassed = (bool) ($policy['allowed'] ?? false);
        if (! $policyPassed) {
            $policyBlockers = (array) ($policy['blockers'] ?? []);
            foreach ($policyBlockers as $b) {
                $blockers[] = 'policy:'.(string) $b;
            }
            if ($policyBlockers === []) {
                // always emit a blocker: no enumerated blockers and policy not passed
                $blockers[] = $policy === [] ? 'policy:missing' : 'policy:not_passed';
            }
        }

        $evidenceHashes = [];
        $providedGates = [];
        foreach ($records as $rec) {
            if (! is_array($rec)) {
                continue;
            }
            $recProj = (string) ($rec['project_id'] ?? '');
            if ($recProj !== '' && $recProj !== $projectId) {
                $blockers[] = 'evidence_project_id_mismatch:'.$recProj;
                continue;
            }
            $hash = (string) ($rec['evidence_hash'] ?? '');
            if ($hash === '') {
                $blockers[] = 'evidence_missing_hash:'.(string) ($rec['gate'] ?? '?');
                continue;
            }
            // server_side_green is the authoritative field; fall back to `passed` for backwards compat.
            $serverSideGreen = (bool) ($rec['server_side_green'] ?? $rec['passed'] ?? false);
            if (! $serverSideGreen) {
                $blockers[] = 'evidence_not_server_side_green:'.(string) ($rec['gate'] ?? '?');
                continue;
            }
            if (! empty($rec['stale'])) {
                $blockers[] = 'evidence_stale:'.(string) ($rec['gate'] ?? '?');
                continue;
            }
            $path = (string) ($rec['path'] ?? '');
            if ($path !== '' && $laneRoots !== []) {
                $inside = false;
                foreach ($laneRoots as $root) {
                    $root = rtrim($root, '/');
                    if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                        $inside = true;
                        break;
                    }
                }
                if (! $inside) {
                    $blockers[] = 'lane_root_mismatch:'.$path;
                    continue;
                }
            }
            $evidenceHashes[] = $hash;
            if (isset($rec['gate'])) {
                $providedGates[] = (string) $rec['gate'];
            }
        }

        $missingRerun = [];
        foreach ($requiredRerun as $gate) {
            if (! in_array($gate, $providedGates, true)) {
                $missingRerun[] = $gate;
            }
        }

        $leakBlockers = [];
        foreach ($leakFacts as $leak) {
            if (! is_array($leak)) {
                continue;
            }
            $leakBlockers[] = 'leak:'.(string) ($leak['kind'] ?? 'unknown');
        }
        foreach ($leakBlockers as $lb) {
            $blockers[] = $lb;
        }

        sort($blockers, SORT_STRING);
        sort($missingRerun, SORT_STRING);
        $evidenceHashes = array_values(array_unique($evidenceHashes));
        sort($evidenceHashes, SORT_STRING);

        $verdict = self::VERDICT_PASS;
        $passed = false;
        if ($blockers !== []) {
            $verdict = self::VERDICT_BLOCKED;
        } elseif ($missingRerun !== []) {
            $verdict = self::VERDICT_HOLD;
        } else {
            $passed = true;
        }

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'passed' => $passed,
            'project_id' => $projectId,
            'blockers' => $blockers,
            'verification_facts' => [
                'policy_passed' => $policyPassed,
                'evidence_record_count' => count($records),
                'missing_rerun' => $missingRerun,
                'leak_count' => count($leakFacts),
            ],
            'evidence_hashes' => $evidenceHashes,
            'proof_summary' => [
                'evidence_count' => count($evidenceHashes),
                'required_rerun_count' => count($requiredRerun),
                'missing_rerun_count' => count($missingRerun),
                'leak_count' => count($leakFacts),
            ],
        ];
    }
}
