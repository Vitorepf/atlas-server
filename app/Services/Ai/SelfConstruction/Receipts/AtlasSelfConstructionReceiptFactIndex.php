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

        $decisionLinkSummary = ['bound_looking' => 0, 'blank' => 0, 'unknown' => 0];

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
                    $decisionRef = trim((string) ($r['decision_ref'] ?? ''));
                    $decisionHash = trim((string) ($r['decision_hash'] ?? ''));
                    if (array_key_exists('decision_ref', $r) && $decisionRef === '') {
                        $blockers[] = $kind.':blank_decision_ref:'.$id;
                    }
                    if (array_key_exists('decision_hash', $r) && $decisionHash === '') {
                        $blockers[] = $kind.':blank_decision_hash:'.$id;
                    }
                    if ($decisionRef !== '' && $decisionHash !== '') {
                        $decisionLinkSummary['bound_looking']++;
                    } elseif (array_key_exists('decision_ref', $r) || array_key_exists('decision_hash', $r)) {
                        $decisionLinkSummary['blank']++;
                    } else {
                        $decisionLinkSummary['unknown']++;
                    }
                }
                $index[$kind][$id] = $r;
            }
            ksort($index[$kind]);
            $summary[$kind] = count($index[$kind]);
        }

        sort($blockers, SORT_STRING);

        $joinIndexes = $this->buildJoinIndexes($index);

        return [
            'schema' => self::SCHEMA,
            'index' => $index,
            'blockers' => $blockers,
            'summary' => $summary,
            'decision_link_summary' => $decisionLinkSummary,
            'by_task' => $joinIndexes['by_task'],
            'by_commit' => $joinIndexes['by_commit'],
            'by_worker' => $joinIndexes['by_worker'],
            'by_decision' => $joinIndexes['by_decision'],
            'by_capability' => $joinIndexes['by_capability'],
            'missing_evidence_gaps' => $joinIndexes['missing_evidence_gaps'],
        ];
    }

    /**
     * Secondary join indexes over the already-deduplicated primary index — task_packet_id,
     * commit_sha, worker_id, decision_ref and capability, when a row declares them. A row that
     * declares none of these join facts is a missing-evidence gap: the receipt exists but can't
     * be joined to task/commit/worker/decision/capability context by downstream learning.
     *
     * @param  array<string,array<string,array<string,mixed>>>  $index
     * @return array{by_task:array<string,list<array{kind:string,id:string}>>, by_commit:array<string,list<array{kind:string,id:string}>>, by_worker:array<string,list<array{kind:string,id:string}>>, by_decision:array<string,list<array{kind:string,id:string}>>, by_capability:array<string,list<array{kind:string,id:string}>>, missing_evidence_gaps:list<string>}
     */
    private function buildJoinIndexes(array $index): array
    {
        $buckets = [
            'by_task' => [],
            'by_commit' => [],
            'by_worker' => [],
            'by_decision' => [],
            'by_capability' => [],
        ];
        $fieldByBucket = [
            'by_task' => 'task_packet_id',
            'by_commit' => 'commit_sha',
            'by_worker' => 'worker_id',
            'by_decision' => 'decision_ref',
            'by_capability' => 'capability',
        ];
        $gaps = [];

        foreach (self::KINDS as $kind) {
            foreach ($index[$kind] as $id => $r) {
                $ref = ['kind' => $kind, 'id' => (string) $id];
                $joinedAny = false;
                foreach ($fieldByBucket as $bucketName => $field) {
                    $value = trim((string) ($r[$field] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $joinedAny = true;
                    $buckets[$bucketName][$value][] = $ref;
                }
                if (! $joinedAny) {
                    $gaps[] = $kind.':'.$id;
                }
            }
        }

        foreach ($buckets as $bucketName => $bucket) {
            ksort($bucket);
            foreach ($bucket as $value => $refs) {
                usort($refs, static fn (array $a, array $b): int => $a['kind'] === $b['kind'] ? strcmp($a['id'], $b['id']) : strcmp($a['kind'], $b['kind']));
                $bucket[$value] = $refs;
            }
            $buckets[$bucketName] = $bucket;
        }
        sort($gaps, SORT_STRING);

        return [
            'by_task' => $buckets['by_task'],
            'by_commit' => $buckets['by_commit'],
            'by_worker' => $buckets['by_worker'],
            'by_decision' => $buckets['by_decision'],
            'by_capability' => $buckets['by_capability'],
            'missing_evidence_gaps' => $gaps,
        ];
    }
}
