<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * L6-12 keystone gate: prove CROSS-WEEK continuity with measured recall lift.
 *
 * The existing {@see LongHorizonContinuityPackEmitterService} +
 * {@see LongHorizonContinuityCertificationService} prove "can the Atlas resume
 * RIGHT NOW" — a continuation pack + replay manifest written and certified in
 * the same instant. That is resume-readiness, NOT the L6-12 DoD.
 *
 * The L6-12 DoD is temporal: "o recall de uma decisão de 3 semanas atrás
 * influencia uma certificação de hoje, medido. A/B mostrando lift de recall de
 * memória antiga em tasks novas." That requires REAL elapsed calendar time —
 * a memory ref first captured WEEKS ago, recalled into a task TODAY, with a
 * measured A/B lift between the recall arm and the no-recall arm.
 *
 * This gate measures exactly that against the REAL persisted continuation
 * packs. It is read-only and fail-closed and — per the anti-over-claim law —
 * it NEVER fabricates elapsed time, NEVER mints recall events, and NEVER
 * inflates lift. Every age is derived from the real `created_at` of a
 * persisted pack. When the calendar window has not yet elapsed (the soak and
 * packs are days old, not weeks), the gate honestly returns
 * `insufficient_cross_week_recall_evidence` with `completion_claim_allowed=false`.
 * It auto-greens the instant real ≥3-week-old recall data exists.
 *
 * Schema: `atlas.long_horizon.cross_week_recall_lift_gate.v1`.
 */
final class LongHorizonCrossWeekRecallLiftGateService
{
    public const SCHEMA_VERSION = 'atlas.long_horizon.cross_week_recall_lift_gate.v1';

    public const STATUS_READY = 'cross_week_recall_lift_proven';

    public const STATUS_INSUFFICIENT = 'insufficient_cross_week_recall_evidence';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Evaluate the cross-week recall-lift gate for a scope.
     *
     * @param  array<string,mixed>  $options  {
     *   enabled: ?bool,
     *   scope_type: ?string (default canon long_horizon),
     *   scope_id: ?string (default 'fable-lista-6'),
     *   min_recall_age_days: ?int (default 21 = 3 weeks),
     *   recent_window_days: ?int (default 7 — what counts as a "new" task),
     *   min_calendar_span_days: ?int (default 21),
     *   min_recall_events: ?int (default 1),
     *   min_new_tasks: ?int (default 1),
     *   min_recall_lift: ?float (default 0.01),
     *   now: ?CarbonImmutable (testing seam ONLY; ages still derive from real created_at)
     * }
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = (array) config('atlas.long_horizon.cross_week_recall_lift_gate', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $scopeType = trim((string) ($options['scope_type'] ?? $cfg['scope_type'] ?? AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON));
        $scopeId = trim((string) ($options['scope_id'] ?? $cfg['scope_id'] ?? 'fable-lista-6'));
        $minRecallAgeDays = max(1, (int) ($options['min_recall_age_days'] ?? $cfg['min_recall_age_days'] ?? 21));
        $recentWindowDays = max(1, (int) ($options['recent_window_days'] ?? $cfg['recent_window_days'] ?? 7));
        $minCalendarSpanDays = max(1, (int) ($options['min_calendar_span_days'] ?? $cfg['min_calendar_span_days'] ?? 21));
        $minRecallEvents = max(1, (int) ($options['min_recall_events'] ?? $cfg['min_recall_events'] ?? 1));
        $minNewTasks = max(1, (int) ($options['min_new_tasks'] ?? $cfg['min_new_tasks'] ?? 1));
        $minRecallLift = max(0.0, (float) ($options['min_recall_lift'] ?? $cfg['min_recall_lift'] ?? 0.01));
        $now = ($options['now'] ?? null) instanceof CarbonImmutable ? $options['now'] : CarbonImmutable::now('UTC');

        $config = [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'min_recall_age_days' => $minRecallAgeDays,
            'recent_window_days' => $recentWindowDays,
            'min_calendar_span_days' => $minCalendarSpanDays,
            'min_recall_events' => $minRecallEvents,
            'min_new_tasks' => $minNewTasks,
            'min_recall_lift' => $minRecallLift,
        ];

        if (! $enabled) {
            return $this->payload(self::STATUS_DISABLED, false, ['cross_week_recall_lift_gate_disabled'], null, $config);
        }

        if (! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            return $this->payload(self::STATUS_BLOCKED, false, ['scope_type_not_in_long_horizon_canon'], null, $config);
        }

        if (! DatabaseTableAvailability::has('atlas_long_horizon_continuation_packs')) {
            return $this->payload(self::STATUS_BLOCKED, false, ['continuation_pack_table_missing'], null, $config);
        }

        try {
            $packs = $this->loadPacks($scopeType, $scopeId);
        } catch (Throwable $e) {
            return $this->payload(self::STATUS_BLOCKED, false, ['continuation_pack_query_failed:'.$e::class], null, $config);
        }

        $measurement = $this->measure($packs, $now, $minRecallAgeDays, $recentWindowDays);

        $blockers = $this->deriveBlockers($measurement, $config);

        $status = $blockers === [] ? self::STATUS_READY : self::STATUS_INSUFFICIENT;

        return $this->payload($status, $blockers === [], $blockers, $measurement, $config);
    }

    /**
     * Load persisted packs as normalised, time-stamped rows. The age is taken
     * STRICTLY from the real `created_at` — never injected, never backfilled.
     *
     * @return list<array{uuid:string,created_at:CarbonImmutable,age_days:float,refs:list<string>,certified:bool}>
     */
    private function loadPacks(string $scopeType, ?string $scopeId): array
    {
        $query = AtlasLongHorizonContinuationPack::query()
            ->where('scope_type', $scopeType)
            ->orderBy('created_at');
        if ($scopeId !== null && $scopeId !== '') {
            $query->where('scope_id', $scopeId);
        }

        $out = [];
        foreach ($query->get() as $pack) {
            $createdAt = $pack->created_at;
            if (! $createdAt instanceof \DateTimeInterface) {
                // A pack with no real persisted timestamp cannot contribute an
                // honest age — exclude it rather than fabricate one.
                continue;
            }
            $out[] = [
                'uuid' => (string) $pack->uuid,
                'created_at' => CarbonImmutable::instance($createdAt),
                'refs' => $this->extractRefs(is_array($pack->evidence_refs) ? $pack->evidence_refs : []),
                'safe_resume_mode' => (string) ($pack->safe_resume_mode ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Measure the cross-week recall lift from real persisted packs.
     *
     * A "new task" is a pack persisted within `recentWindowDays` of `now`.
     * A new task "recalls old memory" when it carries an evidence ref whose
     * path first appeared in a pack persisted at least `minRecallAgeDays` ago.
     * The lift is the certification-readiness advantage of the recall arm over
     * the no-recall arm among the new tasks.
     *
     * @param  list<array{uuid:string,created_at:CarbonImmutable,refs:list<string>,safe_resume_mode:string}>  $packs
     * @return array<string,mixed>
     */
    private function measure(array $packs, CarbonImmutable $now, int $minRecallAgeDays, int $recentWindowDays): array
    {
        $packCount = count($packs);
        if ($packCount === 0) {
            return $this->emptyMeasurement($now);
        }

        $oldestCreated = $packs[0]['created_at'];
        $newestCreated = $packs[$packCount - 1]['created_at'];
        $calendarSpanDays = $this->daysBetween($oldestCreated, $newestCreated);

        // First-seen calendar age of each evidence-ref path: the earliest pack
        // (by real created_at) that carried this ref. This is the genuine age
        // of the memory, not a per-pack property.
        $refFirstSeen = [];
        foreach ($packs as $pack) {
            foreach ($pack['refs'] as $ref) {
                if (! isset($refFirstSeen[$ref]) || $pack['created_at']->lessThan($refFirstSeen[$ref])) {
                    $refFirstSeen[$ref] = $pack['created_at'];
                }
            }
        }

        $recentThreshold = $now->subDays($recentWindowDays);

        $newTasks = 0;
        $recallArmTasks = 0;
        $recallArmCertified = 0;
        $noRecallArmTasks = 0;
        $noRecallArmCertified = 0;
        $recallEvents = 0;
        $maxRecallAgeDays = 0.0;
        $recalledRefs = [];

        foreach ($packs as $pack) {
            // Only packs in the recent window count as "new tasks".
            if ($pack['created_at']->lessThan($recentThreshold)) {
                continue;
            }
            $newTasks++;

            $recallsOldMemory = false;
            foreach ($pack['refs'] as $ref) {
                $firstSeen = $refFirstSeen[$ref] ?? null;
                if ($firstSeen === null) {
                    continue;
                }
                $refAgeDays = $this->daysBetween($firstSeen, $pack['created_at']);
                // The ref must have been FIRST captured strictly before this
                // pack (a true cross-pack recall) AND be at least the minimum
                // calendar age old.
                if ($firstSeen->lessThan($pack['created_at']) && $refAgeDays >= $minRecallAgeDays) {
                    $recallsOldMemory = true;
                    $recallEvents++;
                    $maxRecallAgeDays = max($maxRecallAgeDays, $refAgeDays);
                    $recalledRefs[$ref] = true;
                }
            }

            $certified = $pack['safe_resume_mode'] === AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;
            if ($recallsOldMemory) {
                $recallArmTasks++;
                $recallArmCertified += $certified ? 1 : 0;
            } else {
                $noRecallArmTasks++;
                $noRecallArmCertified += $certified ? 1 : 0;
            }
        }

        $recallRate = $recallArmTasks > 0 ? $recallArmCertified / $recallArmTasks : null;
        $noRecallRate = $noRecallArmTasks > 0 ? $noRecallArmCertified / $noRecallArmTasks : null;
        $recallLift = ($recallRate !== null && $noRecallRate !== null)
            ? $recallRate - $noRecallRate
            : null;

        return [
            'measured_at' => $now->toIso8601String(),
            'pack_count' => $packCount,
            'oldest_pack_created_at' => $oldestCreated->toIso8601String(),
            'newest_pack_created_at' => $newestCreated->toIso8601String(),
            'calendar_span_days' => $calendarSpanDays,
            'recent_window_days' => $recentWindowDays,
            'new_task_count' => $newTasks,
            'recall_event_count' => $recallEvents,
            'distinct_recalled_ref_count' => count($recalledRefs),
            'max_recall_age_days' => $maxRecallAgeDays,
            'ab' => [
                'recall_arm_tasks' => $recallArmTasks,
                'recall_arm_certified' => $recallArmCertified,
                'recall_arm_certification_rate' => $recallRate,
                'no_recall_arm_tasks' => $noRecallArmTasks,
                'no_recall_arm_certified' => $noRecallArmCertified,
                'no_recall_arm_certification_rate' => $noRecallRate,
                'recall_lift' => $recallLift,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyMeasurement(CarbonImmutable $now): array
    {
        return [
            'measured_at' => $now->toIso8601String(),
            'pack_count' => 0,
            'oldest_pack_created_at' => null,
            'newest_pack_created_at' => null,
            'calendar_span_days' => 0.0,
            'recent_window_days' => 0,
            'new_task_count' => 0,
            'recall_event_count' => 0,
            'distinct_recalled_ref_count' => 0,
            'max_recall_age_days' => 0.0,
            'ab' => [
                'recall_arm_tasks' => 0,
                'recall_arm_certified' => 0,
                'recall_arm_certification_rate' => null,
                'no_recall_arm_tasks' => 0,
                'no_recall_arm_certified' => 0,
                'no_recall_arm_certification_rate' => null,
                'recall_lift' => null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $m
     * @param  array<string,mixed>  $config
     * @return list<string>
     */
    private function deriveBlockers(array $m, array $config): array
    {
        $blockers = [];

        if ((int) $m['pack_count'] < 2) {
            $blockers[] = 'insufficient_pack_history';
        }
        if ((float) $m['calendar_span_days'] < (float) $config['min_calendar_span_days']) {
            $blockers[] = 'calendar_span_below_floor';
        }
        if ((int) $m['new_task_count'] < (int) $config['min_new_tasks']) {
            $blockers[] = 'new_task_count_below_floor';
        }
        if ((int) $m['recall_event_count'] < (int) $config['min_recall_events']) {
            $blockers[] = 'recall_events_below_floor';
        }
        if ((float) $m['max_recall_age_days'] < (float) $config['min_recall_age_days']) {
            $blockers[] = 'recall_age_below_floor';
        }

        $recallLift = $m['ab']['recall_lift'] ?? null;
        if ($recallLift === null) {
            $blockers[] = 'recall_lift_not_measured';
        } elseif ((float) $recallLift < (float) $config['min_recall_lift']) {
            $blockers[] = 'recall_lift_below_floor';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<int,mixed>  $evidenceRefs
     * @return list<string>
     */
    private function extractRefs(array $evidenceRefs): array
    {
        $out = [];
        foreach ($evidenceRefs as $ref) {
            if (is_array($ref)) {
                $path = $ref['ref'] ?? null;
                if (is_string($path) && trim($path) !== '') {
                    $out[] = trim($path);
                }
            } elseif (is_string($ref) && trim($ref) !== '') {
                $out[] = trim($ref);
            }
        }

        return array_values(array_unique($out));
    }

    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round($from->diffInSeconds($to, false) / 86400, 6);
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $measurement
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(string $status, bool $certified, array $blockers, ?array $measurement, array $config): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'status' => $status,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'measurement' => $measurement,
            'blockers' => array_values($blockers),
            'config' => $config,
            'claim_policy' => [
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'reads_only_persisted_packs' => true,
                'does_not_backfill_time' => true,
                'does_not_mint_recall_events' => true,
                'does_not_inflate_lift' => true,
                'age_derived_from_real_created_at' => true,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
            ],
        ];
        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'status' => $status,
            'certified' => $certified,
            'blockers' => $payload['blockers'],
            'measurement' => $measurement,
            'config' => $config,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return $payload;
    }
}
