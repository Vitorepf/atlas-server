<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Runs the complete Maestro health cluster against a live serving-queue snapshot and
 * produces a ranked intervention plan.
 *
 * Composes six orphan health organs into a single actionable result:
 *   1. AtlasMaestroBlockedQueueUnblockPlanner   — unblock actions for blocked packets
 *   2. AtlasMaestroOldestPacketRotationAdvisor  — anti-starvation rotation decisions
 *   3. AtlasMaestroPoisonEarlyWarningModel      — pre-serve poison risk scoring
 *   4. AtlasMaestroQuarantineBurnDownScheduler  — quarantine drain scheduling
 *   5. AtlasMaestroStaleClaimableRescueLens     — stale-claimable rescue diagnosis
 *   6. AtlasMaestroQueueAgeValueDecayJoiner     — age-vs-value decay routing
 *
 * Each organ runs independently; the runner merges findings into a ranked
 * intervention list ordered by urgency (priority_score descending).
 *
 * Pure, deterministic, read-only composition — no queue mutation, provider calls,
 * filesystem write, or git command.
 */
final class AtlasMaestroQueueHealthInterventionRunner
{
    public const SCHEMA = 'atlas.maestro.health.queue_health_intervention_runner.v1';

    public const INTERVENTION_TYPE_UNBLOCK = 'unblock';
    public const INTERVENTION_TYPE_ROTATION = 'rotation';
    public const INTERVENTION_TYPE_POISON_HOLD = 'poison_hold';
    public const INTERVENTION_TYPE_QUARANTINE_BURN_DOWN = 'quarantine_burn_down';
    public const INTERVENTION_TYPE_STALE_RESCUE = 'stale_rescue';
    public const INTERVENTION_TYPE_AGE_VALUE_DECAY = 'age_value_decay';

    /**
     * Run all six health organs against the queue snapshot and produce a ranked
     * intervention plan.
     *
     * @param  array<string, mixed>  $snapshot  live serving-queue snapshot
     *   (from AtlasTaskServingStack::coordinationHealth()->snapshot())
     * @return array<string, mixed>  ranked intervention plan
     */
    public function run(array $snapshot): array
    {
        $blockedPackets = $this->extractBlockedPackets($snapshot);
        $oldestPackets = $this->extractOldestPackets($snapshot);
        $claimablePackets = $this->extractClaimablePackets($snapshot);
        $quarantinedPackets = $this->extractQuarantinedPackets($snapshot);
        $staleClaimableFacts = $this->extractStaleClaimableFacts($snapshot);
        $decayJoinPackets = $this->extractDecayJoinPackets($snapshot);

        $planner = new AtlasMaestroBlockedQueueUnblockPlanner;
        $advisor = new AtlasMaestroOldestPacketRotationAdvisor;
        $poisonModel = new AtlasMaestroPoisonEarlyWarningModel;
        $scheduler = new AtlasMaestroQuarantineBurnDownScheduler;
        $lens = new AtlasMaestroStaleClaimableRescueLens;
        $joiner = new AtlasMaestroQueueAgeValueDecayJoiner;

        // ── Organ 1: Unblock planner ──────────────────────────────────────────
        $unblockPlan = $blockedPackets !== []
            ? $planner->plan($blockedPackets)
            : ['entries' => [], 'total_blocked' => 0];

        // ── Organ 2: Rotation advisor ─────────────────────────────────────────
        $rotationFacts = $this->buildRotationFacts($snapshot, $oldestPackets);
        $rotationAdvice = $rotationFacts !== []
            ? $advisor->advise($rotationFacts)
            : ['action' => 'observe', 'reason_codes' => [], 'packet_ids_to_surface' => []];

        // ── Organ 3: Poison early warning ─────────────────────────────────────
        $poisonWarnings = [];
        foreach ($claimablePackets as $packet) {
            $poisonWarnings[] = $poisonModel->score($packet);
        }

        // ── Organ 4: Quarantine burn-down scheduler ───────────────────────────
        $quarantineSchedule = $quarantinedPackets !== []
            ? $scheduler->schedule($quarantinedPackets)
            : ['lanes' => [], 'total_quarantined' => 0, 'do_not_requeue_count' => 0];

        // ── Organ 5: Stale claimable rescue lens ──────────────────────────────
        $staleDiagnosis = $staleClaimableFacts !== []
            ? $lens->diagnose($staleClaimableFacts)
            : ['diagnosis' => 'insufficient_evidence', 'severity' => 'none', 'rescue_actions' => []];

        // ── Organ 6: Age-value decay joiner ───────────────────────────────────
        $decayJoin = $decayJoinPackets !== []
            ? $joiner->join($decayJoinPackets)
            : ['recommendations' => []];

        // ── Compose interventions ─────────────────────────────────────────────
        $interventions = [];

        // From unblock planner: each claimable repair spec is an intervention
        foreach (($unblockPlan['entries'] ?? []) as $entry) {
            if ($entry['implementation_work_enqueued'] ?? false) {
                $interventions[] = [
                    'type' => self::INTERVENTION_TYPE_UNBLOCK,
                    'priority_score' => (float) ($entry['priority_score'] ?? 0),
                    'target_packet_id' => (string) ($entry['task_packet_id'] ?? ''),
                    'summary' => sprintf(
                        'unblock %s: %s (unblock_class=%s, lane=%s, recovered_claimable_value=%.1f)',
                        $entry['task_packet_id'] ?? '?',
                        $entry['action'] ?? '?',
                        $entry['unblock_class'] ?? '?',
                        $entry['lane'] ?? '?',
                        (float) ($entry['recovered_claimable_value'] ?? 0),
                    ),
                    'details' => $entry,
                ];
            }
        }

        // From rotation advisor: rotation intervention when action is rotate/surface
        $rotationAction = (string) ($rotationAdvice['action'] ?? '');
        if (in_array($rotationAction, ['rotate_oldest', 'surface_oldest_to_muscles'], true)) {
            $surfaceIds = (array) ($rotationAdvice['packet_ids_to_surface'] ?? []);
            $interventions[] = [
                'type' => self::INTERVENTION_TYPE_ROTATION,
                'priority_score' => 50.0, // rotation is high urgency
                'target_packet_id' => $surfaceIds[0] ?? '',
                'summary' => sprintf(
                    '%s: %d packet(s) stale, depth=%s, action=%s',
                    $rotationAction,
                    count($surfaceIds),
                    $snapshot['claimable_depth'] ?? '?',
                    $rotationAction,
                ),
                'details' => $rotationAdvice,
            ];
        }

        // From poison model: each high/medium-risk packet gets a poison_hold intervention
        foreach ($poisonWarnings as $pw) {
            $risk = (string) ($pw['poison_risk'] ?? 'low');
            if ($risk === 'low') {
                continue;
            }
            $interventions[] = [
                'type' => self::INTERVENTION_TYPE_POISON_HOLD,
                'priority_score' => $risk === 'high' ? 40.0 : 25.0,
                'target_packet_id' => '',
                'summary' => sprintf(
                    'poison risk=%s (score=%d): %s → %s',
                    $risk,
                    (int) ($pw['score'] ?? 0),
                    implode(', ', (array) ($pw['reasons'] ?? [])),
                    (string) ($pw['safe_next_action'] ?? 'hold_for_review'),
                ),
                'details' => $pw,
            ];
        }

        // From quarantine scheduler: each lane that has entries is an intervention
        foreach (($quarantineSchedule['lanes'] ?? []) as $laneName => $laneEntries) {
            if ($laneEntries === [] || ! is_array($laneEntries)) {
                continue;
            }
            $interventions[] = [
                'type' => self::INTERVENTION_TYPE_QUARANTINE_BURN_DOWN,
                'priority_score' => match ($laneName) {
                    'retire' => 35.0,
                    'operator_only' => 30.0,
                    'respec' => 20.0,
                    default => 10.0,
                },
                'target_packet_id' => '',
                'summary' => sprintf(
                    'quarantine lane=%s: %d packet(s) need %s action (do_not_requeue=%d)',
                    $laneName,
                    count($laneEntries),
                    $laneName,
                    $quarantineSchedule['do_not_requeue_count'] ?? 0,
                ),
                'details' => ['lane' => $laneName, 'entries' => $laneEntries],
            ];
        }

        // From stale rescue lens: non-healthy diagnosis with rescue actions
        $staleDiagnosisStr = (string) ($staleDiagnosis['diagnosis'] ?? '');
        $severity = (string) ($staleDiagnosis['severity'] ?? 'none');
        if ($staleDiagnosisStr !== '' && $severity !== 'none') {
            $rescueActions = (array) ($staleDiagnosis['rescue_actions'] ?? []);
            $interventions[] = [
                'type' => self::INTERVENTION_TYPE_STALE_RESCUE,
                'priority_score' => match ($severity) {
                    'high' => 45.0,
                    'medium' => 20.0,
                    default => 5.0,
                },
                'target_packet_id' => '',
                'summary' => sprintf(
                    'stale-claimable: %s (severity=%s): %s',
                    $staleDiagnosisStr,
                    $severity,
                    implode(', ', $rescueActions),
                ),
                'details' => $staleDiagnosis,
            ];
        }

        // From age-value decay joiner: decay_or_review and drain_first are actionable
        foreach (($decayJoin['recommendations'] ?? []) as $rec) {
            $action = (string) ($rec['action'] ?? 'keep_waiting');
            if (! in_array($action, ['drain_first', 'decay_or_review'], true)) {
                continue;
            }
            $interventions[] = [
                'type' => self::INTERVENTION_TYPE_AGE_VALUE_DECAY,
                'priority_score' => $action === 'drain_first' ? 15.0 : 8.0,
                'target_packet_id' => (string) ($rec['task_packet_id'] ?? ''),
                'summary' => sprintf(
                    'age-value: %s (age=%s, value=%s, decay=%.4f, routing=%s)',
                    $rec['task_packet_id'] ?? '?',
                    $rec['age_bucket'] ?? '?',
                    $rec['value_bucket'] ?? '?',
                    (float) ($rec['decay_score'] ?? 0),
                    $rec['routing_label'] ?? '?',
                ),
                'details' => $rec,
            ];
        }

        // ── Rank interventions by priority_score descending ───────────────────
        usort($interventions, static fn (array $a, array $b): int => (float) ($b['priority_score'] ?? 0) <=> (float) ($a['priority_score'] ?? 0));

        $isHealthy = $interventions === [];

        return [
            'schema' => self::SCHEMA,
            'healthy' => $isHealthy,
            'interventions' => $interventions,
            'intervention_count' => count($interventions),
            'organs' => [
                'unblock_planner' => [
                    'total_blocked' => $unblockPlan['total_blocked'] ?? 0,
                    'claimable_repair_specs_count' => count($unblockPlan['claimable_repair_specs'] ?? []),
                    'retire_candidates_count' => count($unblockPlan['retire_candidates'] ?? []),
                ],
                'rotation_advisor' => [
                    'action' => $rotationAdvice['action'] ?? 'observe',
                    'packets_to_surface_count' => count($rotationAdvice['packet_ids_to_surface'] ?? []),
                    'reason_codes' => $rotationAdvice['reason_codes'] ?? [],
                ],
                'poison_warning' => [
                    'total_scored' => count($poisonWarnings),
                    'high_risk_count' => count(array_filter($poisonWarnings, static fn (array $pw): bool => ($pw['poison_risk'] ?? 'low') === 'high')),
                    'medium_risk_count' => count(array_filter($poisonWarnings, static fn (array $pw): bool => ($pw['poison_risk'] ?? 'low') === 'medium')),
                ],
                'quarantine_scheduler' => [
                    'total_quarantined' => $quarantineSchedule['total_quarantined'] ?? 0,
                    'do_not_requeue_count' => $quarantineSchedule['do_not_requeue_count'] ?? 0,
                ],
                'stale_rescue_lens' => [
                    'diagnosis' => $staleDiagnosis['diagnosis'] ?? 'insufficient_evidence',
                    'severity' => $staleDiagnosis['severity'] ?? 'none',
                ],
                'age_value_decay_joiner' => [
                    'recommendations_count' => count($decayJoin['recommendations'] ?? []),
                ],
            ],
        ];
    }

    // ── data extraction helpers (overrideable via params for testing) ─────────

    /**
     * Extract blocked packet data from the snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function extractBlockedPackets(array $snapshot): array
    {
        $packets = $snapshot['blocked_packets'] ?? [];
        return is_array($packets) ? array_values($packets) : [];
    }

    /**
     * Extract oldest packet IDs from the snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function extractOldestPackets(array $snapshot): array
    {
        return array_values(array_map('strval', (array) ($snapshot['oldest_packet_ids'] ?? [])));
    }

    /**
     * Extract claimable packet data from the snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function extractClaimablePackets(array $snapshot): array
    {
        $packets = $snapshot['claimable_packets'] ?? [];
        return is_array($packets) ? array_values($packets) : [];
    }

    /**
     * Extract quarantined packet data from the snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function extractQuarantinedPackets(array $snapshot): array
    {
        $packets = $snapshot['quarantined_packets'] ?? [];
        return is_array($packets) ? array_values($packets) : [];
    }

    /**
     * Extract stale-claimable diagnosis facts from the snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function extractStaleClaimableFacts(array $snapshot): array
    {
        return is_array($snapshot['stale_claimable_facts'] ?? null) ? $snapshot['stale_claimable_facts'] : [];
    }

    /**
     * Extract age-value decay join packet data from the snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function extractDecayJoinPackets(array $snapshot): array
    {
        $packets = $snapshot['decay_join_packets'] ?? [];
        return is_array($packets) ? array_values($packets) : [];
    }

    /**
     * Build rotation advisor facts from snapshot + oldest packet IDs.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $oldestPackets
     * @return array<string, mixed>
     */
    private function buildRotationFacts(array $snapshot, array $oldestPackets): array
    {
        return [
            'queue_age' => [
                'p95' => (float) ($snapshot['oldest_age_minutes'] ?? 0.0),
            ],
            'oldest_packet_ids' => $oldestPackets,
            'claimable_depth' => (int) ($snapshot['claimable_depth'] ?? 0),
            'active_leases' => (int) ($snapshot['active_leases'] ?? 0),
            'serve_rate_per_minute' => $snapshot['serve_rate_per_minute'] ?? null,
        ];
    }
}
