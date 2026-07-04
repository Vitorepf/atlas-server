<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Read-only dashboard model of the brain's current state and maturity.
 *
 * Input contract (all keys optional; missing keys lower maturity or add blockers):
 *   rubric_scores      array<string,float>   — capability dimension scores 0–1
 *   queue_health       array                 — {status:'healthy'|'degraded'|'stalled', give_back_rate?:float}
 *   audit_result       array                 — AntiGoodhartAuditor output ({verdict, findings})
 *   ledger_summary     array|null            — {total:int, success_rate:float}, null = missing ledger
 *   stalled_yield      bool                  — true when batches are submitted but not completing
 *   known_capabilities list<string>          — already-proven capability labels (excluded from "next missing")
 *
 * Maturity bands (ascending): bootstrapping → emerging → functional → advanced → autonomous
 *
 * Capping rules (blockers that constrain the band):
 *   caps_maturity_at_emerging  — stalled queue OR missing ledger
 *   caps_maturity_at_functional — degraded queue OR audit verdict=reject
 *   lowers_maturity            — stalled yield (drops one band)
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainControlPlaneSnapshot
{
    public const SCHEMA = 'atlas.external_brain.control_plane_snapshot.v1';

    public const BAND_BOOTSTRAPPING = 'bootstrapping';
    public const BAND_EMERGING      = 'emerging';
    public const BAND_FUNCTIONAL    = 'functional';
    public const BAND_ADVANCED      = 'advanced';
    public const BAND_AUTONOMOUS    = 'autonomous';

    private const BAND_ORDER = [
        self::BAND_BOOTSTRAPPING => 0,
        self::BAND_EMERGING      => 1,
        self::BAND_FUNCTIONAL    => 2,
        self::BAND_ADVANCED      => 3,
        self::BAND_AUTONOMOUS    => 4,
    ];

    private const BAND_THRESHOLDS = [
        self::BAND_AUTONOMOUS    => 0.85,
        self::BAND_ADVANCED      => 0.70,
        self::BAND_FUNCTIONAL    => 0.50,
        self::BAND_EMERGING      => 0.25,
        self::BAND_BOOTSTRAPPING => 0.0,
    ];

    /**
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema:                     string,
     *     maturity_band:              string,
     *     maturity_score:             float,
     *     blockers:                   list<array<string,string>>,
     *     next_actions:               list<string>,
     *     evidence_gaps:              list<string>,
     *     next_missing_capabilities:  list<array<string,mixed>>,
     *     dimensions:                 array<string,mixed>,
     * }
     */
    public function snapshot(array $inputs): array
    {
        $rubricScore = $this->rubricScore((array) ($inputs['rubric_scores'] ?? []));
        $blockers     = [];
        $evidenceGaps = [];
        $nextActions  = [];

        // Ledger presence
        if (! array_key_exists('ledger_summary', $inputs) || $inputs['ledger_summary'] === null) {
            $blockers[]     = ['dimension' => 'ledger', 'reason' => 'missing_ledger',      'impact' => 'caps_maturity_at_emerging'];
            $evidenceGaps[] = 'learning_ledger';
            $nextActions[]  = 'Initialize the learning ledger with at least one completed outcome cycle';
        }

        // Queue health
        $queueStatus = (string) ($inputs['queue_health']['status'] ?? 'unknown');
        if ($queueStatus === 'stalled') {
            $blockers[]    = ['dimension' => 'queue_health', 'status' => 'stalled',   'impact' => 'caps_maturity_at_emerging'];
            $nextActions[] = 'Investigate queue stall: check give_back rate and poison packet patterns';
        } elseif ($queueStatus === 'degraded') {
            $blockers[]    = ['dimension' => 'queue_health', 'status' => 'degraded',  'impact' => 'caps_maturity_at_functional'];
            $nextActions[] = 'Repair degraded queue: review give_back patterns and run the packet reshaper';
        }

        // Anti-Goodhart audit
        $auditVerdict = (string) ($inputs['audit_result']['verdict'] ?? 'unknown');
        if ($auditVerdict === 'reject') {
            $blockers[]     = ['dimension' => 'batch_quality_audit', 'verdict' => 'reject', 'impact' => 'caps_maturity_at_functional'];
            $evidenceGaps[] = 'batch_quality_proof';
            $nextActions[]  = 'Address anti-Goodhart findings and re-audit before the next cycle';
        } elseif ($auditVerdict === 'repair_required') {
            $nextActions[] = 'Repair batch: apply anti-Goodhart suggestions to eliminate low-quality tasks';
        }

        // Stalled yield
        if (! empty($inputs['stalled_yield'])) {
            $blockers[]     = ['dimension' => 'yield', 'reason' => 'stalled_yield', 'impact' => 'lowers_maturity'];
            $evidenceGaps[] = 'yield_recovery_evidence';
            $nextActions[]  = 'Diagnose stalled yield: verify task completion and worker health';
        }

        $band        = $this->computeBand($rubricScore, $blockers);
        $domainFacts = (array) ($inputs['domain_facts'] ?? []);
        $hasMissingLedger = ! array_key_exists('ledger_summary', $inputs) || $inputs['ledger_summary'] === null;

        // AC1/AC2/AC3: explicit control-plane summary fields
        $giveBackRate       = array_key_exists('queue_health', $inputs)
            ? (float) ($inputs['queue_health']['give_back_rate'] ?? 0.0)
            : null;
        $queueHealthSummary = [
            'status'         => $queueStatus !== '' ? $queueStatus : 'unknown',
            'give_back_rate' => $giveBackRate,
        ];

        // Task quality drift: detect when task quality is degrading over time
        $taskQualityDrift = $this->computeTaskQualityDrift($inputs);

        // Learning freshness: how recent is the learning signal
        $learningFreshness = $this->computeLearningFreshness($inputs);

        // Blocked debt: count of blocked/stalled items that need repair
        $blockedDebt = $this->computeBlockedDebt($inputs, $blockers);

        // Next originator action: what should the originator do next
        $nextOriginatorAction = $this->computeNextOriginatorAction(
            $queueStatus,
            $auditVerdict,
            $hasMissingLedger,
            $taskQualityDrift,
            $blockedDebt,
            $inputs
        );
        $workerState = array_key_exists('worker_state', $inputs)
            ? (string) ($inputs['worker_state']['status'] ?? 'unknown')
            : 'unknown';
        $highGiveBack = $giveBackRate !== null && $giveBackRate > 0.30;
        $risk = 'low';
        if ($queueStatus === 'stalled' || $highGiveBack) {
            $risk = 'high';
        } elseif ($queueStatus === 'degraded' || ($giveBackRate !== null && $giveBackRate > 0.10)) {
            $risk = 'medium';
        }
        $quality = match ($auditVerdict) {
            'pass'            => 'good',
            'repair_required' => 'degraded',
            'reject'          => 'blocked',
            default           => 'unknown',
        };
        if ($queueStatus === 'stalled' || $risk === 'high') {
            $recommendedDecision = 'repair_queue_before_creating_more';
        } elseif ($queueStatus === 'degraded' || $risk === 'medium') {
            $recommendedDecision = 'reduce_risk_before_creating_more';
        } elseif ($blockers !== []) {
            $recommendedDecision = 'resolve_blockers_before_creating_more';
        } else {
            $recommendedDecision = 'create_more_tasks';
        }

        return [
            'schema'                    => self::SCHEMA,
            'maturity_band'             => $band,
            'maturity_score'            => round($rubricScore, 3),
            'blockers'                  => $blockers,
            'next_actions'              => $nextActions,
            'evidence_gaps'             => $evidenceGaps,
            'next_missing_capabilities' => $this->nextMissingCapabilities(
                (array) ($inputs['rubric_scores'] ?? []),
                (array) ($inputs['known_capabilities'] ?? []),
            ),
            'dimensions'                => [
                'rubric_score'   => round($rubricScore, 3),
                'queue_status'   => $queueStatus,
                'audit_verdict'  => $auditVerdict,
                'ledger_present' => ! $hasMissingLedger,
                'stalled_yield'  => ! empty($inputs['stalled_yield']),
            ],
            'queue_health'              => $queueHealthSummary,
            'worker_state'             => $workerState,
            'risk'                     => $risk,
            'maturity_gaps'            => $evidenceGaps,
            'quality'                  => $quality,
            'recommended_decision'     => $recommendedDecision,
            'domain_map'               => $this->buildDomainMap($domainFacts),
            'prioritized_next_actions' => $this->buildPrioritizedActions(
                $queueStatus,
                $auditVerdict,
                $hasMissingLedger,
                ! empty($inputs['stalled_yield']),
                $domainFacts,
            ),
            'task_quality_drift'       => $taskQualityDrift,
            'learning_freshness'       => $learningFreshness,
            'blocked_debt'             => $blockedDebt,
            'next_originator_action'    => $nextOriginatorAction,
        ];
    }

    private function rubricScore(array $rubricScores): float
    {
        $numeric = array_filter(array_values($rubricScores), 'is_numeric');
        if ($numeric === []) {
            return 0.0;
        }

        return (float) (array_sum($numeric) / count($numeric));
    }

    /** @param list<array<string,string>> $blockers */
    private function computeBand(float $score, array $blockers): string
    {
        $baseBand = self::BAND_BOOTSTRAPPING;
        foreach (self::BAND_THRESHOLDS as $band => $threshold) {
            if ($score >= $threshold) {
                $baseBand = $band;
                break;
            }
        }

        // Collect impact caps.
        $impacts = array_column($blockers, 'impact');

        if (in_array('caps_maturity_at_emerging', $impacts, true)) {
            $baseBand = $this->minBand($baseBand, self::BAND_EMERGING);
        }
        if (in_array('caps_maturity_at_functional', $impacts, true)) {
            $baseBand = $this->minBand($baseBand, self::BAND_FUNCTIONAL);
        }
        if (in_array('lowers_maturity', $impacts, true)) {
            $baseBand = $this->lowerBand($baseBand);
        }

        return $baseBand;
    }

    private function minBand(string $current, string $cap): string
    {
        return self::BAND_ORDER[$current] <= self::BAND_ORDER[$cap] ? $current : $cap;
    }

    private function lowerBand(string $band): string
    {
        $byOrder = array_flip(self::BAND_ORDER);
        $order   = self::BAND_ORDER[$band] ?? 0;

        return $byOrder[max(0, $order - 1)];
    }

    /**
     * AC1: Produces prioritized_next_actions ordered by impact severity.
     * AC2: Stalled-queue (P1) and anti-Goodhart (P2) always precede cosmetic domain-map gaps (P10+).
     *
     * @return list<array{priority:int,blocker_dimension:string,evidence_source:string,expected_autonomy_gain:string}>
     */
    private function buildPrioritizedActions(
        string $queueStatus,
        string $auditVerdict,
        bool $hasMissingLedger,
        bool $stalledYield,
        array $domainFacts,
    ): array {
        $actions = [];

        if ($queueStatus === 'stalled') {
            $actions[] = [
                'priority'               => 1,
                'blocker_dimension'      => 'queue_health.stalled',
                'evidence_source'        => 'give_back_rate;poison_packet_scanner',
                'expected_autonomy_gain' => 'restores_task_throughput',
            ];
        }

        if ($auditVerdict === 'reject') {
            $actions[] = [
                'priority'               => 2,
                'blocker_dimension'      => 'batch_quality_audit.reject',
                'evidence_source'        => 'anti_goodhart_audit_findings',
                'expected_autonomy_gain' => 'enables_quality_gated_merges',
            ];
        }

        if ($hasMissingLedger) {
            $actions[] = [
                'priority'               => 3,
                'blocker_dimension'      => 'ledger.missing',
                'evidence_source'        => 'outcome_cycle_log',
                'expected_autonomy_gain' => 'enables_compounding_learning',
            ];
        }

        if ($stalledYield) {
            $actions[] = [
                'priority'               => 4,
                'blocker_dimension'      => 'yield.stalled',
                'evidence_source'        => 'task_completion_rate;worker_health',
                'expected_autonomy_gain' => 'recovers_batch_delivery',
            ];
        }

        if ($queueStatus === 'degraded') {
            $actions[] = [
                'priority'               => 5,
                'blocker_dimension'      => 'queue_health.degraded',
                'evidence_source'        => 'give_back_patterns;packet_reshaper',
                'expected_autonomy_gain' => 'reduces_manual_intervention',
            ];
        }

        if ($auditVerdict === 'repair_required') {
            $actions[] = [
                'priority'               => 6,
                'blocker_dimension'      => 'batch_quality_audit.repair_required',
                'evidence_source'        => 'anti_goodhart_suggestions',
                'expected_autonomy_gain' => 'improves_batch_quality',
            ];
        }

        // Cosmetic domain-map gaps — always lower priority than structural blockers (AC2).
        $domainGapPriority = 10;
        foreach ($domainFacts as $fact) {
            $area     = trim((string) ($fact['area'] ?? ''));
            $maturity = trim((string) ($fact['maturity'] ?? ''));
            if ($area !== '' && ($maturity === '' || $maturity === 'unknown')) {
                $actions[] = [
                    'priority'               => $domainGapPriority++,
                    'blocker_dimension'      => "domain_map.{$area}.maturity_unknown",
                    'evidence_source'        => 'domain_scan;code_intelligence',
                    'expected_autonomy_gain' => 'improves_domain_coverage_tracking',
                ];
            }
        }

        usort($actions, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $actions;
    }

    /**
     * Build a compact domain map from supplied domain_facts.
     *
     * Each fact entry may have:
     *   area           string   (required)
     *   maturity       string   (optional → 'unknown')
     *   risk           string   (optional → 'unknown')
     *   owner_signal   string   (optional → 'missing')
     *   current_gap    string   (optional → 'missing')
     *   next_lever     string   (optional → 'missing')
     *   evidence_refs  string[] (optional → [])
     *
     * Fact-only: no LLM call, no inference, no optimistic filling.
     * Missing evidence produces explicit sentinel values so the brain can see what needs attention.
     *
     * @param  list<array<string,mixed>>  $domainFacts
     * @return list<array<string,mixed>>
     */
    private function buildDomainMap(array $domainFacts): array
    {
        $map = [];

        foreach ($domainFacts as $fact) {
            $area = trim((string) ($fact['area'] ?? ''));
            if ($area === '') {
                continue;
            }

            $map[] = [
                'area'         => $area,
                'maturity'     => trim((string) ($fact['maturity']    ?? '')) ?: 'unknown',
                'risk'         => trim((string) ($fact['risk']        ?? '')) ?: 'unknown',
                'owner_signal' => trim((string) ($fact['owner_signal'] ?? '')) ?: 'missing',
                'current_gap'  => trim((string) ($fact['current_gap'] ?? '')) ?: 'missing',
                'next_lever'   => trim((string) ($fact['next_lever']  ?? '')) ?: 'missing',
                'evidence_refs' => array_values(array_filter(
                    array_map('trim', (array) ($fact['evidence_refs'] ?? [])),
                    static fn (string $r): bool => $r !== '',
                )),
            ];
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $rubricScores
     * @param  list<string>         $knownCapabilities
     * @return list<array{capability:string,current_score:float}>
     */
    private function nextMissingCapabilities(array $rubricScores, array $knownCapabilities): array
    {
        $missing = [];
        foreach ($rubricScores as $cap => $score) {
            if ((float) $score < 0.5 && ! in_array($cap, $knownCapabilities, true)) {
                $missing[] = ['capability' => (string) $cap, 'current_score' => round((float) $score, 3)];
            }
        }
        usort($missing, static fn (array $a, array $b): int => $a['current_score'] <=> $b['current_score']);

        return array_values($missing);
    }

    /**
     * Compute task quality drift from audit and queue signals.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{direction: string, severity: string, signal: string}
     */
    private function computeTaskQualityDrift(array $inputs): array
    {
        $auditVerdict = (string) ($inputs['audit_result']['verdict'] ?? 'unknown');
        $giveBackRate = (float) ($inputs['queue_health']['give_back_rate'] ?? 0.0);

        if ($auditVerdict === 'reject' || $giveBackRate > 0.5) {
            return ['direction' => 'declining', 'severity' => 'high', 'signal' => 'audit_reject_or_high_give_back'];
        }

        if ($auditVerdict === 'repair_required' || $giveBackRate > 0.2) {
            return ['direction' => 'declining', 'severity' => 'medium', 'signal' => 'audit_repair_or_elevated_give_back'];
        }

        if ($auditVerdict === 'pass' && $giveBackRate <= 0.1) {
            return ['direction' => 'stable', 'severity' => 'low', 'signal' => 'audit_pass_low_give_back'];
        }

        return ['direction' => 'unknown', 'severity' => 'low', 'signal' => 'insufficient_data'];
    }

    /**
     * Compute learning freshness from ledger signals.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{status: string, reason: string}
     */
    private function computeLearningFreshness(array $inputs): array
    {
        if (! array_key_exists('ledger_summary', $inputs) || $inputs['ledger_summary'] === null) {
            return ['status' => 'missing', 'reason' => 'no_ledger'];
        }

        $total = (int) ($inputs['ledger_summary']['total'] ?? 0);
        if ($total === 0) {
            return ['status' => 'stale', 'reason' => 'empty_ledger'];
        }

        $successRate = (float) ($inputs['ledger_summary']['success_rate'] ?? 0.0);
        if ($successRate >= 0.7) {
            return ['status' => 'fresh', 'reason' => 'healthy_success_rate'];
        }

        return ['status' => 'stale', 'reason' => 'low_success_rate'];
    }

    /**
     * Compute blocked debt from blockers and queue signals.
     *
     * @param  array<string,mixed>  $inputs
     * @param  list<array<string,string>>  $blockers
     * @return array{count: int, dimensions: list<string>, repair_priority: string}
     */
    private function computeBlockedDebt(array $inputs, array $blockers): array
    {
        $dimensions = [];
        foreach ($blockers as $blocker) {
            $dim = (string) ($blocker['dimension'] ?? '');
            if ($dim !== '' && ! in_array($dim, $dimensions, true)) {
                $dimensions[] = $dim;
            }
        }

        $count = count($dimensions);
        $repairPriority = $count === 0 ? 'none' : ($count >= 3 ? 'critical' : 'elevated');

        return [
            'count' => $count,
            'dimensions' => $dimensions,
            'repair_priority' => $repairPriority,
        ];
    }

    /**
     * Compute next originator action based on all signals.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{action: string, reason: string}
     */
    private function computeNextOriginatorAction(
        string $queueStatus,
        string $auditVerdict,
        bool $hasMissingLedger,
        array $taskQualityDrift,
        array $blockedDebt,
        array $inputs
    ): array {
        // Repair before origination when there are blockers
        if ($blockedDebt['count'] > 0) {
            $malformed = (int) ($inputs['queue_health']['malformed_count'] ?? 0);
            $collision = (bool) ($inputs['queue_health']['collision_detected'] ?? false);
            $staleLowValue = (bool) ($inputs['queue_health']['stale_low_value'] ?? false);

            if ($malformed > 0 || $collision || $staleLowValue) {
                return ['action' => 'repair_queue', 'reason' => 'malformed_or_collision_or_stale_low_value_signals_present'];
            }

            return ['action' => 'repair_blockers', 'reason' => 'resolve_blockers_before_origination'];
        }

        // High-value origination when queue is healthy and fresh candidates exist
        $highValueGapCount = (int) ($inputs['high_value_gap_count'] ?? 0);
        $originatorDutyCycleDue = (bool) ($inputs['originator_duty_cycle_due'] ?? false);

        if ($highValueGapCount > 0 || $originatorDutyCycleDue) {
            return ['action' => 'high_value_origination', 'reason' => 'healthy_queue_with_fresh_candidates'];
        }

        return ['action' => 'monitor', 'reason' => 'no_immediate_action_needed'];
    }
}
