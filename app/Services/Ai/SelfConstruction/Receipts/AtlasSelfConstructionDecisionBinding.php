<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Receipts;

/**
 * Proves that downstream FACT CLASSES (task, verification, merge, learning) BIND BACK to a known
 * decision_receipt id via a `decision_ref` field with a matching `decision_hash`. Worker self-report
 * without an authoritative decision binding is NEVER treated as final evidence.
 *
 * INPUT:
 *   $index — output of {@see AtlasSelfConstructionReceiptFactIndex::project()} (has
 *            index[<kind>][<id>] rows). Each downstream row may carry:
 *              { decision_ref:string, decision_hash:string, decision_ts:string }
 *
 * OUTPUT:
 *   { schema, bindings:list<{kind, id, status ∈ {bound,missing_binding,stale_binding,invalid_hash}, reason}> }
 *
 * STATUS RULES:
 *   bound          — row.decision_ref exists in decision_receipts AND row.decision_hash matches the
 *                    decision_receipt.hash.
 *   missing_binding— row has no decision_ref OR decision_ref points to unknown decision_receipt.
 *   invalid_hash   — decision_ref matches but row.decision_hash != decision_receipt.hash.
 *   stale_binding  — decision_ref matches AND hashes match, but row.decision_ts is older than the
 *                    decision_receipt's ts (the decision was REISSUED after this downstream row).
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (bindings sorted by (kind, id)).
 *   - PURE.
 */
final class AtlasSelfConstructionDecisionBinding
{
    public const SCHEMA = 'atlas.selfconstruction.decision_binding.v1';

    public const STATUS_BOUND = 'bound';

    public const STATUS_MISSING = 'missing_binding';

    public const STATUS_STALE = 'stale_binding';

    public const STATUS_INVALID_HASH = 'invalid_hash';

    /** A row carrying more than one distinct decision_ref (opt-in `decision_refs` list) — the
     *  binding is ambiguous and can never be trusted as authoritative evidence. */
    public const STATUS_AMBIGUOUS = 'ambiguous_binding';

    /** Confidence assigned per binding status — 1.0 only for a fully bound, fresh receipt. */
    private const CONFIDENCE_BY_STATUS = [
        self::STATUS_BOUND => 1.0,
        self::STATUS_STALE => 0.5,
        self::STATUS_AMBIGUOUS => 0.2,
        self::STATUS_INVALID_HASH => 0.0,
        self::STATUS_MISSING => 0.0,
        'unknown_kind' => 0.0,
    ];

    /** Downstream kinds that MUST bind back to a decision receipt. */
    public const BINDING_KINDS = ['task_receipts', 'verification_receipts', 'merge_receipts', 'learning_receipts'];

    /**
     * @param  array{index?:array<string,array<string,array<string,mixed>>>}  $factIndex
     * @return array{schema:string, bindings:list<array{kind:string, id:string, status:string, reason:string}>}
     */
    public function verify(array $factIndex): array
    {
        $index = is_array($factIndex['index'] ?? null) ? $factIndex['index'] : [];
        $decisions = is_array($index['decision_receipts'] ?? null) ? $index['decision_receipts'] : [];

        $bindings = [];
        foreach (self::BINDING_KINDS as $kind) {
            $rows = is_array($index[$kind] ?? null) ? $index[$kind] : [];
            foreach ($rows as $id => $row) {
                if (! is_array($row)) {
                    continue;
                }
                // Opt-in ambiguity check: a row citing more than one distinct decision_refs entry
                // can never be authoritatively bound to a single decision.
                $multiRefs = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array) ($row['decision_refs'] ?? []),
                ), static fn (string $r): bool => $r !== '')));
                if (count($multiRefs) > 1) {
                    $bindings[] = ['kind' => $kind, 'id' => (string) $id, 'status' => self::STATUS_AMBIGUOUS, 'reason' => 'multiple_distinct_decision_refs:'.implode(',', $multiRefs)];

                    continue;
                }

                $ref = (string) ($row['decision_ref'] ?? '');
                if ($ref === '' || ! isset($decisions[$ref])) {
                    $bindings[] = ['kind' => $kind, 'id' => (string) $id, 'status' => self::STATUS_MISSING, 'reason' => $ref === '' ? 'no_decision_ref' : 'decision_ref_unknown:'.$ref];

                    continue;
                }
                $decision = $decisions[$ref];
                $rowHash = (string) ($row['decision_hash'] ?? '');
                $decHash = (string) ($decision['hash'] ?? '');
                if ($rowHash === '' || $decHash === '' || $rowHash !== $decHash) {
                    $bindings[] = ['kind' => $kind, 'id' => (string) $id, 'status' => self::STATUS_INVALID_HASH, 'reason' => 'decision_hash_mismatch'];

                    continue;
                }
                $rowTs = (string) ($row['decision_ts'] ?? '');
                $decTs = (string) ($decision['ts'] ?? '');
                if ($rowTs !== '' && $decTs !== '' && strcmp($decTs, $rowTs) > 0) {
                    $bindings[] = ['kind' => $kind, 'id' => (string) $id, 'status' => self::STATUS_STALE, 'reason' => 'decision_ts_newer_than_row_ts'];

                    continue;
                }
                $bindings[] = ['kind' => $kind, 'id' => (string) $id, 'status' => self::STATUS_BOUND, 'reason' => 'ref+hash matched'];
            }
        }

        // Surface unknown receipt kinds so callers cannot hide evidence in unexpected collections.
        $knownKeys = array_merge(self::BINDING_KINDS, ['decision_receipts']);
        foreach ($index as $kind => $rows) {
            if (in_array($kind, $knownKeys, true) || ! is_array($rows)) {
                continue;
            }
            foreach ($rows as $id => $row) {
                $bindings[] = ['kind' => $kind, 'id' => (string) $id, 'status' => 'unknown_kind', 'reason' => 'kind_not_in_binding_kinds'];
            }
        }

        usort($bindings, static fn (array $a, array $b): int => strcmp($a['kind'], $b['kind']) ?: strcmp($a['id'], $b['id']));

        // Confidence + proof_gaps are derived from status alone — never from raw row/decision
        // content — so nothing provider-sensitive can leak through this envelope.
        $proofGaps = [];
        foreach ($bindings as &$binding) {
            $binding['confidence'] = self::CONFIDENCE_BY_STATUS[$binding['status']] ?? 0.0;
            if ($binding['status'] !== self::STATUS_BOUND) {
                $proofGaps[] = ['kind' => $binding['kind'], 'id' => $binding['id'], 'gap' => $binding['status'], 'reason' => $binding['reason']];
            }
        }
        unset($binding);

        $summary = array_count_values(array_column($bindings, 'status'));
        ksort($summary, SORT_STRING);

        $criticalStatuses = [self::STATUS_MISSING, self::STATUS_STALE, self::STATUS_INVALID_HASH, self::STATUS_AMBIGUOUS, 'unknown_kind'];
        $criticalSummary = [];
        $totalCritical = 0;
        foreach ($criticalStatuses as $status) {
            $count = (int) ($summary[$status] ?? 0);
            $criticalSummary[$status] = $count;
            $totalCritical += $count;
        }
        $criticalSummary['total_critical'] = $totalCritical;

        $totalBindings = count($bindings);
        $boundCount = (int) ($summary[self::STATUS_BOUND] ?? 0);
        $overallConfidence = $totalBindings > 0 ? round($boundCount / $totalBindings, 3) : 0.0;

        return [
            'schema' => self::SCHEMA,
            'bindings' => $bindings,
            'summary' => $summary,
            'critical_summary' => $criticalSummary,
            'proof_gaps' => $proofGaps,
            'overall_confidence' => $overallConfidence,
        ];
    }
}
