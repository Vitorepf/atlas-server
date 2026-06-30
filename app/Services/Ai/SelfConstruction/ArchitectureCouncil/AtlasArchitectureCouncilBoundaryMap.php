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
                        $forbidden[] = ['edge' => compact('from', 'to', 'action'), 'reason' => self::FORBIDDEN_EDGES[$key]];
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

        return [
            'schema' => self::SCHEMA,
            'organs' => $organs,
            'allowed_edges' => $allowed,
            'forbidden_edges' => $forbidden,
            'shared_artifacts' => self::SHARED_ARTIFACTS,
            'boundary_risks' => array_values(array_unique($risks)),
        ];
    }
}
