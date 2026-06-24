<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use Throwable;

/**
 * V3 SELF-CONSTRUCTION CONTROL PLANE — the READ-ONLY orchestrator that composes the four v3 primitives into a
 * single SNAPSHOT of what the Loop is currently equipped to self-construct, learn, and transfer. PURE
 * composition: no autonomous side effects, no auto-merge, no flag flips — the operator (and later the gated
 * autonomous arm) reads the snapshot and decides.
 *
 * ANTI-GOODHART: ready_actions is empty UNLESS a real upstream primitive reports an admissible signal — no
 * synthetic "all systems nominal". RESILIENT: any delegate throw is caught PER-JOB into 'errors' so one bad
 * job never poisons the whole snapshot.
 *
 * The four collaborators are duck-typed (the real v3 primitives are final, so they are injected by constructor
 * and replaceable with anonymous-class fakes): $promotion->evaluate(), $lever->recommend(),
 * $strategy->distill(), $transfer->probe().
 */
final class AtlasLoopV3SelfConstructionControlPlane
{
    public const SCHEMA = 'atlas.loop.v3.self_construction_control_plane.v1';

    public function __construct(
        private readonly object $promotion, // AtlasLoopV3GraderPromotionGate
        private readonly object $lever,     // AtlasLoopV3MetaLeverRecommender
        private readonly object $strategy,  // AtlasLoopV3MetaStrategyDistiller
        private readonly object $transfer,  // AtlasLoopV3CrossScopeTransferProbe
    ) {}

    /**
     * @param  array{candidate_graders?:list<array{fqcn:string,real_rejected:array,planted_false:array,repo_root:string}>, lever_readings?:array, campaign_ids?:list<string>, transfer_jobs?:list<array{fingerprint:array,target_root:string}>}  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input): array
    {
        $errors = [];

        // (1) candidate graders → those the promotion gate certifies promote=true.
        $gradersPromotable = [];
        foreach ((array) ($input['candidate_graders'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $fqcn = (string) ($candidate['fqcn'] ?? '');
            try {
                $result = (array) $this->promotion->evaluate(
                    $fqcn,
                    (array) ($candidate['real_rejected'] ?? []),
                    (array) ($candidate['planted_false'] ?? []),
                    (string) ($candidate['repo_root'] ?? ''),
                );
                if (($result['promote'] ?? null) === true) {
                    $gradersPromotable[] = ['fqcn' => $fqcn] + $result;
                }
            } catch (Throwable $e) {
                $errors[] = ['fqcn' => $fqcn, 'error' => $e->getMessage()];
            }
        }

        // (2) lever recommender (pass-through).
        $lever = [];
        try {
            $lever = (array) $this->lever->recommend((array) ($input['lever_readings'] ?? []));
        } catch (Throwable $e) {
            $errors[] = ['fqcn' => 'lever', 'error' => $e->getMessage()];
        }

        // (3) meta-strategy distiller (pass-through).
        $strategy = [];
        try {
            $strategy = (array) $this->strategy->distill((array) ($input['campaign_ids'] ?? []));
        } catch (Throwable $e) {
            $errors[] = ['fqcn' => 'strategy', 'error' => $e->getMessage()];
        }

        // (4) transfer jobs → those probed 'transferable'.
        $transfersReady = [];
        foreach ((array) ($input['transfer_jobs'] ?? []) as $job) {
            if (! is_array($job)) {
                continue;
            }
            try {
                $result = (array) $this->transfer->probe((array) ($job['fingerprint'] ?? []), (string) ($job['target_root'] ?? ''));
                if (($result['verdict'] ?? null) === 'transferable') {
                    $transfersReady[] = ['target_root' => (string) ($job['target_root'] ?? '')] + $result;
                }
            } catch (Throwable $e) {
                $errors[] = ['fqcn' => (string) ($job['target_root'] ?? 'transfer'), 'error' => $e->getMessage()];
            }
        }

        $recommend = $lever['recommend'] ?? null;
        $readyActions = array_merge(
            $gradersPromotable,
            $recommend !== null ? [$recommend] : [],
            $transfersReady,
        );

        $hasEvidence = $readyActions !== [] || ($strategy['strategies'] ?? []) !== [];

        return [
            'graders_promotable' => $gradersPromotable,
            'lever' => $lever,
            'strategy' => $strategy,
            'transfers_ready' => $transfersReady,
            'ready_actions' => $readyActions,
            'has_evidence' => $hasEvidence,
            'errors' => $errors,
            'schema' => self::SCHEMA,
        ];
    }
}
