<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ArchitectureCouncil;

/**
 * Pure mapper of organ responsibilities + non-authorities + allowed integration edges for the
 * Self-Construction architecture. Returns FACTS only — no scalar score, no mutation.
 *
 * INPUT (per organ contract):
 *   { organ, integrations:list<{from:string, to:string, action:string}>,
 *     non_authority:list<string> }
 *
 * OUTPUT:
 *   { schema, organs:list<string>, allowed_edges:list<edge>, forbidden_edges:list<{edge, reason}>,
 *     shared_artifacts:list<string>, boundary_risks:list<string> }
 *
 * HARD-CODED FORBIDDEN EDGES (any input matching ⇒ moved to forbidden_edges with the cited reason):
 *   - Worker Swarm -> Merge Governor : execute_merge       — workers may not merge
 *   - Worker Swarm -> Verification Court : grant_verified  — workers may not grant final verification
 *   - Task Fabric -> Verification Court : approve_merit    — task fabric may not approve merit
 *   - Task Fabric -> Merge Governor : execute_merge        — same
 *   - Strategy Council -> Worker Swarm : create_packet     — strategy may not create executable packets
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (allowed/forbidden edges + organs sorted).
 *   - PURE — no I/O.
 */
final class AtlasArchitectureCouncilBoundaryMap
{
    public const SCHEMA = 'atlas.architecturecouncil.boundary_map.v1';

    public const FORBIDDEN_EDGES = [
        'Worker Swarm|Merge Governor|execute_merge' => 'workers_may_not_merge',
        'Worker Swarm|Verification Court|grant_verified' => 'workers_may_not_grant_final_verification',
        'Task Fabric|Verification Court|approve_merit' => 'task_fabric_may_not_approve_merit',
        'Task Fabric|Merge Governor|execute_merge' => 'task_fabric_may_not_merge',
        'Strategy Council|Worker Swarm|create_packet' => 'strategy_may_not_create_executable_packets_directly',
    ];

    /** Remediation hint per forbidden-edge reason — always preserves simple shared-main task execution. */
    public const FORBIDDEN_EDGE_REMEDIATION_HINTS = [
        'workers_may_not_merge' => 'route the merge decision through Merge Governor instead of executing it from Worker Swarm; keep the worker task limited to declaring evidence.',
        'workers_may_not_grant_final_verification' => 'route final verification through Verification Court instead of self-granting from Worker Swarm; keep the worker task limited to submitting evidence.',
        'task_fabric_may_not_approve_merit' => 'route merit approval through Verification Court instead of Task Fabric; keep Task Fabric limited to packet origination.',
        'task_fabric_may_not_merge' => 'route the merge decision through Merge Governor instead of Task Fabric; keep Task Fabric limited to packet origination.',
        'strategy_may_not_create_executable_packets_directly' => 'route packet creation through Task Fabric instead of Strategy Council; keep Strategy Council limited to naming the next theme.',
    ];

    public const SHARED_ARTIFACTS = [
        'context_pack',
        'evidence_ledger',
        'receipts_index',
        'cortex_snapshot',
    ];

    /**
     * @param  list<array{organ?:string, integrations?:list<array{from?:string, to?:string, action?:string}>, non_authority?:list<string>}>  $contracts
     * @return array{schema:string, organs:list<string>, allowed_edges:list<array{from:string, to:string, action:string}>, forbidden_edges:list<array{edge:array{from:string, to:string, action:string}, reason:string}>, shared_artifacts:list<string>, boundary_risks:list<string>}
     */
    public function map(array $contracts): array
    {
        // Pass 1: collect declared organs + non-authority risks.
        $organs = [];
        $risks = [];

        foreach ($contracts as $idx => $c) {
            if (! is_array($c)) {
                continue;
            }
            $organ = trim((string) ($c['organ'] ?? ''));
            if ($organ === '') {
                $risks[] = 'missing_organ:contract_index_'.$idx;

                continue;
            }
            if (! in_array($organ, $organs, true)) {
                $organs[] = $organ;
            }
            $nonAuth = is_array($c['non_authority'] ?? null) ? $c['non_authority'] : [];
            if ($nonAuth === []) {
                $risks[] = 'boundary_risk:'.$organ.':missing_non_authority';
            }
        }

        $declaredOrgans = $organs;

        // Pass 2: process integrations with dedup + undeclared-organ risk.
        $allowed = [];
        $forbidden = [];
        $seenAllowed = [];
        $seenForbidden = [];

        foreach ($contracts as $c) {
            if (! is_array($c) || trim((string) ($c['organ'] ?? '')) === '') {
                continue;
            }
            $integrations = is_array($c['integrations'] ?? null) ? $c['integrations'] : [];
            foreach ($integrations as $edge) {
                if (! is_array($edge)) {
                    continue;
                }
                $from = trim((string) ($edge['from'] ?? ''));
                $to = trim((string) ($edge['to'] ?? ''));
                $action = trim((string) ($edge['action'] ?? ''));
                if ($from === '' || $to === '' || $action === '') {
                    continue;
                }

                if (! in_array($from, $declaredOrgans, true)) {
                    $risks[] = 'boundary_risk:undeclared_organ:'.$from;
                }
                if (! in_array($to, $declaredOrgans, true)) {
                    $risks[] = 'boundary_risk:undeclared_organ:'.$to;
                }

                $key = $from.'|'.$to.'|'.$action;
                if (isset(self::FORBIDDEN_EDGES[$key])) {
                    if (! isset($seenForbidden[$key])) {
                        $seenForbidden[$key] = true;
                        $reason = self::FORBIDDEN_EDGES[$key];
                        $offendingArtifactOrPath = $action;
                        foreach (self::SHARED_ARTIFACTS as $artifact) {
                            if (str_contains($action, $artifact)) {
                                $offendingArtifactOrPath = $artifact;
                                break;
                            }
                        }
                        $forbidden[] = [
                            'edge' => compact('from', 'to', 'action'),
                            'reason' => $reason,
                            'source_boundary' => $from,
                            'target_boundary' => $to,
                            'offending_artifact_or_path' => $offendingArtifactOrPath,
                            'remediation_hint' => self::FORBIDDEN_EDGE_REMEDIATION_HINTS[$reason]
                                ?? 'remove this edge; route the action through the organ authorised for it while keeping the rest of the task on simple shared-main execution.',
                        ];
                    }

                    continue;
                }
                if (! isset($seenAllowed[$key])) {
                    $seenAllowed[$key] = true;
                    $allowed[] = compact('from', 'to', 'action');
                }
            }
        }

        sort($organs, SORT_STRING);
        usort($allowed, static fn (array $a, array $b): int => strcmp($a['from'].'|'.$a['to'].'|'.$a['action'], $b['from'].'|'.$b['to'].'|'.$b['action']));
        usort($forbidden, static function (array $a, array $b): int {
            $ka = $a['edge']['from'].'|'.$a['edge']['to'].'|'.$a['edge']['action'];
            $kb = $b['edge']['from'].'|'.$b['edge']['to'].'|'.$b['edge']['action'];

            return strcmp($ka, $kb);
        });
        sort($risks, SORT_STRING);

        // Build per-organ profiles — after all edges are sorted so inbound/outbound slices are stable.
        $organProfiles = [];
        foreach ($organs as $organ) {
            $contractForOrgan = null;
            foreach ($contracts as $c) {
                if (is_array($c) && trim((string) ($c['organ'] ?? '')) === $organ) {
                    $contractForOrgan = $c;
                    break;
                }
            }

            $responsibilities = is_array($contractForOrgan['responsibilities'] ?? null)
                ? array_values(array_map('strval', $contractForOrgan['responsibilities']))
                : [];
            $nonAuth = is_array($contractForOrgan['non_authority'] ?? null) ? array_values($contractForOrgan['non_authority']) : [];

            $inbound  = array_values(array_filter($allowed, static fn (array $e): bool => $e['to']   === $organ));
            $outbound = array_values(array_filter($allowed, static fn (array $e): bool => $e['from'] === $organ));

            $artifactsTouched = [];
            foreach ([...$inbound, ...$outbound] as $e) {
                foreach (self::SHARED_ARTIFACTS as $artifact) {
                    if (str_contains($e['action'], $artifact) && ! in_array($artifact, $artifactsTouched, true)) {
                        $artifactsTouched[] = $artifact;
                    }
                }
            }
            sort($artifactsTouched, SORT_STRING);

            $organRisks = [];
            if ($nonAuth === []) {
                $organRisks[] = 'missing_non_authority';
            }
            foreach ($forbidden as $fe) {
                if ($fe['edge']['from'] === $organ || $fe['edge']['to'] === $organ) {
                    $organRisks[] = 'forbidden_edge:'.$fe['edge']['from'].'->'.$fe['edge']['to'].':'.$fe['edge']['action'];
                }
            }
            sort($organRisks, SORT_STRING);

            if (in_array('missing_non_authority', $organRisks, true)) {
                $hint = 'declare non_authority list for this organ';
            } elseif ($organRisks !== []) {
                $firstFe = current(array_filter($forbidden, static fn (array $fe): bool => $fe['edge']['from'] === $organ || $fe['edge']['to'] === $organ));
                $hint = 'remove forbidden edge: '.(is_array($firstFe) ? $firstFe['reason'] : 'see forbidden_edges');
            } else {
                $hint = 'no immediate repair needed';
            }

            $organProfiles[$organ] = [
                'responsibilities'       => $responsibilities,
                'non_authority'          => $nonAuth,
                'inbound_edges'          => $inbound,
                'outbound_edges'         => $outbound,
                'shared_artifacts_touched' => $artifactsTouched,
                'boundary_risks'         => $organRisks,
                'next_repair_hint'       => $hint,
            ];
        }

        // Duplicate/overlapping-organ detection: when two or more organs declare the SAME
        // responsibility, that is a consolidation boundary risk — multiple organs claiming
        // ownership over one concern. canonical_owner is the alphabetically-first organ (a
        // simple, deterministic tie-break); the rest are collapse_candidate(s) that should be
        // folded into the canonical owner before new task creation is allowed against them.
        $responsibilityOwners = [];
        foreach ($organs as $organ) {
            foreach ($organProfiles[$organ]['responsibilities'] as $responsibility) {
                $responsibilityOwners[$responsibility][] = $organ;
            }
        }

        $responsibilityOverlaps = [];
        foreach ($responsibilityOwners as $responsibility => $owningOrgans) {
            $distinctOwners = array_values(array_unique($owningOrgans));
            if (count($distinctOwners) < 2) {
                continue;
            }
            sort($distinctOwners, SORT_STRING);
            $canonicalOwner = $distinctOwners[0];
            $collapseCandidates = array_slice($distinctOwners, 1);

            // Merge/delete candidates: a redundant organ with no other organ consuming its
            // outputs is safe to delete outright; one that other organs still depend on must be
            // merged into the canonical owner instead (its consumers need to be repointed).
            $mergeDeleteCandidates = [];
            foreach ($collapseCandidates as $candidate) {
                $consumers = array_values(array_unique(array_map(
                    static fn (array $e): string => $e['from'],
                    $organProfiles[$candidate]['inbound_edges'] ?? [],
                )));
                sort($consumers, SORT_STRING);

                $action = $consumers === [] ? 'delete' : 'merge';
                $riskNotes = $consumers === []
                    ? ['no_known_consumers:safe_to_delete_after_confirming_no_hidden_callers']
                    : ['has_consumers:repoint_to_'.$canonicalOwner.'_before_deleting', 'consumers:'.implode(',', $consumers)];

                $mergeDeleteCandidates[] = [
                    'organ' => $candidate,
                    'consumers' => $consumers,
                    'action' => $action,
                    'risk_notes' => $riskNotes,
                ];
            }

            $responsibilityOverlaps[] = [
                'responsibility' => $responsibility,
                'organs' => $distinctOwners,
                'canonical_owner' => $canonicalOwner,
                'collapse_candidate' => $collapseCandidates,
                'merge_delete_candidates' => $mergeDeleteCandidates,
            ];
        }
        usort($responsibilityOverlaps, static fn (array $a, array $b): int => strcmp($a['responsibility'], $b['responsibility']));

        return [
            'schema' => self::SCHEMA,
            'organs' => $organs,
            'allowed_edges' => $allowed,
            'forbidden_edges' => $forbidden,
            'shared_artifacts' => self::SHARED_ARTIFACTS,
            'boundary_risks' => array_values(array_unique($risks)),
            'organ_profiles' => $organProfiles,
            'responsibility_overlaps' => $responsibilityOverlaps,
            'has_responsibility_overlaps' => $responsibilityOverlaps !== [],
        ];
    }
}
