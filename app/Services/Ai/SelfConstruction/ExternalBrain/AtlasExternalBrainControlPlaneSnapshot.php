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

        $band = $this->computeBand($rubricScore, $blockers);

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
                'ledger_present' => array_key_exists('ledger_summary', $inputs) && $inputs['ledger_summary'] !== null,
                'stalled_yield'  => ! empty($inputs['stalled_yield']),
            ],
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
}
