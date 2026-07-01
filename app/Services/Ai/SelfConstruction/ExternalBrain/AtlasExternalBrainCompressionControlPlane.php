<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Autonomous invariant cockpit for governed codebase-compression campaigns. Reads compression
 * candidates (the shape produced by {@see AtlasExternalBrainArchitectureCompressionPlanner}) and
 * turns hotspot, proof-gate, behavior-lock, risk and expected-reduction facts into a single
 * go/hold decision per candidate — never the other way around. A mutating candidate (delete,
 * merge, simplify) is HELD whenever its behavior lock or proof-gate evidence is missing, no
 * matter how large the expected line-reduction ROI is; ROI never overrides safety.
 *
 * Input contract:
 *   candidates: list<array{
 *     candidate_id?:             string,
 *     action?:                   string  ('delete'|'merge'|'simplify'|'keep', default 'keep'),
 *     area?:                     string,
 *     risk_level?:               string  ('low'|'medium'|'high', default 'high'),
 *     expected_line_reduction?:  int,
 *     behavior_lock_present?:    bool    (default false — a lock must be proven, not assumed),
 *     proof_gates_passed?:       bool    (default false — same principle),
 *     hotspot_score?:            float,
 *   }>
 *
 * A candidate whose action is 'keep' never mutates anything and is never a go/hold decision —
 * it is excluded from safe_waves and blocked_deletions but still ranked in top_hotspots.
 *
 * Pure PHP, deterministic, no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainCompressionControlPlane
{
    public const SCHEMA = 'atlas.external_brain.compression_control_plane.v1';

    public const DECISION_GO   = 'go';
    public const DECISION_HOLD = 'hold';

    private const MUTATING_ACTIONS = ['delete', 'merge', 'simplify'];

    private const TOP_HOTSPOTS_LIMIT = 5;

    private const NEXT_BATCH_LIMIT = 5;

    /**
     * @param  array{candidates?: list<array<string,mixed>>}  $facts
     * @return array{
     *     schema:                   string,
     *     readiness:                string,
     *     top_hotspots:             list<array<string,mixed>>,
     *     safe_waves:               list<string>,
     *     blocked_deletions:        list<array<string,mixed>>,
     *     expected_line_reduction:  int,
     *     recommended_next_batch:   list<string>,
     * }
     */
    public function evaluate(array $facts): array
    {
        $candidates = is_array($facts['candidates'] ?? null) ? $facts['candidates'] : [];

        $ranked            = [];
        $safeWaves         = [];
        $blockedDeletions  = [];
        $expectedReduction = 0;

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = trim((string) ($candidate['candidate_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $action         = (string) ($candidate['action'] ?? 'keep');
            $area           = (string) ($candidate['area'] ?? '');
            $riskLevel      = (string) ($candidate['risk_level'] ?? 'high');
            $lineReduction  = max(0, (int) ($candidate['expected_line_reduction'] ?? 0));
            $lockPresent    = (bool) ($candidate['behavior_lock_present'] ?? false);
            $proofPassed    = (bool) ($candidate['proof_gates_passed'] ?? false);
            $hotspotScore   = (float) ($candidate['hotspot_score'] ?? 0.0);

            $decision = null;

            if (in_array($action, self::MUTATING_ACTIONS, true)) {
                $blockingReasons = [];
                if (! $lockPresent) {
                    $blockingReasons[] = 'missing_behavior_lock';
                }
                if (! $proofPassed) {
                    $blockingReasons[] = 'missing_proof_gate_evidence';
                }
                if ($riskLevel === 'high') {
                    $blockingReasons[] = 'high_risk';
                }

                if ($blockingReasons !== []) {
                    $decision = self::DECISION_HOLD;
                    $blockedDeletions[] = [
                        'candidate_id'             => $id,
                        'action'                   => $action,
                        'reason'                   => implode('+', $blockingReasons),
                        'expected_line_reduction'  => $lineReduction,
                    ];
                } else {
                    $decision = self::DECISION_GO;
                    $safeWaves[] = $id;
                    $expectedReduction += $lineReduction;
                }
            }

            $ranked[] = [
                'candidate_id'  => $id,
                'action'        => $action,
                'area'          => $area,
                'hotspot_score' => $hotspotScore,
                'decision'      => $decision,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['hotspot_score'] <=> $a['hotspot_score']);
        $topHotspots = array_slice($ranked, 0, self::TOP_HOTSPOTS_LIMIT);

        $goCandidates = array_values(array_filter($ranked, static fn (array $r): bool => $r['decision'] === self::DECISION_GO));
        $recommendedNextBatch = array_map(
            static fn (array $r): string => (string) $r['candidate_id'],
            array_slice($goCandidates, 0, self::NEXT_BATCH_LIMIT),
        );

        $readiness = match (true) {
            $blockedDeletions === [] && $safeWaves !== [] => self::DECISION_GO,
            $safeWaves === [] => self::DECISION_HOLD,
            default => 'partial_go',
        };

        return [
            'schema'                  => self::SCHEMA,
            'readiness'               => $readiness,
            'top_hotspots'            => $topHotspots,
            'safe_waves'              => $safeWaves,
            'blocked_deletions'       => $blockedDeletions,
            'expected_line_reduction' => $expectedReduction,
            'recommended_next_batch'  => $recommendedNextBatch,
        ];
    }
}
