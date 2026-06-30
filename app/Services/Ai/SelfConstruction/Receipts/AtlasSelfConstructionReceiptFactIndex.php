<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Receipts;

/**
 * Read-only projector over SUPPLIED receipt facts for one Self-Construction cycle. Indexes facts by
 * stable id and flags integrity issues (duplicate ids / missing hash / missing timestamp / missing
 * chain refs). NEVER creates a ledger; NEVER persists.
 *
 * INPUT FACTS (each list is optional):
 *   { decision_receipts:list<{id, hash, ts}>,
 *     task_receipts:list<{id, hash, ts, task_packet_id?}>,
 *     lease_receipts:list<{id, hash, ts}>,
 *     verification_receipts:list<{id, hash, ts, chain_ref?:string}>,
 *     merge_receipts:list<{id, hash, ts, chain_ref?:string}>,
 *     rollback_receipts:list<{id, hash, ts}>,
 *     knowledge_receipts:list<{id, hash, ts}>,
 *     learning_receipts:list<{id, hash, ts}> }
 *
 * OUTPUT:
 *   { schema, index:array<kind,array<id,row>>, blockers:list<string>, summary:array<kind,int> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: id maps and blockers sorted byte-stably.
 *   - PURE.
 */
final class AtlasSelfConstructionReceiptFactIndex
{
    public const SCHEMA = 'atlas.selfconstruction.receipt_fact_index.v1';

    public const KINDS = [
        'decision_receipts',
        'task_receipts',
        'lease_receipts',
        'verification_receipts',
        'merge_receipts',
        'rollback_receipts',
        'knowledge_receipts',
        'learning_receipts',
    ];

    /** Kinds whose rows MAY declare a chain_ref (verification/merge link upstream to a verdict). */
    public const CHAIN_LINKED_KINDS = ['verification_receipts', 'merge_receipts'];

    /** Downstream kinds whose decision_ref and decision_hash fields must not be blank when present. */
    public const DECISION_LINKED_KINDS = ['task_receipts', 'verification_receipts', 'merge_receipts', 'learning_receipts'];

    /**
     * @param  array<string,list<array<string,mixed>>>  $facts
     * @return array{schema:string, index:array<string,array<string,array<string,mixed>>>, blockers:list<string>, summary:array<string,int>}
     */
    public function project(array $facts): array
    {
        $blockers = [];
        $index = [];
        $summary = [];

        $allDecisionIds = [];
        foreach (($facts['decision_receipts'] ?? []) as $r) {
            if (is_array($r) && isset($r['id'])) {
                $allDecisionIds[(string) $r['id']] = true;
            }
        }

        foreach (self::KINDS as $kind) {
            $rows = is_array($facts[$kind] ?? null) ? array_values($facts[$kind]) : [];
            $index[$kind] = [];
            $seen = [];
            foreach ($rows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $id = trim((string) ($r['id'] ?? ''));
                if ($id === '') {
                    $blockers[] = $kind.':missing_id';

                    continue;
                }
                if (isset($seen[$id])) {
                    $blockers[] = $kind.':duplicate_id:'.$id;

                    continue;
                }
                $seen[$id] = true;
                if (trim((string) ($r['hash'] ?? '')) === '') {
                    $blockers[] = $kind.':missing_hash:'.$id;
                }
                if (trim((string) ($r['ts'] ?? '')) === '') {
                    $blockers[] = $kind.':missing_ts:'.$id;
                }
                if (in_array($kind, self::CHAIN_LINKED_KINDS, true) && isset($r['chain_ref'])) {
                    $chainRef = (string) $r['chain_ref'];
                    if ($chainRef !== '' && ! isset($allDecisionIds[$chainRef])) {
                        $blockers[] = $kind.':chain_ref_unknown:'.$chainRef;
                    }
                }
                if (in_array($kind, self::DECISION_LINKED_KINDS, true)) {
                    if (array_key_exists('decision_ref', $r) && trim((string) ($r['decision_ref'] ?? '')) === '') {
                        $blockers[] = $kind.':blank_decision_ref:'.$id;
                    }
                    if (array_key_exists('decision_hash', $r) && trim((string) ($r['decision_hash'] ?? '')) === '') {
                        $blockers[] = $kind.':blank_decision_hash:'.$id;
                    }
                }
                $index[$kind][$id] = $r;
            }
            ksort($index[$kind]);
            $summary[$kind] = count($index[$kind]);
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'index' => $index,
            'blockers' => $blockers,
            'summary' => $summary,
        ];
    }
}
