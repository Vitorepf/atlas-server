<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\Cognitive\Failure\SuiteRedTestHandleHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The self-feeding seam: turns discovered TARGETS into durable, metric-shaped TASKS.
 *
 * For each top-ranked candidate it builds a scoped base workspace from the campaign's
 * repo, asks {@see AtlasEvolutionTaskGenerator} to write ONE RED-verified frozen test +
 * objective (the only provider call in discovery — the honest "is this real work" gate),
 * then SNAPSHOTS the target content + frozen test bodies into a durable task payload so
 * the queued task is fully reproducible across restarts, and enqueues it. A target whose
 * generator output is not genuinely RED is routed through loop-back (quarantined as
 * un-grindable), never enqueued — so the queue stays honest.
 */
final class AtlasLoopQueueRefiller
{
    public function __construct(
        private readonly AtlasLoopTargetDiscoveryService $discovery,
        private readonly AtlasLoopTargetRepository $repository,
        private readonly AtlasEvolutionTaskGenerator $generator,
        private readonly AtlasLoopBackService $loopBack,
        private readonly AtlasLoopStore $store,
        private readonly ?AtlasLoopRefactorObjectiveSynthesizer $refactorSynthesizer = null,
        private readonly ?AtlasLoopHarnessGuard $harnessGuard = null,
        private readonly ?AtlasLoopFrameworkRefactorSynthesizer $frameworkRefactorSynthesizer = null,
        private readonly ?AtlasLoopObraClusterDetectorService $obraClusterDetector = null,
        private readonly ?AtlasLoopWorkShapeRouter $workShapeRouter = null,
        private readonly ?AtlasLoopMultiFileRefactorSynthesizer $multiFileRefactorSynthesizer = null,
        private readonly ?AtlasLoopNextWorkDecider $nextWorkDecider = null,
        private readonly ?AtlasLoopWorkClassPriorService $workClassPrior = null,
        private readonly ?AtlasLoopSelectAdjuster $selectAdjuster = null,
        private readonly ?AtlasLoopConstraintsBlockAssembler $constraintsBlockAssembler = null,
        private readonly ?AtlasLoopHypothesisTreeProducer $treeProducer = null,
        private readonly ?AtlasLoopObjectiveProducer $objectiveProducer = null,
        private readonly ?AtlasLoopBugReproductionLane $bugReproductionLane = null,
        private readonly ?\App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline $pipeline = null,
        // B0 — REAL WORK SUPPLY: the deterministic-RED handle harvester, run at the FRONT of the
        // refill so a configured phpunit JSON report becomes durable bug handles BEFORE discovery
        // stamps them onto target signals. Nullable + lazily resolved in refill() (mirrors the
        // pipeline arg, which is also not passed by the AppServiceProvider DI binding), so existing
        // refiller constructions (18 positional args) stay valid and the harvester self-resolves.
        private readonly ?SuiteRedTestHandleHarvester $failureHandleHarvester = null,
    ) {}

    /**
     * ARBOR-GRAFT TIER 0.1 — materialize the K competing readings the generator sampled (divergence path)
     * as sibling hypothesis nodes under the target, so the idea-tree becomes real. Flag-gated default-OFF +
     * fail-open + only when >=2 distinct readings exist => no producer call on the default path (byte-
     * identical). ADVISORY: the children are tree-nodes the constraints-block/SELECT/backprop read; they do
     * not change the grind flow or any gate.
     *
     * @param  array<string,mixed>  $gen  the generateBestForTarget result
     */
    private function materializeHypothesisTree(AtlasLoopTarget $target, array $gen): void
    {
        if ($this->treeProducer === null || ! (bool) config('atlas.loop.idea_tree_enabled', false)) {
            return;
        }
        $objectives = $gen['sampled_objectives'] ?? null;
        if (! is_array($objectives) || count($objectives) < 2) {
            return;
        }
        try {
            $this->treeProducer->materialize($target, array_values(array_map('strval', $objectives)));
        } catch (Throwable) {
            // advisory production: a failed tree materialization never breaks the refill
        }
    }

    /**
     * ARBOR-GRAFT #2 (companion) — record the objective being enqueued into the target's signals, so a later
     * metric MISS frames its failure-supply around the REAL objective the generator pursued (not just the
     * file path). Flag-gated on failure_supply_enabled => NO signal written when OFF (byte-identical). Touches
     * ONLY the signals column (markStatus owns status/attempts); fail-open — a failed stamp never blocks the
     * enqueue transition.
     */
    private function stampLastObjective(AtlasLoopTarget $target, string $objective): void
    {
        $objective = trim($objective);
        if ($objective === '' || ! (bool) config('atlas.loop.failure_supply_enabled', false)) {
            return;
        }
        try {
            $signals = is_array($target->signals) ? $target->signals : [];
            $signals['last_objective'] = mb_substr($objective, 0, 500);
            $encoded = json_encode($signals);
            if ($encoded === false) {
                return;
            }
            AtlasLoopTarget::query()->whereKey($target->id)->update(['signals' => $encoded]);
            $target->setAttribute('signals', $signals); // keep in-memory consistent for any later read
        } catch (Throwable) {
            // advisory: a failed stamp never blocks the enqueue transition
        }
    }

    /**
     * ARBOR-GRAFT W1b — assemble the advisory constraints-block (PRUNED LESSONS + VALIDATED FINDINGS + TREE
     * SHAPE + operator steering note) for the generation prompt. Flag-gated default-OFF + fail-open: returns
     * '' when OFF / no assembler / error / empty corpora => the generator prompt is byte-identical. CONTEXT
     * ONLY — never gates (the out-of-process cert is unchanged).
     */
    private function constraintsBlockFor(AtlasLoopCampaign $campaign): string
    {
        if ($this->constraintsBlockAssembler === null || ! (bool) config('atlas.loop.constraints_block_enabled', false)) {
            return '';
        }
        try {
            $note = is_array($campaign->config) ? ($campaign->config['steering_note'] ?? null) : null;
            $operatorNote = is_string($campaign->steering_note ?? null) ? (string) $campaign->steering_note : (is_string($note) ? $note : null);

            return $this->constraintsBlockAssembler->build((string) $campaign->id, [], $operatorNote);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * ACDE S1 — copy the (operator-armed) self-improvement marker from the discovery signals into a task
     * payload so certify() can stamp it on the proposal and the self-edit park gate fires. Applied at EVERY
     * enqueue lane so a harness self-edit is parked whichever route it takes. Self-gated + additive: an
     * ordinary target (no marker in signals) returns the payload UNCHANGED (byte-identical).
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function withSelfImprovementMarker(array $payload, array $signals): array
    {
        if (($signals['is_self_improvement'] ?? false) === true) {
            $payload['is_self_improvement'] = true;
            if (isset($signals['quality_bar'])) {
                $payload['quality_bar'] = $signals['quality_bar'];
            }
        }

        return $payload;
    }

    /**
     * DECISION ("o quê a seguir") — the next-work priority for a target, gated by the PER-CAMPAIGN
     * frozen pricing scheme. With the decider scheme OFF (default) it returns the legacy score*100,
     * byte-identical to today. With it ON it returns the ungameable BAND(shape)+OFFSET(leverage
     * re-resolved FRESH from the campaign workspace). FAIL-OPEN: a null decider or any error falls
     * back to the legacy value — never throws out of generateAndEnqueue, never regresses ordering.
     * The receipt (empty when OFF/failed) is stamped into the task payload under '_decision'.
     *
     * @param  array<string,mixed>  $signals
     * @return array{priority:int, receipt:array<string,mixed>}
     */
    private function decidedPriority(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $repoRoot, string $shapeHint): array
    {
        $legacy = (int) round(((float) $target->score) * 100);
        if ($this->nextWorkDecider === null || ! $this->frozenDecisionScheme($campaign)) {
            return ['priority' => $legacy, 'receipt' => []];
        }
        try {
            $d = $this->nextWorkDecider->decide($repoRoot, ltrim((string) $target->target_path, '/'), $signals, (float) $target->score, $shapeHint);
            [$priority, $receipt] = $this->applyWorkClassPrior((int) $d['priority'], (array) $d['receipt'], (string) $target->target_path);
            [$priority, $receipt] = $this->applySelectAdjuster($campaign, $target, $signals, $priority, $receipt);

            return ['priority' => $priority, 'receipt' => $receipt];
        } catch (Throwable) {
            return ['priority' => $legacy, 'receipt' => []];
        }
    }

    /**
     * ACDE M1 — de-prioritize a proven-HOPELESS work-class WITHIN its band, so the loop stops grinding a class
     * that empirically never lands before it burns budget on yet another file of that class (the per-target
     * gate only fires AFTER one file has burned N attempts; this generalizes to a brand-new file of the class).
     * Bounded to the band OFFSET and clamped to priority >= band, so a shape can NEVER cross into a lower band.
     * Flag-gated, fail-OPEN: OFF / null service / any error / thin evidence returns the inputs unchanged
     * (byte-identical). The nudge only ever LOWERS priority, never raises it, so it cannot promote bad work.
     *
     * @param  array<string,mixed>  $receipt
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function applyWorkClassPrior(int $priority, array $receipt, string $targetPath): array
    {
        if ($this->workClassPrior === null || ! (bool) config('atlas.loop.work_class_prior_enabled', false)) {
            return [$priority, $receipt];
        }
        try {
            $band = (int) ($receipt['band'] ?? 0);
            $offset = (int) ($receipt['offset'] ?? max(0, $priority - $band));
            if ($offset <= 0) {
                return [$priority, $receipt];
            }
            $prior = $this->workClassPrior->priorFor($targetPath);
            $weight = $this->workClassPrior->deprioritizationWeight($prior);
            if ($weight <= 0.0) {
                return [$priority, $receipt];
            }
            $maxFraction = max(0.0, min(1.0, (float) config('atlas.loop.work_class_prior_max_penalty_fraction', 0.8)));
            $penalty = (int) round($weight * $maxFraction * $offset);
            $receipt['_work_class_prior'] = [
                'work_class' => $prior['work_class'] ?? null,
                'real_attempts' => $prior['real_attempts'] ?? 0,
                'certified' => $prior['certified'] ?? 0,
                'wilson_lower' => $prior['wilson_lower'] ?? 0.0,
                'hopeless' => $prior['hopeless'] ?? false,
                'penalty' => $penalty,
            ];

            return [$band + max(0, $offset - $penalty), $receipt];
        } catch (Throwable) {
            return [$priority, $receipt];
        }
    }

    /**
     * ARBOR-GRAFT SEL1 — the third within-band term: a deterministic DIVERSITY/NOVELTY penalty so the loop
     * spreads exploration across distinct directions instead of over-committing to near-duplicate work (the
     * SELECT algorithm Arbor lacks). Operates on the CURRENT offset (priority - band, after the work-class
     * prior) and only LOWERS within the band — shares the single clamp so SHAPE always dominates. Machine-
     * resolved only (paths + queued/tree siblings, never a self-report). Flag-gated default-OFF, fail-OPEN.
     *
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $receipt
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function applySelectAdjuster(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, int $priority, array $receipt): array
    {
        if ($this->selectAdjuster === null || ! (bool) config('atlas.loop.select_adjuster_enabled', false)) {
            return [$priority, $receipt];
        }
        try {
            $band = (int) ($receipt['band'] ?? 0);
            $offset = max(0, $priority - $band); // the CURRENT within-band position (post work-class prior)
            if ($offset <= 0) {
                return [$priority, $receipt];
            }
            $maxFraction = max(0.0, min(1.0, (float) config('atlas.loop.select_adjuster_max_penalty_fraction', 0.5)));
            [$newPriority, $fragment] = $this->selectAdjuster->adjust($campaign, $target, $signals, $band, $offset, $maxFraction);
            if (is_array($fragment)) {
                $receipt['_select_adjuster'] = $fragment;
            }

            return [$newPriority, $receipt];
        } catch (Throwable) {
            return [$priority, $receipt];
        }
    }

    /**
     * The next-work pricing scheme is FROZEN per campaign at creation ({@see AtlasLoopStore::openCampaign}),
     * so an in-flight campaign never mixes the legacy and banded priority scales in one column. An
     * older campaign without the snapshot falls back to the live flag (default OFF => no mix).
     */
    private function frozenDecisionScheme(AtlasLoopCampaign $campaign): bool
    {
        $cfg = is_array($campaign->config) ? $campaign->config : [];
        if (array_key_exists('decision_priority_enabled', $cfg)) {
            return (bool) $cfg['decision_priority_enabled'];
        }

        return (bool) config('atlas.loop.decision_priority_enabled', false);
    }

    /**
     * Discover + generate + enqueue up to $want new tasks for a campaign.
     *
     * @return array{discovered:int, claimed:int, enqueued:int, quarantined:int, deferred:int, obra_candidates:int, failure_handle_harvest:array{status:string, scanned:int, harvested:int, dropped_environmental:int, dropped_unknown:int, dropped_unrunnable:int, dropped_ambiguous_target:int, write_failed:int}}
     */
    public function refill(AtlasLoopCampaign $campaign, int $want): array
    {
        $repoRoot = rtrim((string) $campaign->base_workspace, '/');
        $provider = (string) $campaign->provider; // '' => loop default (provider-agnostic)

        // B0 — REAL WORK SUPPLY: harvest deterministic-RED handles from the configured phpunit JSON
        // report BEFORE discovery runs, so a freshly-harvested handle is in atlas_loop_failure_handles
        // when the discovery FAILURE-HANDLE STAMP reads it this same cycle => the bug-fix lane is fed
        // live instead of starved. FAIL-OPEN by total contract (disabled / no path / missing report /
        // DB-less => a no-op receipt), so it can NEVER break a refill. Surfaced as a sibling receipt
        // key; the legacy counters (discovered/claimed/enqueued/quarantined/deferred) are untouched.
        $this->touchHeartbeat($campaign);
        $failureHandleHarvest = $this->harvestFailureHandles();
        $this->touchHeartbeat($campaign);

        $disc = $this->discovery->discover($repoRoot, $campaign->id, [
            'limit' => max($want * 2, $want + 4),
            'heartbeat_campaign_id' => (string) $campaign->id,
        ]);
        $this->touchHeartbeat($campaign);
        $targets = $this->repository->claimTop($campaign->id, $want);

        $enqueued = 0;
        $quarantined = 0;
        $deferred = 0;
        $producerLed = false;

        // HIGH-LEVERAGE OBJECTIVE PRODUCER (the rédea — default-OFF, fail-open). It runs FIRST, BEFORE
        // the per-target lanes, because it picks the single BIGGEST verifiable leap across the claimed
        // targets (cross-target leverage + the brain's strategic alignment the per-target lanes can't
        // see) — the loop's priority, not an afterthought starved by a slow per-target grind. It
        // enqueues in the refactor/obra band so the supervisor grinds the biggest leap first. Flag OFF
        // / nothing clears the floor / origination fail-closed => inert. Wrapped fail-open.
        if ((bool) config('atlas.loop.objective_producer_enabled', false) && $this->objectiveProducer !== null) {
            try {
                $relPaths = [];
                foreach ($targets as $t) {
                    $p = ltrim((string) $t->target_path, '/');
                    if ($p !== '') {
                        $relPaths[] = $p;
                    }
                }
                $built = $this->objectiveProducer->produce($repoRoot, $relPaths, $provider, (string) $campaign->id);
                if ($built !== null) {
                    // The synthesizers stamp payload['_target_id'] with the targetId we passed; the
                    // grinder's loop-back consumes it as a UUID (atlas_loop_targets.parent_target_id is
                    // uuid, no FK). The campaign id is a valid uuid so nothing crashes in-flight, but map
                    // the produced objective back to its REAL claimed-target id so lineage/history link
                    // correctly (fallback: a fresh uuid — never the non-uuid 'producer:' string that the
                    // first live run crashed on).
                    if (is_array($built['payload'] ?? null)) {
                        $producedPath = ltrim((string) ($built['target_path'] ?? ''), '/');
                        $realTargetId = null;
                        foreach ($targets as $t) {
                            if (ltrim((string) $t->target_path, '/') === $producedPath) {
                                $realTargetId = (string) $t->id;
                                break;
                            }
                        }
                        $built['payload']['_target_id'] = $realTargetId ?? (string) Str::uuid();
                    }
                    $priority = 4000 + min(999, (int) round((float) $built['leverage'] * 200));
                    $hash = (string) ($built['acceptance_hash'] ?? '');

                    // S2 — PROJECTION STAGE LIVE (flag default-ON). The rédea's biggest leap is NOT minted
                    // straight into a task: it is DISPATCHED into the async projection stage (a µs-returning
                    // pipeline row), where a separate worker runs the FROZEN designer↔critic engine to a
                    // content-fixpoint over typed obligations. Only a CONVERGED projection becomes a task;
                    // a non-converging one PARKS and never enters the queue. The objective id is STABLE
                    // (campaignId|target_path|acceptance_hash) so a re-dispatch every refill is idempotent.
                    // Flag OFF ⇒ the legacy direct-enqueue path below runs byte-identical.
                    $pipeline = $this->pipeline ?? app(AtlasLoopDeliveryPipeline::class);
                    if ((bool) config('atlas.loop.projection_stage_enabled', true) && $pipeline !== null) {
                        $realTargetId = is_array($built['payload'] ?? null) ? (string) ($built['payload']['_target_id'] ?? '') : '';
                        $objectiveId = hash('sha256', $campaign->id.'|'.((string) $built['target_path']).'|'.$hash);
                        $pipeline->dispatchProjection(
                            (string) $campaign->id,
                            $objectiveId,
                            (float) ($built['leverage'] ?? 0.0),
                            [
                                'built' => $built,
                                'repoRoot' => $repoRoot,
                                'priority' => $priority,
                                'real_target_id' => $realTargetId,
                            ],
                        );
                        // A projection row is OPEN work, not a task — mark the cycle producer-led so the
                        // per-target lanes can be skipped, but do NOT increment $enqueued (no task minted yet).
                        $producerLed = true;
                    } else {
                        $enq = $this->store->enqueueTask(
                            $campaign->id,
                            (string) $built['objective'],
                            (array) $built['payload'],
                            'producer:objective',
                            (string) $built['target_path'],
                            $priority,
                            (bool) ($built['self_contained'] ?? true), // refactor=self-contained; feature=framework
                            $hash !== '' ? $hash : null,
                        );
                        if ($enq !== null) {
                            $enqueued++;
                            $producerLed = true;
                        }
                    }
                }
            } catch (Throwable) {
                // fail-open: the rédea can never break a refill.
            }
        }

        foreach ($targets as $target) {
            // PRODUCER-EXCLUSIVE: when the rédea produced this cycle's biggest leap, it is the SOLE
            // work source — skip the slow per-target generation so the supervisor reaches the GRIND
            // phase within budget (one biggest leap per cycle = the meta's design). Flag default-OFF
            // => the per-target lanes run exactly as before.
            if ($producerLed && (bool) config('atlas.loop.producer_exclusive', false)) {
                break;
            }
            $outcome = $this->generateAndEnqueue($campaign, $target, $provider);
            if ($outcome === 'enqueued') {
                $enqueued++;
            } elseif ($outcome === 'quarantined') {
                $quarantined++;
            } else {
                $deferred++;
            }
            // Liveness during a long refill: generating a batch can take many minutes (a
            // provider call per self-contained target), but the supervisor only beats AFTER
            // refill() RETURNS. Without this, a cold-start refill freezes the campaign
            // heartbeat for 10-20min, so the keepalive watchdog and operator monitoring
            // cannot tell "working" from "hung". Refresh the heartbeat per target (liveness
            // only — never touches elapsed_seconds, so the wall-clock budget is unaffected).
            $this->touchHeartbeat($campaign);
        }

        // OBRA CANDIDATE PRODUCER (slice-1, default-OFF, fail-open): after discovery+enqueue,
        // scan the SAME claimed targets for high-leverage multi-file HUB clusters and park them
        // as operator-review obra CANDIDATES. It enqueues NO loop task, calls NO provider,
        // mutates NOTHING, and never changes discovered/claimed/enqueued/quarantined/deferred.
        // Flag OFF or detector unresolved => this block is inert and the counters are unchanged.
        $obraCandidates = 0;
        $obraRanking = null; // ACDE DC7 — populated only when the heavy-ranking flag is armed
        if ((bool) config('atlas.loop.obra_cluster_detection_enabled', false) && $this->obraClusterDetector !== null) {
            try {
                $detected = $this->obraClusterDetector->detect($campaign, $targets);
                $obraCandidates = count($detected);
                // ACDE DC7 — de-orphan the HeavyWorkSelector: rank the detected candidates by proven-leap
                // (REAL measured leverage evidence + REAL per-class accept stats from the decomposition-
                // outcomes ledger) so the operator's obra review surfaces the highest-value-proven cluster
                // first. Read-only; default OFF => no ranking key => byte-identical envelope.
                if ((bool) config('atlas.loop.obra_heavy_ranking_enabled', false) && $detected !== []) {
                    $obraRanking = (new AtlasLoopObraCandidateRanker)->rank($detected);
                }
            } catch (Throwable) {
                $obraCandidates = 0; // fail-open: the producer can never break a refill
            }
        }

        $result = [
            'discovered' => (int) $disc['upserted'],
            'claimed' => count($targets),
            'enqueued' => $enqueued,
            'quarantined' => $quarantined,
            'deferred' => $deferred,
            'obra_candidates' => $obraCandidates,
            'failure_handle_harvest' => $failureHandleHarvest,
        ];
        if ($obraRanking !== null) {
            $result['obra_pick'] = $obraRanking['pick'];
            $result['obra_ranked'] = $obraRanking['ranked'];
        }

        return $result;
    }

    /**
     * B0 — run the deterministic-RED handle harvester at the FRONT of the refill so a configured
     * phpunit JSON report becomes durable bug handles BEFORE discovery stamps them. The "enabled"
     * decision + the report-path default both live in {@see SuiteRedTestHandleHarvester} (single
     * source of truth: harvestEnabled() honors the legacy flat override then the canonical nested
     * flag; configuredReportPath() reads the canonical report_path). This method only DELEGATES and
     * surfaces a compact receipt. FAIL-OPEN by total contract: harvest disabled => the harvester
     * returns a 'disabled' no-op; an empty/missing report or DB-less context => 'report_missing' /
     * fail-open no-op rows; ANY thrown error => a compact 'error' receipt — it can NEVER break refill.
     *
     * @return array{status:string, scanned:int, harvested:int, dropped_environmental:int, dropped_unknown:int, dropped_unrunnable:int, dropped_ambiguous_target:int, write_failed:int}
     */
    private function harvestFailureHandles(): array
    {
        try {
            $harvester = $this->failureHandleHarvester ?? app(SuiteRedTestHandleHarvester::class);

            // Always delegate: the harvester short-circuits to a 'disabled' receipt when OFF (cheap,
            // no IO), so the enabled-decision is never re-implemented here (no config drift). The
            // configured report_path is the canonical default; a null path yields 'report_missing'.
            return $harvester->harvest(SuiteRedTestHandleHarvester::configuredReportPath());
        } catch (Throwable) {
            return [
                'status' => 'error',
                'scanned' => 0,
                'harvested' => 0,
                'dropped_environmental' => 0,
                'dropped_unknown' => 0,
                'dropped_unrunnable' => 0,
                'dropped_ambiguous_target' => 0,
                'write_failed' => 0,
            ];
        }
    }

    /**
     * Best-effort liveness ping during a long refill. Updates ONLY heartbeat_at (never
     * elapsed_seconds, so the wall-clock budget is untouched). Never throws — a heartbeat
     * write must not break task generation.
     */
    private function touchHeartbeat(AtlasLoopCampaign $campaign): void
    {
        try {
            $dir = storage_path('atlas-loop/campaign/'.(string) $campaign->id);
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
            @file_put_contents($dir.'/heartbeat', (string) time());

            DB::table('atlas_loop_campaigns')
                ->where('id', $campaign->id)
                ->update(['heartbeat_at' => now()]);
        } catch (Throwable) {
            // liveness ping is best-effort; ignore failures
        }
    }

    private function generateAndEnqueue(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, string $provider): string
    {
        $repoRoot = rtrim((string) $campaign->base_workspace, '/');
        $source = $repoRoot.'/'.ltrim((string) $target->target_path, '/');
        if (! is_file($source)) {
            $this->loopBack->reflect($campaign->id, ['target_id' => $target->id, 'status' => 'no_winner', 'reason' => 'self_contain_missing_source']);

            return 'quarantined';
        }

        // L2-2 (breadth): target framework-reach NÃO passa pelo gerador self-contained —
        // vai direto para o caminho framework do grinder (worktree real + intent-verifier
        // compilado deterministicamente + certificação adversarial universal). É o
        // caminho pelo qual o Loop melhora serviços REAIS do Atlas.
        $signals = is_array($target->signals) ? $target->signals : [];

        // DECISION ("o quê a seguir"): the work-shape router REASONS the highest-leverage shape
        // from the already-stamped signals (replacing pure flag-routing). Its load-bearing
        // decision HERE is work_skip — a CONFIRMED orphan is DEFERRED, not ground: don't spend a
        // provider call on dead code (the honest decision the static cascade lacked). The
        // refactor/edge lanes below remain the executors and keep every RED-gate. Flag-gated
        // (default ON); fail-open — the router only skips on a measured orphan, never on missing
        // data, so the cascade is byte-identical for every non-orphan target.
        $routedShape = null;
        if ((bool) config('atlas.loop.decision_router_enabled', true)) {
            $decision = ($this->workShapeRouter ?? new AtlasLoopWorkShapeRouter)->decideShape($signals);
            $routedShape = (string) ($decision['shape'] ?? '');
            if ($routedShape === AtlasLoopWorkShapeRouter::SHAPE_SKIP) {
                $this->loopBack->reflect($campaign->id, [
                    'target_id' => $target->id,
                    'status' => 'no_winner',
                    'reason' => 'work_shape_skip:'.(string) ($decision['reason'] ?? 'low_leverage'),
                ]);

                return 'deferred';
            }
        }

        // SHAPE VOCABULARY BROADENING — when the (broadened) router NAMES a heavier shape, prefer the
        // heavier synthesizer the rédea already depends on, before the single-file cascade below:
        //   - extract_class : the 2-file god-method split via synthesizeFrameworkRefactor(extractClass:true)
        //   - multi_file    : the >=2-file coupled-cluster refactor via synthesizeMultiFileRefactor()
        // Each is FAIL-OPEN: if the heavier synthesizer returns null the routing falls through to the
        // existing single-file path, so nothing regresses. Both router shapes are themselves gated
        // default-OFF inside the router, so on the default path $routedShape is never one of these and
        // this block is inert (byte-identical). The synthesizers keep every RED/structural-cert gate.
        if ($routedShape === AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE) {
            $outcome = $this->tryRoutedMultiFileRefactor($campaign, $target, $signals, $provider, $repoRoot);
            if ($outcome !== null) {
                return $outcome;
            }
        }
        if ($routedShape === AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS) {
            $outcome = $this->tryFrameworkRefactor($campaign, $target, $signals, $provider, $repoRoot, true);
            if ($outcome !== null) {
                return $outcome;
            }
        }

        // OPTION 3 (operator-gated autonomous MULTI-FILE refactor): if this target is the HUB of a
        // coherent high-leverage cluster, synthesize a >=2-file `refactor_reduce_complexity` task
        // that the grinder HARD-routes to the obra bridge (operator-review, NEVER auto-merge). It
        // runs BEFORE the single-file framework lane so a real cluster goes to the obra route, not
        // a single-file refactor. CO-GATED (adversary finding): requires BOTH
        // multi_file_refactor_objectives_enabled AND refactor_multi_file_via_obra ON — otherwise a
        // multi-file task would fall through to the single-file grind at route time and a >=2-file
        // diff could reach main with only a single-target canary. With either flag OFF, or any
        // null, the lane is inert and the cascade below is byte-identical.
        if ($this->proxyRefactorSupplyEnabled()
            && (bool) config('atlas.loop.multi_file_refactor_objectives_enabled', false)
            && (bool) config('atlas.loop.refactor_multi_file_via_obra', false)
            && $this->obraClusterDetector !== null
            && $this->multiFileRefactorSynthesizer !== null) {
            $cluster = $this->obraClusterDetector->candidateFor($target, $campaign);
            if ($cluster !== null) {
                $synth = $this->multiFileRefactorSynthesizer->synthesizeMultiFileRefactor($cluster, $repoRoot, $signals, $provider);
                if ($synth !== null) {
                    $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, 'multi_file_refactor');
                    $mfPayload = $synth['payload'];
                    if ($dp['receipt'] !== []) {
                        $mfPayload['_decision'] = $dp['receipt'];
                    }
                    $mfPayload = $this->withSelfImprovementMarker($mfPayload, $signals);
                    $enq = $this->store->enqueueTask(
                        $campaign->id,
                        $synth['objective'],
                        $mfPayload,
                        'discovery',
                        (string) $target->target_path,
                        $dp['priority'],
                        true,
                        $synth['acceptance_hash'],
                    );
                    $this->stampLastObjective($target, (string) $synth['objective']);
                    $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'multi_file_refactor_task_synthesized');

                    return $enq !== null ? 'enqueued' : 'deferred';
                }
            }
        }

        $coverageOutcome = $this->tryCoverageDeficitCharacterization($campaign, $target, $signals, $provider, $source);
        if ($coverageOutcome !== null) {
            return $coverageOutcome;
        }

        // §11.4 BUG-FIX REPRODUCTION LANE: when this target's discovery signals carry a runnable
        // FAILURE handle (a reproducing test path or an explicit failing command), shape a
        // reproduce-then-fix RED-required objective and enqueue it as a FIRST-CLASS bug_fix —
        // BEFORE the refactor/framework cascade (a known break beats speculative complexity work).
        // The lane is fail-closed (it returns null unless target_path + a runnable handle exist), so
        // an ordinary target with no failure handle falls through byte-identical. Flag-gated default-ON.
        // NOTE: nothing currently STAMPS failure_test_path/failure_command into discovery signals, so
        // on the live default path this branch is inert until a failure source populates them.
        $bugOutcome = $this->tryBugReproduction($campaign, $target, $signals, $provider, $repoRoot);
        if ($bugOutcome !== null) {
            return $bugOutcome;
        }

        if ((int) ($signals['framework_reach'] ?? 0) > 0) {
            // FRAMEWORK REFACTOR (heavy, behavior-preserving): BEFORE the edge-gap objective, try
            // to synthesize a `refactor_reduce_complexity` objective STRUCTURALLY (no provider
            // call) for a HIGH-COMPLEXITY framework target that is WIRED (>=1 real caller) AND has
            // real PHPUnit tests. The framework path runs those REAL tests (behavior preserved) and
            // the semantic certifier proves an AST cyclomatic DROP. Default OFF; fail-closed (the
            // synthesizer returns null => fall through to the edge-gap path, byte-identical to
            // today). PETREO: a forbidden self-target is rejected here before enqueue
            // (belt-and-suspenders; discovery's admit() already filters them).
            $outcome = $this->tryFrameworkRefactor($campaign, $target, $signals, $provider, $repoRoot);
            if ($outcome !== null) {
                return $outcome;
            }

            if ((bool) config('atlas.loop.framework_edge_gap_fallback_enabled', true)) {
                $payload = [
                    'materializer' => 'framework',
                    'intent_verifier_factory' => true,
                    'target_relative_path' => ltrim((string) $target->target_path, '/'),
                    'allowed_files' => [ltrim((string) $target->target_path, '/')],
                    '_target_id' => $target->id,
                ];
                if ($provider !== '') {
                    $payload['provider'] = $provider;
                }
                $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
                if ($dp['receipt'] !== []) {
                    $payload['_decision'] = $dp['receipt'];
                }
                $objective = 'Improve '.basename((string) $target->target_path).' guided by its improvement signals (edge gaps, branch density) — framework target.';
                $enq = $this->store->enqueueTask(
                    $campaign->id,
                    $objective,
                    $payload,
                    'discovery',
                    (string) $target->target_path,
                    $dp['priority'],
                    true,
                    '',
                );
                $this->stampLastObjective($target, $objective);
                $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'framework_task_enqueued');

                return $enq !== null ? 'enqueued' : 'deferred';
            }
        }

        // GOVERNED REFACTOR (Phase 1 · within-file): BEFORE the provider-call generator, try
        // to synthesize a `refactor_reduce_complexity` objective STRUCTURALLY (no provider
        // call) for a high-complexity self-contained file that already has a real sibling
        // test. The frozen judge certifies it ONLY when the frozen sibling test stays GREEN
        // (behavior preserved — the loop can never edit it) AND a real AST cyclomatic measure
        // drops. Default OFF; fail-closed (synthesizer returns null => fall through to the
        // normal generator). PETREO: a forbidden self-target is rejected here before enqueue
        // (belt-and-suspenders; discovery's admit() already filters them).
        if ($this->proxyRefactorSupplyEnabled()
            && (bool) config('atlas.loop.refactor_objectives_enabled', false)
            && $this->refactorSynthesizer !== null) {
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            if (! $guard->isForbiddenSelfTarget((string) $target->target_path)) {
                $refactor = $this->refactorSynthesizer->synthesize(
                    $repoRoot,
                    ltrim((string) $target->target_path, '/'),
                    $signals,
                    $provider,
                    $target->id,
                );
                if ($refactor !== null) {
                    $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
                    $rfPayload = $refactor['payload'];
                    if ($dp['receipt'] !== []) {
                        $rfPayload['_decision'] = $dp['receipt'];
                    }
                    $rfPayload = $this->withSelfImprovementMarker($rfPayload, $signals);
                    $enq = $this->store->enqueueTask(
                        $campaign->id,
                        $refactor['objective'],
                        $rfPayload,
                        'discovery',
                        (string) $target->target_path,
                        $dp['priority'],
                        true,
                        $refactor['acceptance_hash'],
                    );
                    $this->stampLastObjective($target, (string) $refactor['objective']);
                    $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'refactor_task_synthesized');

                    return $enq !== null ? 'enqueued' : 'deferred';
                }
            }
        }

        // PHPUnit-sibling refactor fallback: the Phase-1 plain-`php` synthesizer above admits ONLY
        // the rare require-style sibling. The COMMON case is a pure-logic self-contained service
        // (framework_reach==0) whose only honest behavior anchor is a PHPUnit sibling — which the
        // plain-`php` harness cannot run. Route it through the FRAMEWORK materializer (worktree +
        // real autoload + ./vendor/bin/phpunit), the right tool for ANY file with a PHPUnit anchor.
        // framework_reach==0 only means NEW-behavior grind needs no framework boot; a behavior-
        // PRESERVING refactor still wants the worktree. Same complexity_proof certification.
        // (Without this, the heavy-refactor lane was structurally dead for the whole real codebase.)
        $outcome = $this->tryFrameworkRefactor($campaign, $target, $signals, $provider, $repoRoot);
        if ($outcome !== null) {
            return $outcome;
        }

        if (! (bool) config('atlas.loop.generic_provider_fallback_enabled', true)) {
            $this->loopBack->reflect($campaign->id, [
                'target_id' => $target->id,
                'status' => 'no_winner',
                'reason' => 'generic_provider_fallback_disabled',
            ]);
            $this->repository->quarantine($target->id, 'generic_provider_fallback_disabled');

            return 'quarantined';
        }

        $base = sys_get_temp_dir().'/atlas-loop-gen-'.bin2hex(random_bytes(5));
        $targetRel = 'src/'.basename((string) $target->target_path);
        $cleanup = static function () use ($base): void {
            if (is_dir($base)) {
                (new Process(['rm', '-rf', $base]))->run();
            }
        };

        try {
            @mkdir($base.'/src', 0o755, true);
            @mkdir($base.'/tests', 0o755, true);
            file_put_contents($base.'/composer.json', "{}\n");
            copy($source, $base.'/'.$targetRel);

            $genOptions = ['provider' => $provider, 'index' => 0];
            $constraintsBlock = $this->constraintsBlockFor($campaign); // ARBOR-GRAFT W1b (flag-OFF => '')
            if ($constraintsBlock !== '') {
                $genOptions['constraints_block'] = $constraintsBlock;
            }
            $gen = $this->generator->generateBestForTarget($base, $targetRel, $genOptions);
            $this->materializeHypothesisTree($target, $gen); // ARBOR-GRAFT TIER 0.1 (flag-OFF / <2 readings => no-op)
            if (! (bool) ($gen['generated'] ?? false)) {
                // Not genuinely RED / no real work -> loop-back classifies (quarantine vs requeue).
                $this->loopBack->reflect($campaign->id, ['target_id' => $target->id, 'status' => 'no_winner', 'reason' => (string) ($gen['reason'] ?? 'not_red')]);
                $cleanup();

                return 'quarantined';
            }

            $task = $gen['task'];
            $payload = $this->snapshotPayload($base, $task, $targetRel, $provider, $target->id);
            $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
            if ($dp['receipt'] !== []) {
                $payload['_decision'] = $dp['receipt'];
            }
            $payload = $this->withSelfImprovementMarker($payload, $signals);
            $enq = $this->store->enqueueTask(
                $campaign->id,
                (string) $task['objective'],
                $payload,
                AtlasLoopTarget::ORIGIN_DISCOVERY === ($target->lineage['origin'] ?? AtlasLoopTarget::ORIGIN_DISCOVERY) ? 'discovery' : 'loopback',
                (string) $target->target_path,
                $dp['priority'],
                true,
                (string) ($task['acceptance']['acceptance_hash'] ?? ''),
            );

            $this->stampLastObjective($target, (string) $task['objective']);
            $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'task_generated');
            $cleanup();

            return $enq !== null ? 'enqueued' : 'deferred';
        } catch (Throwable $e) {
            $cleanup();
            $this->loopBack->reflect($campaign->id, ['target_id' => $target->id, 'status' => 'no_winner', 'reason' => 'materialize_'.mb_substr($e->getMessage(), 0, 60)]);

            return 'quarantined';
        }
    }

    /**
     * Snapshot the generated scoped workspace into a DURABLE, restart-safe payload: the
     * unchanged target content + the frozen test bodies + acceptance. The materializer
     * rebuilds an identical workspace at grind time, with no dependency on this temp dir.
     *
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    /**
     * HEAVY REFACTOR attempt (behavior-preserving, AST complexity-drop certified) for a
     * high-complexity WIRED target whose behavior anchor is a real PHPUnit sibling. Routes
     * through the FRAMEWORK materializer (worktree + real autoload + ./vendor/bin/phpunit),
     * which runs PHPUnit correctly — so it is the right tool for BOTH framework-reach files AND
     * pure-logic self-contained files (the common case: framework_reach==0 but the only honest
     * behavior anchor is a PHPUnit test, which the plain-`php` Phase-1 synthesizer cannot run).
     * Flag-gated by `framework_refactor_enabled`, fail-closed: returns the enqueue outcome
     * ('enqueued'|'deferred') when a refactor task was synthesized, or null when the target is
     * not refactor-eligible (the caller falls through to its normal path). PETREO: a forbidden
     * self-target is rejected here before enqueue (belt-and-suspenders; discovery already filters).
     *
     * @param  array<string,mixed>  $signals
     * @param  bool  $forceExtractClass  when the broadened router NAMED the extract_class
     *                                   shape, request the 2-file extract-class objective
     *                                   directly (the rédea's heavier-shape origination),
     *                                   independent of the PATH-B static-flag heuristic
     */
    private function tryFrameworkRefactor(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $provider, string $repoRoot, bool $forceExtractClass = false): ?string
    {
        if (! $this->proxyRefactorSupplyEnabled()) {
            return null;
        }
        if (! (bool) config('atlas.loop.framework_refactor_enabled', false)) {
            return null;
        }
        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
        if ($guard->isForbiddenSelfTarget((string) $target->target_path)) {
            return null;
        }
        $synth = $this->frameworkRefactorSynthesizer ?? new AtlasLoopFrameworkRefactorSynthesizer;
        // PATH B: escalate a sufficiently-complex target to a MULTI-FILE extract-class objective
        // (routed to the normal grind via the structural cert, NOT the Obra bridge) when the lane is
        // enabled. Below the threshold — or with the lane OFF — it stays a single-file in-place
        // reduction (byte-identical). The synth reuses the SAME complex/wired/sibling gates for both.
        // $forceExtractClass: the broadened router already reasoned the extract_class shape (wired +
        // test-backed + cyclomatic well above the floor), so honor it directly here.
        $extractClass = $forceExtractClass || ((bool) config('atlas.loop.multi_file_refactor_via_normal_lane', false)
            && (int) ($signals['cyclomatic'] ?? 0) >= max(1, (int) config('atlas.loop.extract_class_min_cyclomatic', 15)));
        $refactor = $synth->synthesizeFrameworkRefactor(
            $repoRoot,
            ltrim((string) $target->target_path, '/'),
            $signals,
            $provider,
            $target->id,
            $extractClass,
        );
        if ($refactor === null) {
            return null;
        }
        $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        $frPayload = $refactor['payload'];
        if ($dp['receipt'] !== []) {
            $frPayload['_decision'] = $dp['receipt'];
        }
        $frPayload = $this->withSelfImprovementMarker($frPayload, $signals);
        $enq = $this->store->enqueueTask(
            $campaign->id,
            $refactor['objective'],
            $frPayload,
            'discovery',
            (string) $target->target_path,
            $dp['priority'],
            true,
            $refactor['acceptance_hash'],
        );
        $this->stampLastObjective($target, (string) $refactor['objective']);
        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'framework_refactor_task_synthesized');

        return $enq !== null ? 'enqueued' : 'deferred';
    }

    /**
     * §11.6 EXECUTOR WIRING: discovery can stamp shape=characterization_test for a real coverage
     * deficit (decision-dense production file with no sibling test). That was only a SOURCE signal;
     * without this lane the refiller fell through to the generic generator and live campaigns ended
     * with targets>0/tasks=0. Convert the stamped shape into the already-governed characterization
     * task: production target frozen, new sibling test allowed, downstream mutant-kill verifier proves
     * the test is not theatre. Flag-gated by characterization_test_lane_enabled because the grinder's
     * cert branch must be armed before such tasks can be safe.
     *
     * @param  array<string,mixed>  $signals
     */
    private function tryCoverageDeficitCharacterization(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $provider, string $source): ?string
    {
        if (! (bool) config('atlas.loop.characterization_test_lane_enabled', false)) {
            return null;
        }
        if ((string) ($signals['shape'] ?? '') !== AtlasLoopCoverageDeficitSource::SHAPE) {
            return null;
        }
        if (! is_file($source)) {
            return null;
        }

        $operator = trim((string) ($signals['coverage_operator'] ?? $signals['coverage_deficit_operator'] ?? ''));
        if ($operator === '' || AtlasLoopMutationOperators::isCosmetic($operator)) {
            $operator = $this->firstNonCosmeticFrozenOperator($source);
        }
        if ($operator === '') {
            $this->repository->quarantine($target->id, 'coverage_deficit_no_non_cosmetic_operator');

            return 'quarantined';
        }

        $sibling = is_string($signals['sibling_test_path'] ?? null) ? trim((string) $signals['sibling_test_path']) : '';
        $taskId = app(AtlasLoopCoverageGapFeeder::class)->feedGap((string) $campaign->id, [
            'target_file' => ltrim((string) $target->target_path, '/'),
            'decision_operator' => $operator,
            'mutation_id' => 'coverage_deficit:'.$operator,
            'sibling_test' => $sibling !== '' ? $sibling : null,
        ], $provider !== '' ? $provider : null, true);

        if ($taskId === null) {
            $this->repository->quarantine($target->id, 'coverage_deficit_unfeedable');

            return 'quarantined';
        }

        $objective = is_string($signals['coverage_objective'] ?? null) && trim((string) $signals['coverage_objective']) !== ''
            ? (string) $signals['coverage_objective']
            : 'Create a characterization test for '.ltrim((string) $target->target_path, '/');
        $this->stampLastObjective($target, $objective);
        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'coverage_deficit_characterization_task_enqueued');

        return 'enqueued';
    }

    private function firstNonCosmeticFrozenOperator(string $source): string
    {
        $body = @file_get_contents($source);
        if (! is_string($body) || $body === '') {
            return '';
        }
        foreach (array_keys(AtlasLoopFrozenMutationOperators::neighborhood($body)) as $operator) {
            if (! AtlasLoopMutationOperators::isCosmetic($operator)) {
                return (string) $operator;
            }
        }

        return '';
    }

    /**
     * §11.4 BUG-FIX REPRODUCTION LANE seam. When the target's discovery signals carry a runnable
     * failure handle (`failure_test_path` or `failure_command`, optionally with the failing
     * assertion/message for objective context), ask {@see AtlasLoopBugReproductionLane} to shape a
     * reproduce-then-fix objective. On a non-null result, enqueue a FIRST-CLASS `bug_fix` task whose
     * acceptance is the lane's RED-required failing command, tagged `revert_recheck=true` (a bug-fix
     * is behavior-CHANGING — the inversion of a refactor's behavior-preserving frozen sibling) and
     * `objective_kind=bug_fix`, then return the enqueue outcome. Fail-closed: a target with no runnable
     * failure handle (the lane returns null) falls through byte-identical to the cascade. Flag-gated by
     * `bug_reproduction_lane_enabled` (default ON). PETREO: a forbidden self-target is rejected before
     * enqueue (belt-and-suspenders; discovery's admit() already filters them).
     *
     * @param  array<string,mixed>  $signals
     */
    private function tryBugReproduction(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $provider, string $repoRoot): ?string
    {
        if (! (bool) config('atlas.loop.bug_reproduction_lane_enabled', true) || $this->bugReproductionLane === null) {
            return null;
        }
        // A runnable failure handle MUST be present (a reproducing test path or an explicit command);
        // without it the lane is the model-bound repro-synthesis half we never fake here => null.
        $testPath = $signals['failure_test_path'] ?? null;
        $command = $signals['failure_command'] ?? null;
        if (! (is_string($testPath) && trim($testPath) !== '') && ! (is_string($command) && trim($command) !== '')) {
            return null;
        }
        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
        if ($guard->isForbiddenSelfTarget((string) $target->target_path)) {
            return null;
        }

        $repro = $this->bugReproductionLane->toReproductionObjective([
            'target_path' => ltrim((string) $target->target_path, '/'),
            'test_path' => is_string($testPath) ? $testPath : null,
            'command' => is_string($command) ? $command : null,
            'failing_assertion' => is_string($signals['failing_assertion'] ?? null) ? $signals['failing_assertion'] : null,
            'message' => is_string($signals['failure_message'] ?? null) ? $signals['failure_message'] : null,
        ]);
        if ($repro === null) {
            return null;
        }

        $acceptance = $repro['acceptance'];
        $acceptance['revert_recheck'] = true; // behavior-CHANGING: a bug-fix is NOT a frozen-sibling refactor
        $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopBugReproductionLane::SHAPE);
        $payload = [
            'target_relative_path' => $repro['target_path'],
            'allowed_files' => [$repro['target_path']],
            'acceptance' => $acceptance,
            'revert_recheck' => true,
            'objective_kind' => AtlasLoopBugReproductionLane::SHAPE,
            '_target_id' => $target->id,
        ];
        if ($provider !== '') {
            $payload['provider'] = $provider;
        }
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }
        $payload = $this->withSelfImprovementMarker($payload, $signals);
        $acceptanceHash = hash('sha256', json_encode($acceptance) ?: $repro['target_path']);
        $enq = $this->store->enqueueTask(
            $campaign->id,
            $repro['objective'],
            $payload,
            'discovery',
            (string) $target->target_path,
            $dp['priority'],
            true,
            $acceptanceHash,
        );
        $this->stampLastObjective($target, (string) $repro['objective']);
        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'bug_reproduction_task_synthesized');

        return $enq !== null ? 'enqueued' : 'deferred';
    }

    /**
     * SHAPE VOCABULARY BROADENING — the multi_file lane reached via the (broadened) router shape.
     * Resolves the coupled cluster for this hub ({@see AtlasLoopObraClusterDetectorService::candidateFor})
     * and synthesizes the >=2-file refactor with the SAME machinery the static OPTION-3 cascade uses
     * (synthesizeMultiFileRefactor → structural cert + every sibling test re-run downstream). Returns the
     * enqueue outcome, or null when the cluster cannot be resolved / synthesized — so the caller falls
     * through byte-identical to the single-file cascade (fail-open, nothing regresses). NEVER auto-merges
     * (the multi-file diff requires operator approval downstream like the existing lane).
     *
     * @param  array<string,mixed>  $signals
     */
    private function tryRoutedMultiFileRefactor(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $provider, string $repoRoot): ?string
    {
        if (! $this->proxyRefactorSupplyEnabled()) {
            return null;
        }
        if ($this->obraClusterDetector === null || $this->multiFileRefactorSynthesizer === null) {
            return null;
        }
        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
        if ($guard->isForbiddenSelfTarget((string) $target->target_path)) {
            return null;
        }
        $cluster = $this->obraClusterDetector->candidateFor($target, $campaign);
        if ($cluster === null) {
            return null;
        }
        $synth = $this->multiFileRefactorSynthesizer->synthesizeMultiFileRefactor($cluster, $repoRoot, $signals, $provider);
        if ($synth === null) {
            return null;
        }
        $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, 'multi_file_refactor');
        $mfPayload = $synth['payload'];
        if ($dp['receipt'] !== []) {
            $mfPayload['_decision'] = $dp['receipt'];
        }
        $mfPayload = $this->withSelfImprovementMarker($mfPayload, $signals);
        $enq = $this->store->enqueueTask(
            $campaign->id,
            $synth['objective'],
            $mfPayload,
            'discovery',
            (string) $target->target_path,
            $dp['priority'],
            true,
            $synth['acceptance_hash'],
        );
        $this->stampLastObjective($target, (string) $synth['objective']);
        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'multi_file_refactor_task_synthesized');

        return $enq !== null ? 'enqueued' : 'deferred';
    }

    private function proxyRefactorSupplyEnabled(): bool
    {
        return (bool) config('atlas.loop.proxy_refactor_supply_enabled', true);
    }

    private function snapshotPayload(string $base, array $task, string $targetRel, string $provider, string $targetId): array
    {
        $frozenTests = [];
        foreach (glob($base.'/tests/*') ?: [] as $file) {
            if (is_file($file)) {
                $frozenTests[] = ['path' => 'tests/'.basename($file), 'content' => (string) file_get_contents($file)];
            }
        }

        $payload = [
            'target_repo_path' => (string) ($task['allowed_files'][0] ?? $targetRel),
            'target_relative_path' => $targetRel,
            'target_content' => (string) file_get_contents($base.'/'.$targetRel),
            'frozen_tests' => $frozenTests,
            'acceptance' => is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [],
            'allowed_files' => is_array($task['allowed_files'] ?? null) ? $task['allowed_files'] : [$targetRel],
            'validation_commands' => is_array($task['validation_commands'] ?? null) ? $task['validation_commands'] : [],
            '_target_id' => $targetId, // loop-back maps the Result back to its Source
        ];
        if ($provider !== '') {
            $payload['provider'] = $provider;
        }

        return $payload;
    }
}
