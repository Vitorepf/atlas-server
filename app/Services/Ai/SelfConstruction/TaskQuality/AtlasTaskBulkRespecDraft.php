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
 *   { schema, drafts:list<{action, safe_action, affected_fields, revalidation_gates, fingerprint,
 *     representative_packet_id, representative_objective_patch, allowed_files_repair_hint,
 *     acceptance_repair_hint, evidence_repair_hint, total_packet_count, packet_ids:list<string>}>,
 *     summary:array<string,int>  // per-action count (raw, not deduped) }
 *
 * SAFE_ACTION — each plan-builder action maps to ONE of respec|retire|quarantine. Unrepairable families
 * (quarantine_candidate, rewrite_objective i.e. contradictory acceptance) NEVER receive a respec-style
 * repair hint — they only carry the safe_action, matching {@see AtlasTaskBlockedPacketFamilyClassifier}'s
 * forbidden_target/contradictory_acceptance → retire verdict. Repairable families (add-missing-file,
 * split, give-back) get GENERIC (not packet-specific) repair hints — the repair PATTERN repeats across
 * packets even though file names differ, so the hint stays reusable across the whole dedup family.
 *
 * INVARIANTS:
 *   - DETERMINISTIC ordering: drafts sorted by (action, fingerprint); packet_ids sorted byte-stably.
 *   - SKIPS 'keep' actions (no repair needed).
 *   - PURE.
 */
final class AtlasTaskBulkRespecDraft
{
    public const SCHEMA = 'atlas.task_quality.bulk_respec_draft.v1';

    /** Hard cap on packet_ids per draft family; total_packet_count still reflects the raw count. */
    public const MAX_PACKET_IDS_PER_FAMILY = 20;

    /** Hard cap on replacement_count per safe wave — bounds blast radius per wave. */
    public const MAX_SAFE_WAVE_SIZE = 8;

    /** Lower value = higher severity → appears first in output. */
    private const SEVERITY_ORDER = [
        'quarantine_candidate' => 1,
        'give_back_hint' => 2,
        'rewrite_objective' => 3,
        'split_task_candidate' => 4,
        'add_missing_allowed_file_candidate' => 5,
    ];

    public const SAFE_ACTION_RESPEC = 'respec';

    public const SAFE_ACTION_RETIRE = 'retire';

    public const SAFE_ACTION_QUARANTINE = 'quarantine';

    /** Plan-builder action → conservative bulk-safe verdict. Unrepairable actions never get a respec hint. */
    private const SAFE_ACTION_MAP = [
        'quarantine_candidate' => self::SAFE_ACTION_QUARANTINE,
        // contradictory acceptance is unrepairable by file-edit alone — matches
        // AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CONTRADICTORY_ACCEPTANCE → retire.
        'rewrite_objective' => self::SAFE_ACTION_RETIRE,
        'add_missing_allowed_file_candidate' => self::SAFE_ACTION_RESPEC,
        'split_task_candidate' => self::SAFE_ACTION_RESPEC,
        'give_back_hint' => self::SAFE_ACTION_RESPEC,
    ];

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
            $ids = array_values(array_unique($d['packet_ids']));
            sort($ids, SORT_STRING);
            $d['representative_packet_id'] = $ids[0] ?? '';
            $d['total_packet_count'] = count($ids);
            $d['packet_ids'] = array_slice($ids, 0, self::MAX_PACKET_IDS_PER_FAMILY);
            $d['safe_action'] = self::SAFE_ACTION_MAP[$d['action']] ?? self::SAFE_ACTION_QUARANTINE;
            $d['representative_objective_patch'] = $this->objectivePatchHint($d['action'], $d['affected_fields']);
            $d['allowed_files_repair_hint'] = $this->allowedFilesRepairHint($d['action']);
            $d['acceptance_repair_hint'] = $this->acceptanceRepairHint($d['action']);
            $d['evidence_repair_hint'] = $this->evidenceRepairHint($d['action']);
        }
        unset($d);
        usort($drafts, function (array $a, array $b): int {
            $sa = self::SEVERITY_ORDER[$a['action']] ?? 99;
            $sb = self::SEVERITY_ORDER[$b['action']] ?? 99;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $c = strcmp($a['action'], $b['action']);

            return $c !== 0 ? $c : strcmp($a['fingerprint'], $b['fingerprint']);
        });
        ksort($summary);

        return [
            'schema' => self::SCHEMA,
            'drafts' => $drafts,
            'summary' => $summary,
        ];
    }

    /** @param  list<string>  $affectedFields */
    private function objectivePatchHint(string $action, array $affectedFields): ?string
    {
        if ($action !== AtlasTaskRespecPlanBuilder::ACTION_REWRITE_OBJECTIVE) {
            return null;
        }

        return 'Rewrite objective and '.implode(', ', $affectedFields)
            .' so they no longer contradict each other; keep exactly one concrete, testable behavior per packet.';
    }

    private function allowedFilesRepairHint(string $action): ?string
    {
        return match ($action) {
            AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE => 'Add the missing implementation/test counterpart file(s) to allowed_files so the impl+test pair is complete.',
            AtlasTaskRespecPlanBuilder::ACTION_SPLIT => 'Split allowed_files into Atlas-native replacement slices, each with a disjoint, concrete scope.',
            default => null,
        };
    }

    private function acceptanceRepairHint(string $action): ?string
    {
        return match ($action) {
            AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE,
            AtlasTaskRespecPlanBuilder::ACTION_SPLIT => 'Add a runnable acceptance criterion, e.g. "/opt/homebrew/bin/php artisan test --filter=<ClassName>Test exits 0".',
            default => null,
        };
    }

    private function evidenceRepairHint(string $action): ?string
    {
        return match ($action) {
            AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE,
            AtlasTaskRespecPlanBuilder::ACTION_SPLIT => 'Ensure required_evidence includes tests_or_gates_result so the corrected file pair can be proven.',
            AtlasTaskRespecPlanBuilder::ACTION_GIVE_BACK => 'Ensure required_evidence is complete before the next give_back attempt is graded.',
            default => null,
        };
    }

    /**
     * Groups only SAFE-to-bulk-respec records (safe_action=respec) into collision-free, bounded-risk
     * waves. Records whose plan resolves to retire/quarantine are unrecoverable by bulk file edits
     * alone and are excluded from every wave, counted only in skipped_unrecoverable_count. Records
     * missing usable target files cannot be safely grouped either, so they are counted in the first
     * wave's skipped_count for their family instead of being placed blind.
     *
     * A wave splits when: (a) two records in the same family declare an overlapping target file
     * (collision), or (b) the wave would otherwise exceed MAX_SAFE_WAVE_SIZE (risk becomes too broad).
     *
     * @param  list<array<string,mixed>>  $records
     * @return array{schema:string, waves:list<array{wave_id:string, family:string, replacement_count:int, skipped_count:int, collision_targets:list<string>, risk_band:string}>, skipped_unrecoverable_count:int}
     */
    public function planSafeWaves(array $records): array
    {
        $byFamily = [];
        $skippedNoTargetByFamily = [];
        $skippedUnrecoverable = 0;

        foreach ($records as $r) {
            if (! is_array($r) || ! isset($r['packet_id'])) {
                continue;
            }
            $plan = $this->planBuilder->build($r);
            $action = (string) $plan['action'];
            if ($action === AtlasTaskRespecPlanBuilder::ACTION_KEEP) {
                continue;
            }
            $safeAction = self::SAFE_ACTION_MAP[$action] ?? self::SAFE_ACTION_QUARANTINE;
            if ($safeAction !== self::SAFE_ACTION_RESPEC) {
                $skippedUnrecoverable++;
                continue;
            }

            $targets = array_values(array_unique(array_map(
                'strval',
                (array) ($r['target_files'] ?? $r['missing_files'] ?? []),
            )));
            if ($targets === []) {
                $skippedNoTargetByFamily[$action] = ($skippedNoTargetByFamily[$action] ?? 0) + 1;
                continue;
            }

            $byFamily[$action][] = ['packet_id' => (string) $plan['packet_id'], 'targets' => $targets];
        }

        ksort($byFamily);
        $waves = [];
        $waveSeq = 0;

        foreach ($byFamily as $family => $entries) {
            $targetCounts = [];
            foreach ($entries as $e) {
                foreach ($e['targets'] as $t) {
                    $targetCounts[$t] = ($targetCounts[$t] ?? 0) + 1;
                }
            }
            $collisionTargets = array_values(array_filter(
                array_keys($targetCounts),
                static fn (string $t): bool => $targetCounts[$t] > 1,
            ));
            sort($collisionTargets, SORT_STRING);

            $chunks = $this->splitIntoCollisionFreeChunks($entries, self::MAX_SAFE_WAVE_SIZE);
            $familySkipped = $skippedNoTargetByFamily[$family] ?? 0;

            foreach ($chunks as $i => $chunkEntries) {
                $waveSeq++;
                $count = count($chunkEntries);
                $riskBand = $count >= self::MAX_SAFE_WAVE_SIZE
                    ? 'high'
                    : ($count > (int) ceil(self::MAX_SAFE_WAVE_SIZE / 2) ? 'medium' : 'low');

                $waves[] = [
                    'wave_id' => sprintf('wave-%03d', $waveSeq),
                    'family' => $family,
                    'replacement_count' => $count,
                    'skipped_count' => $i === 0 ? $familySkipped : 0,
                    'collision_targets' => $collisionTargets,
                    'risk_band' => $riskBand,
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'waves' => $waves,
            'skipped_unrecoverable_count' => $skippedUnrecoverable,
        ];
    }

    /**
     * Greedy collision-free, size-bounded chunking: an entry starts a new chunk when it collides
     * with a target already used in the current chunk, or the chunk is already at capacity.
     *
     * @param  list<array{packet_id:string, targets:list<string>}>  $entries
     * @return list<list<array{packet_id:string, targets:list<string>}>>
     */
    private function splitIntoCollisionFreeChunks(array $entries, int $maxWaveSize): array
    {
        usort($entries, static fn (array $a, array $b): int => strcmp($a['packet_id'], $b['packet_id']));

        $chunks = [];
        $current = [];
        $usedTargets = [];

        foreach ($entries as $e) {
            $collides = false;
            foreach ($e['targets'] as $t) {
                if (isset($usedTargets[$t])) {
                    $collides = true;
                    break;
                }
            }

            if ($collides || count($current) >= $maxWaveSize) {
                if ($current !== []) {
                    $chunks[] = $current;
                }
                $current = [];
                $usedTargets = [];
            }

            $current[] = $e;
            foreach ($e['targets'] as $t) {
                $usedTargets[$t] = true;
            }
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
