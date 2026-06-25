<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Bulk respec drafter — accepts a list of queue records and returns SORTED RESPEC DRAFTS grouped by
 * blocker class. NEVER mutates queue records. Deduplicates identical repair fingerprints so repeated
 * poison packets produce ONE reusable template fix.
 *
 * INPUT (per queue record): same shape as {@see AtlasTaskRespecPlanBuilder::build} input —
 *   { packet_id, hidden_poison_facts, missing_files, too_many_deficiencies, contradictory_acceptance,
 *     cli_clobber, autonomy_regression }
 *
 * REPAIR FINGERPRINT = sha256(action + '|' + sort(affected_fields).implode(',') + '|' +
 *                             sort(revalidation_gates).implode(','))
 *
 * OUTPUT:
 *   { schema, drafts:list<{action, affected_fields, revalidation_gates, fingerprint, packet_ids:list<string>}>,
 *     summary:array<string,int>  // per-action count (raw, not deduped) }
 *
 * INVARIANTS:
 *   - DETERMINISTIC ordering: drafts sorted by (action, fingerprint); packet_ids sorted byte-stably.
 *   - SKIPS 'keep' actions (no repair needed).
 *   - PURE.
 */
final class AtlasTaskBulkRespecDraft
{
    public const SCHEMA = 'atlas.task_quality.bulk_respec_draft.v1';

    public function __construct(private readonly AtlasTaskRespecPlanBuilder $planBuilder = new AtlasTaskRespecPlanBuilder) {}

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array{schema:string, drafts:list<array{action:string, affected_fields:list<string>, revalidation_gates:list<string>, fingerprint:string, packet_ids:list<string>}>, summary:array<string,int>}
     */
    public function draft(array $records): array
    {
        $byFingerprint = [];
        $summary = [];

        foreach ($records as $r) {
            if (! is_array($r)) {
                continue;
            }
            $plan = $this->planBuilder->build($r);
            $action = (string) $plan['action'];
            $summary[$action] = ($summary[$action] ?? 0) + 1;
            if ($action === AtlasTaskRespecPlanBuilder::ACTION_KEEP) {
                continue;
            }
            $affected = (array) ($plan['affected_fields'] ?? []);
            $gates = (array) ($plan['revalidation_gates'] ?? []);
            sort($affected, SORT_STRING);
            sort($gates, SORT_STRING);
            $fp = substr(hash('sha256', $action.'|'.implode(',', $affected).'|'.implode(',', $gates)), 0, 16);

            if (! isset($byFingerprint[$fp])) {
                $byFingerprint[$fp] = [
                    'action' => $action,
                    'affected_fields' => $affected,
                    'revalidation_gates' => $gates,
                    'fingerprint' => $fp,
                    'packet_ids' => [],
                ];
            }
            $byFingerprint[$fp]['packet_ids'][] = (string) $plan['packet_id'];
        }

        $drafts = array_values($byFingerprint);
        foreach ($drafts as &$d) {
            $d['packet_ids'] = array_values(array_unique($d['packet_ids']));
            sort($d['packet_ids'], SORT_STRING);
        }
        unset($d);
        usort($drafts, static fn (array $a, array $b): int => strcmp($a['action'].'|'.$a['fingerprint'], $b['action'].'|'.$b['fingerprint']));
        ksort($summary);

        return [
            'schema' => self::SCHEMA,
            'drafts' => $drafts,
            'summary' => $summary,
        ];
    }
}
