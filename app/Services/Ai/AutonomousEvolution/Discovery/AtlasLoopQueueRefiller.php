<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use App\Services\Ai\AutonomousEvolution\AtlasLoopSelfImprovementGroundingBridge;
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
    private const EXTRACT_CLASS_OBJECTIVE_KIND = 'refactor_extract_class';

    /** @var array<string,array{active:bool, timeouts:int, successes:int, window_hours:int}> */
    private array $extractClassBackoffCache = [];

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
        // NET-NEW MATERIAL SUPPLY: the per-method complexity decomposer that finds the UNTAPPED material
        // refactor methods (cyclomatic >= the material bar) the single-objective lane abandons. Nullable +
        // lazily resolved in the lane (mirrors the harvester/pipeline args, also not DI-passed), so every
        // existing positional refiller construction stays valid and the decomposer self-resolves.
        private readonly ?AtlasLoopComplexTargetDecomposer $complexTargetDecomposer = null,
        // §5.5 — the egress-safe research TOPIC producer. Nullable + lazily resolved at the authoring site
        // (mirrors the harvester/decomposer args, also not DI-passed), so every existing positional refiller
        // construction stays valid and the deriver self-resolves. Default-OFF => returns [] => byte-identical.
        private readonly ?AtlasLoopResearchTopicDeriver $researchTopicDeriver = null,
    ) {}

    /** C — characterization/coverage tasks minted in the CURRENT refill (reset each refill); the portfolio cap. */
    private int $coverageMintedThisRefill = 0;

    /** D2 — substantive (bug/feature/self-improvement/material-refactor) tasks minted this refill; the relative-cap base. */
    private int $substantiveMintedThisRefill = 0;

    /**
     * P1-A — the request-scoped memoizing comprehension queries, keyed by (repoRoot, opts) signature. The
     * dedup and orphan-wiring lanes build with IDENTICAL empty-docs opts, so they share ONE query and the
     * ~9s model build runs ONCE for both instead of once per lane; the doc-gap lane (docs_roots set => a
     * genuinely different model) gets its own. Reset at the top of refill() so a model is never served stale
     * across refills.
     *
     * @var array<string, AtlasLoopScopeComprehensionQuery>
     */
    private array $comprehensionQueries = [];

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
     * @return array{discovered:int, reopened:int, claimed:int, enqueued:int, quarantined:int, deferred:int, obra_candidates:int, failure_handle_harvest:array{status:string, scanned:int, harvested:int, dropped_environmental:int, dropped_unknown:int, dropped_unrunnable:int, dropped_ambiguous_target:int, write_failed:int}}
     */
    public function refill(AtlasLoopCampaign $campaign, int $want): array
    {
        $repoRoot = rtrim((string) $campaign->base_workspace, '/');
        $provider = (string) $campaign->provider; // '' => loop default (provider-agnostic)
        $this->coverageMintedThisRefill = 0; // C — reset the per-refill coverage portfolio cap
        $this->substantiveMintedThisRefill = 0; // D2 — reset the per-refill substantive-work counter
        $this->comprehensionQueries = []; // P1-A — fresh comprehension memo per refill (never serve a stale model)

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
        $reopened = $this->repository->reopenPolicyBlockedForStructuredSupply($campaign->id, $want);
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
                    // Material work targets PRODUCTION code. A TEST file as the objective-producer's target
                    // yields only added assertions (characterization/coverage = PROXY, forbidden by the
                    // canonical def). Live drift seen 06-23: 3 of 5 soak tasks targeted *Test.php and emitted
                    // "Assert that …" coverage proposals. Keep test files out of the candidate set so the
                    // producer can only originate behaviour-changing production fixes.
                    if ($p !== '' && ! self::isTestPath($p)) {
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
                        $dispatched = $pipeline->dispatchProjection(
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
                        // If insertOrIgnore says the objective already exists (for example parked/exhausted),
                        // do not suppress the target lanes; otherwise the loop can starvation-spin on a
                        // duplicate projection that cannot mint live work.
                        if ($dispatched) {
                            $producerLed = true;
                        }
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
                            $this->substantiveMintedThisRefill++; // D2 — the rédea's driver-governed leap is substantive
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

        // NET-NEW MATERIAL SUPPLY LANE (default-OFF, fail-open): the per-target claim loop above mints at
        // most ONE refactor per file (its worst method). After the first wave of files, single-objective
        // substantive supply runs dry and the loop fills every refill with coverage — yet the SAME
        // multi-method complex files still hold many untapped material methods (cyclomatic >= the material
        // bar). This lane drains that supply: for each complex file in the discovery scope with NO in-flight
        // task it re-mints a governed refactor task (the SAME framework-refactor synthesizer + complexity-proof
        // cert as a normal refactor; never proxy, never coverage), serialized at most ONE in-flight task per
        // file. It runs AFTER the per-target lanes so substantive single-objective work is preferred and
        // BEFORE coverage would dominate (coverage is minted in the per-target loop, so re-running discovery
        // is not needed; the cap below prefers material over the next refill's coverage). Flag OFF => the
        // method early-returns 0 (refill byte-identical to today). Wrapped fail-open: it can NEVER break a refill.
        $enqueued += $this->tryDecomposeMaterialSupply($campaign, $provider, $repoRoot, $want);

        // §5.6 DEDUP-SUPPLY LANE (default-OFF, fail-open): the BRAIN drives selection — the comprehension model's
        // clone clusters become CERTIFIABLE clone-unification tasks the proxy scan structurally cannot produce.
        // Flag OFF => returns 0 before any model build (refill byte-identical to today). Wrapped fail-open.
        $enqueued += $this->tryDedupSupply($campaign, $provider, $repoRoot, $want);

        // §5.6 ORPHAN-WIRING SUPPLY LANE (default-OFF, fail-open): the BRAIN drives selection — the comprehension
        // model's ORPHANS (tested, built-but-unwired capabilities the proxy scan never surfaces) become wiring
        // DIRECTIVES the grinder routes to AtlasLoopOrphanWiringExecutionAdapter (engine authors the earned-RED
        // test + wiring; Guard 4e certifies). Flag OFF => returns 0 before any model build (byte-identical).
        $enqueued += $this->tryOrphanWiringSupply($campaign, $provider, $repoRoot, $want);

        // §2 DOC-GAP SUPPLY LANE (default-OFF, fail-open): the BRAIN originates a capability the canonical
        // docs DEMAND but no symbol provides — a red→green feature directive (Guard 4 diff_earned certifies;
        // authoring is model-bound, §9-fenced). The proxy scan can NEVER surface it (it only sees code that
        // exists). Flag OFF => returns 0 before any model build (byte-identical). Wrapped fail-open.
        $enqueued += $this->tryDocGapSupply($campaign, $provider, $repoRoot, $want);

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
            'reopened' => $reopened,
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
     * NET-NEW MATERIAL SUPPLY LANE — drain the UNTAPPED material refactor methods the single-objective lane
     * abandons. The per-target loop mints ONE refactor per file (its worst method); after the first wave of
     * files, substantive single-objective supply runs dry and the loop fills every refill with coverage —
     * yet those SAME multi-method complex files still hold many methods at/above the loop's own material bar.
     * For each complex file in the discovery scope this lane re-mints a governed refactor task through the
     * EXACT framework-refactor synthesizer + enqueue path the per-target lane uses (so the acceptance,
     * complexity-proof and cert are byte-identical to a normal refactor; it is material-by-construction —
     * the file qualifies ONLY because the decomposer found a method >= the material bar, never proxy, never
     * coverage). Returns the number of NEW tasks minted (added to the refill's enqueued count).
     *
     * CONFLICT-FREE: it mints AT MOST ONE in-flight task per file — a file with any existing pending/claimed/
     * running task is skipped — so two workers never grind the same file (worktree conflict) and same-file
     * work is serialized across cycles. The framework synthesizer re-mints fine: it certifies on ANY complex
     * method's reduction (total decisions + max-gate), so a still-complex already-touched file is valid supply.
     *
     * GATING: flag-gated default-OFF (config decompose_supply_enabled). With the flag OFF this method
     * RETURNS 0 IMMEDIATELY before touching anything, so {@see refill()} is byte-identical to today (the call
     * site only adds 0 to $enqueued). The whole body is wrapped fail-open so it can NEVER break a refill.
     */
    private function tryDecomposeMaterialSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        // BYTE-IDENTICAL GUARD: OFF => no work, no DB read, no glob — refill() is exactly today's behaviour.
        if (! (bool) config('atlas.loop.decompose_supply_enabled', false)) {
            return 0;
        }

        try {
            $decomposer = $this->complexTargetDecomposer ?? new AtlasLoopComplexTargetDecomposer;
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            $materialMin = max(1, (int) config('atlas.loop.material_refactor_min_cyclomatic', 12));
            // Bound how many files this lane mints per refill (the want/refill ceiling AND a hard cap), so a
            // huge codebase cannot flood one refill — same spirit as the coverage portfolio cap.
            $cap = max(1, min(
                max(1, $want),
                (int) config('atlas.loop.decompose_supply_max_files_per_refill', 8),
            ));

            $minted = 0;
            foreach ($this->discoveryScopeFiles($repoRoot, $campaign) as $relPath) {
                if ($minted >= $cap) {
                    break;
                }
                // PETREO: never re-mint a forbidden self-target (belt-and-suspenders; the synthesizer rejects
                // it too, but skip early so the lane spends no work on it).
                if ($guard->isForbiddenSelfTarget($relPath)) {
                    continue;
                }
                // CONFLICT GUARD: at most ONE in-flight task per file — skip a file already being ground so two
                // workers never collide on the same worktree, and same-file work is serialized across cycles.
                if ($this->fileHasInflightTask((string) $campaign->id, $relPath)) {
                    continue;
                }

                $source = $repoRoot.'/'.$relPath;
                $body = @file_get_contents($source);
                if (! is_string($body) || trim($body) === '') {
                    continue;
                }

                // The decomposer IS the untapped-supply detector: a non-empty result means >=1 method at/above
                // the material bar. Worst method first; its cyclomatic + name aim the synthesizer surgically.
                $subs = $decomposer->decompose($relPath, $body, 1);
                if ($subs === []) {
                    continue; // fail-closed: no method clears the material bar => not supply (never proxy)
                }
                $worst = $subs[0];
                $worstMethod = (string) ($worst['method'] ?? '');
                $cyclomatic = (int) ($worst['cyclomatic'] ?? 0);
                if ($cyclomatic < $materialMin) {
                    continue; // defensive: the decomposer already enforces this, but the bar is load-bearing
                }

                if ($this->mintDecomposeRefactorTask($campaign, $relPath, $worstMethod, $cyclomatic, $provider, $repoRoot)) {
                    $minted++;
                }
                $this->touchHeartbeat($campaign); // liveness: a scope scan + synth per file can take a while
            }

            return $minted;
        } catch (Throwable) {
            return 0; // fail-open: the material-supply lane can never break a refill
        }
    }

    /**
     * Enqueue ONE governed refactor task for a complex file through the SAME framework-refactor synthesizer
     * + enqueue + completeTargetEnqueue path the per-target lane uses, so the acceptance + complexity-proof
     * cert are identical to a normal refactor. The supply signals (worst method + its cyclomatic) aim the
     * synthesizer exactly as discovery's stamped signals do. A real candidate target row is upserted (so the
     * task has a live _target_id for loop-back and same-file serialization) and CLAIMED before the enqueue so
     * completeTargetEnqueue's QUEUED transition is valid. The task carries source='decompose' +
     * decompose_supply=true for provenance; everything else mirrors tryFrameworkRefactor exactly. Returns true
     * only when a live task was minted. Fail-closed: a null synth / non-live enqueue / any error => false.
     */
    private function mintDecomposeRefactorTask(AtlasLoopCampaign $campaign, string $relPath, string $worstMethod, int $cyclomatic, string $provider, string $repoRoot): bool
    {
        // Aim the synthesizer with the same signal shape discovery stamps (cyclomatic + worst_method); this is
        // ALSO the per-method complexity that makes the refactor material-by-construction.
        $signals = [
            'cyclomatic' => $cyclomatic,
            'worst_method' => $worstMethod,
            'decompose_supply' => true,
        ];

        // Upsert a real candidate target for this file (idempotent: an already-discovered candidate is just
        // refreshed, a non-candidate keeps its historical state) so the minted task has a live _target_id and
        // same-file work stays serialized through the normal target lifecycle.
        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$relPath));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $relPath,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 1.0,
                'improvement' => 1.0,
                'novelty' => 0.5,
                'signals' => $signals,
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        // The conflict guard (the in-flight-TASK check in the caller) is the real same-file serializer, so the
        // lane is the authority on a file that has untapped material methods and NO live task — REGARDLESS of
        // the target row's current status. The per-target pass routinely consumes this same row (e.g. it
        // quarantines a complex file when the per-target refactor lanes are inert, or queues it for its WORST
        // method only); the row's OTHER material methods are still untapped supply. So revive the row to drive
        // the new refactor: remember its prior status, claim it (so completeTargetEnqueue's markStatus(QUEUED)
        // is valid), and restore the prior status untouched if no task is minted (never thrash its attempts).
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'decompose_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        // Synthesize via the framework-refactor synthesizer (the right tool for ANY file with a PHPUnit
        // anchor — framework-reach AND pure-logic), falling back to the Phase-1 plain-`php` synthesizer for a
        // require-style sibling. Either way the acceptance carries complexity_proof so the cert is identical
        // to a normal refactor. Null (no real behaviour anchor / below floor) => no proxy reaches the queue.
        $synth = ($this->frameworkRefactorSynthesizer ?? new AtlasLoopFrameworkRefactorSynthesizer)
            ->synthesizeFrameworkRefactor($repoRoot, $relPath, $signals, $provider, (string) $target->id);
        if ($synth === null && $this->refactorSynthesizer !== null) {
            $synth = $this->refactorSynthesizer->synthesize($repoRoot, $relPath, $signals, $provider, (string) $target->id);
        }
        if ($synth === null) {
            // No honest anchor for this file (no sibling test / below floor / not wired) — restore the row to
            // exactly the status the lane found it in, with no attempt bump, so the lane is a pure no-op on a
            // file it cannot honestly mint for.
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();

            return false;
        }

        $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        $payload = $synth['payload'];
        $payload['decompose_supply'] = true; // provenance: this refactor came from the material-supply lane
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }
        $payload = $this->withSelfImprovementMarker($payload, $signals);
        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) $synth['objective'],
            $payload,
            'decompose',
            $relPath,
            $dp['priority'],
            true,
            (string) $synth['acceptance_hash'],
        );
        $this->stampLastObjective($target, (string) $synth['objective']);

        return $this->completeTargetEnqueue($target, $enq, 'decompose_supply_refactor_synthesized') === 'enqueued';
    }

    /**
     * L3 (territory supply-widening) — the discovery roots the next refill actually scans. The supervisor's
     * territory ladder persists a WIDER root set onto the campaign (`config['discovery_roots']`) once
     * capability is proven (canPromote: K certified leaps + compounding trend up + a frozen judge under every
     * new root — the no-blinder invariant). This makes that persisted widening DRIVE the refill, so a proven
     * leap grows the SUPPLY frontier — the other half of the Fibonacci rung-growth (the ambition dial dares the
     * biggest AVAILABLE candidate; this grows what's available). Flag default-OFF => the global roots exactly
     * as before => byte-identical. The persisted set already ⊇ the global roots (the ladder only ever widens);
     * the defensive union keeps the base scope even if the global config later changes.
     *
     * @return list<string>
     */
    private function effectiveDiscoveryRoots(AtlasLoopCampaign $campaign): array
    {
        $global = array_values((array) config('atlas.loop.campaign.discovery_roots', ['app/Services']));
        if (! (bool) config('atlas.loop.territory_widened_roots_drive_refill', false)) {
            return $global;
        }
        $config = is_array($campaign->config) ? $campaign->config : [];
        $widened = array_values(array_filter(
            (array) ($config['discovery_roots'] ?? []),
            static fn ($r): bool => is_string($r) && trim($r) !== '',
        ));
        if ($widened === []) {
            return $global;
        }

        return array_values(array_unique(array_merge($global, $widened)));
    }

    /**
     * P1-A — the request-scoped memoizing comprehension query for (repoRoot, opts). Lanes with identical
     * inputs share ONE query => the ~9s model build runs once per (scopeRoot, opts) per refill, not once per
     * lane. The signature is opts-order-stable (ksort) so `['docs_roots' => []]` always hits the same entry.
     *
     * @param  array{docs_roots?:list<string>, max_files?:int}  $opts
     */
    private function comprehensionQuery(string $repoRoot, array $opts): AtlasLoopScopeComprehensionQuery
    {
        $optsKey = $opts;
        ksort($optsKey);
        $sig = $repoRoot.'|'.json_encode($optsKey);

        return $this->comprehensionQueries[$sig] ??= new AtlasLoopScopeComprehensionQuery(
            new AtlasLoopScopeComprehensionModelBuilder,
            $repoRoot,
            $opts,
        );
    }

    /**
     * §5.6 DEDUP-SUPPLY LANE — the BRAIN driving selection. Build the grounded scope-comprehension model and
     * let {@see AtlasLoopDedupSupplyLane} mint CERTIFIABLE clone-unification tasks from its clone clusters —
     * net-new work the proxy discovery (cyclomatic/coverage) STRUCTURALLY cannot produce. Each task carries
     * dedup_proof + the frozen member siblings, so the frozen judge's Guard 4d count-drop (behaviour preserved
     * AND duplication removed) is the sole authority. Conflict-free (at most one in-flight task across a
     * cluster's members). Flag default-OFF => returns 0 before any model build => refill() is byte-identical.
     * Wrapped fail-open so it can NEVER break a refill.
     */
    private function tryDedupSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        // BYTE-IDENTICAL GUARD: OFF => no model build, no mint — refill() is exactly today's behaviour.
        if (! (bool) config('atlas.loop.dedup_supply_enabled', false)) {
            return 0;
        }

        try {
            $cap = max(1, min(max(1, $want), (int) config('atlas.loop.dedup_supply_max_per_refill', 4)));
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            $query = $this->comprehensionQuery($repoRoot, ['docs_roots' => []]);
            $lane = new AtlasLoopDedupSupplyLane;

            $minted = 0;
            foreach ($this->effectiveDiscoveryRoots($campaign) as $root) {
                if ($minted >= $cap) {
                    break;
                }
                $root = trim(str_replace('\\', '/', (string) $root), '/');
                if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                    continue;
                }
                $model = $query->model($root);
                $this->touchHeartbeat($campaign); // the model build can take a few seconds on a large scope
                foreach ($lane->mint($model, $repoRoot) as $spec) {
                    if ($minted >= $cap) {
                        break;
                    }
                    $members = array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'));
                    $anchor = $members[0] ?? '';
                    if ($anchor === '' || $guard->isForbiddenSelfTarget($anchor)) {
                        continue;
                    }
                    // CONFLICT GUARD: skip if ANY member has an in-flight task (no two workers on a shared file).
                    $conflict = false;
                    foreach ($members as $m) {
                        if ($this->fileHasInflightTask((string) $campaign->id, $m)) {
                            $conflict = true;
                            break;
                        }
                    }
                    if ($conflict) {
                        continue;
                    }
                    if ($this->mintDedupTask($campaign, $spec, $repoRoot)) {
                        $minted++;
                    }
                    $this->touchHeartbeat($campaign);
                }
            }

            return $minted;
        } catch (Throwable) {
            return 0; // fail-open: the dedup-supply lane can never break a refill
        }
    }

    /**
     * Enqueue ONE clone-unification task from a {@see AtlasLoopDedupSupplyLane} spec, anchored on the first
     * member (a real claimed target row for loop-back + same-file serialization), the SAME upsert+claim+enqueue
     * +completeTargetEnqueue path the decompose lane uses. The frozen acceptance (dedup_proof + clone_target +
     * member siblings) comes straight from the spec — the provider can never author it. Restore the row
     * untouched on a non-live enqueue (never thrash attempts). Fail-closed: any error => false.
     */
    private function mintDedupTask(AtlasLoopCampaign $campaign, array $spec, string $repoRoot): bool
    {
        $members = array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'));
        $anchor = $members[0] ?? '';
        if ($anchor === '') {
            return false;
        }

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$anchor));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $anchor,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 0.0,
                'improvement' => 1.0,
                'novelty' => 0.5,
                'signals' => ['dedup_supply' => true, 'clone_members' => $members],
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'dedup_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $payload['_target_id'] = (string) $target->id;
        $dp = $this->decidedPriority($campaign, $target, ['dedup_supply' => true], $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) ($spec['objective'] ?? ''),
            $payload,
            'dedup',
            $anchor,
            $dp['priority'],
            false,
            (string) ($spec['acceptance_hash'] ?? ''),
        );
        $this->stampLastObjective($target, (string) ($spec['objective'] ?? ''));

        $ok = $this->completeTargetEnqueue($target, $enq, 'dedup_supply_unification') === 'enqueued';
        if (! $ok) {
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();
        }

        return $ok;
    }

    /**
     * §5.6 ORPHAN-WIRING supply lane (mirrors {@see tryDedupSupply}). The comprehension model's ORPHANS become
     * wiring DIRECTIVES — net-new work the proxy scan can't surface (an unwired class has no high cyclomatic /
     * missing-coverage signal; it simply isn't called). Flag OFF => no model build, no mint (byte-identical).
     * Fail-open: it can NEVER break a refill.
     */
    private function tryOrphanWiringSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        if (! (bool) config('atlas.loop.orphan_wiring_supply_enabled', false)) {
            return 0;
        }

        try {
            $cap = max(1, min(max(1, $want), (int) config('atlas.loop.orphan_wiring_supply_max_per_refill', 2)));
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            $query = $this->comprehensionQuery($repoRoot, ['docs_roots' => []]);
            $lane = new AtlasLoopOrphanWiringSupplyLane;

            $minted = 0;
            foreach ($this->effectiveDiscoveryRoots($campaign) as $root) {
                if ($minted >= $cap) {
                    break;
                }
                $root = trim(str_replace('\\', '/', (string) $root), '/');
                if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                    continue;
                }
                $model = $query->model($root);
                $this->touchHeartbeat($campaign);
                foreach ($lane->mint($model, $repoRoot) as $spec) {
                    if ($minted >= $cap) {
                        break;
                    }
                    $anchor = (string) (array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'))[0] ?? '');
                    if ($anchor === '' || $guard->isForbiddenSelfTarget($anchor)) {
                        continue;
                    }
                    // CONFLICT GUARD: no two workers on the orphan file at once.
                    if ($this->fileHasInflightTask((string) $campaign->id, $anchor)) {
                        continue;
                    }
                    if ($this->mintOrphanWiringTask($campaign, $spec, $repoRoot)) {
                        $minted++;
                    }
                    $this->touchHeartbeat($campaign);
                }
            }

            return $minted;
        } catch (Throwable) {
            return 0; // fail-open: the orphan-wiring lane can never break a refill
        }
    }

    /**
     * Enqueue ONE orphan-wiring DIRECTIVE (source='orphan_wiring') anchored on the orphan file. Unlike the dedup
     * task, the acceptance is NOT pre-baked — the grinder's orphan-wiring route + the engine author the earned-RED
     * test and the wiring; Guard 4e is the sole authority on whether the wiring is real. Restore the row on a
     * non-live enqueue. Fail-closed: any error => false.
     */
    private function mintOrphanWiringTask(AtlasLoopCampaign $campaign, array $spec, string $repoRoot): bool
    {
        $anchor = (string) (array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'))[0] ?? '');
        if ($anchor === '') {
            return false;
        }

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$anchor));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $anchor,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 0.0,
                'improvement' => 1.0,
                'novelty' => 0.5,
                'signals' => ['orphan_wiring_supply' => true, 'orphan_path' => $anchor],
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'orphan_wiring_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $payload['_target_id'] = (string) $target->id;
        $dp = $this->decidedPriority($campaign, $target, ['orphan_wiring_supply' => true], $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) ($spec['objective'] ?? ''),
            $payload,
            'orphan_wiring',
            $anchor,
            $dp['priority'],
            false,
            '',
        );
        $this->stampLastObjective($target, (string) ($spec['objective'] ?? ''));

        $ok = $this->completeTargetEnqueue($target, $enq, 'orphan_wiring_supply_directive') === 'enqueued';
        if (! $ok) {
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();
        }

        return $ok;
    }

    /**
     * §2 DOC-GAP supply lane (mirrors {@see tryOrphanWiringSupply}). The comprehension model's doc-stated
     * gaps (a capability the canonical docs NAME but no symbol provides) become red→green feature directives.
     * Built WITH docs_roots so gaps are detected; flag OFF => no model build (byte-identical). Fail-open.
     */
    private function tryDocGapSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        if (! (bool) config('atlas.loop.doc_gap_supply_enabled', false)) {
            return 0;
        }

        try {
            $cap = max(1, min(max(1, $want), (int) config('atlas.loop.doc_gap_supply_max_per_refill', 1)));
            $lane = new AtlasLoopDocGapSupplyLane;
            $docsRoots = array_values(array_filter((array) config('atlas.loop.doc_gap_supply_docs_roots', []), 'is_string'));
            $query = $this->comprehensionQuery($repoRoot, ['docs_roots' => $docsRoots]);

            $minted = 0;
            foreach ($this->effectiveDiscoveryRoots($campaign) as $root) {
                if ($minted >= $cap) {
                    break;
                }
                $root = trim(str_replace('\\', '/', (string) $root), '/');
                if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                    continue;
                }
                $model = $query->model($root);
                $this->touchHeartbeat($campaign);
                foreach ($lane->mint($model, $repoRoot) as $spec) {
                    if ($minted >= $cap) {
                        break;
                    }
                    if ($this->mintDocGapTask($campaign, $spec, $repoRoot)) {
                        $minted++;
                    }
                    $this->touchHeartbeat($campaign);
                }
            }

            return $minted;
        } catch (Throwable) {
            return 0; // fail-open: the doc-gap lane can never break a refill
        }
    }

    /**
     * Enqueue ONE doc-gap DIRECTIVE (source='doc_gap'), anchored on the EXPECTED path of the capability to be
     * created (scope-consistent + deterministic). The acceptance is engine-authored (red→green; Guard 4
     * diff_earned is the deterministic gate). A pétreo expected-path is refused; a non-live enqueue restores.
     *
     * @param  array<string,mixed>  $spec
     */
    private function mintDocGapTask(AtlasLoopCampaign $campaign, array $spec, string $repoRoot): bool
    {
        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $capability = trim((string) ($payload['capability'] ?? ''));
        if ($capability === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $capability)) {
            return false; // only a clean class-name maps to a deterministic expected path
        }
        $anchor = 'app/Services/Ai/AutonomousEvolution/'.$capability.'.php';

        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
        if ($guard->isForbiddenSelfTarget($anchor) || $this->fileHasInflightTask((string) $campaign->id, $anchor)) {
            return false;
        }

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$anchor));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $anchor,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 0.0,
                'improvement' => 1.0,
                'novelty' => 0.7,
                'signals' => ['doc_gap_supply' => true, 'capability' => $capability],
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'doc_gap_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $payload['_target_id'] = (string) $target->id;
        $dp = $this->decidedPriority($campaign, $target, ['doc_gap_supply' => true], $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask((string) $campaign->id, (string) ($spec['objective'] ?? ''), $payload, 'doc_gap', $anchor, $dp['priority'], false, '');
        $this->stampLastObjective($target, (string) ($spec['objective'] ?? ''));

        $ok = $this->completeTargetEnqueue($target, $enq, 'doc_gap_supply_directive') === 'enqueued';
        if (! $ok) {
            $target->forceFill(['status' => $priorStatus, 'claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null])->save();
        }

        return $ok;
    }

    /**
     * The set of .php files under the campaign's discovery scope (the SAME roots discovery scans —
     * config atlas.loop.campaign.discovery_roots under the campaign's base workspace), so the material-supply
     * lane mines exactly the territory discovery covers. Deterministic order (roots in declared order, files
     * sorted) so a re-run is stable. Returns repo-relative paths.
     *
     * @return list<string>
     */
    private function discoveryScopeFiles(string $repoRoot, AtlasLoopCampaign $campaign): array
    {
        $roots = $this->effectiveDiscoveryRoots($campaign);
        $out = [];
        $seen = [];
        foreach ($roots as $root) {
            $root = trim((string) $root, "/ \t\n\r\0\x0B");
            if ($root === '') {
                continue;
            }
            $absRoot = $repoRoot.'/'.$root;
            if (! is_dir($absRoot)) {
                continue;
            }
            $found = [];
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $abs = $file->getPathname();
                if (! str_starts_with($abs, $repoRoot.'/')) {
                    continue;
                }
                $found[] = ltrim(substr($abs, strlen($repoRoot) + 1), '/');
            }
            sort($found); // stable, deterministic per-root order
            foreach ($found as $rel) {
                if ($rel !== '' && ! isset($seen[$rel])) {
                    $seen[$rel] = true;
                    $out[] = $rel;
                }
            }
        }

        return $out;
    }

    /**
     * True when the file already has an in-flight (pending/claimed/running) task in this campaign — the
     * conflict guard that serializes same-file work so the lane mints at most ONE concurrent task per file.
     */
    private function fileHasInflightTask(string $campaignId, string $relPath): bool
    {
        return AtlasLoopTask::query()
            ->where('campaign_id', $campaignId)
            ->where('target_path', $relPath)
            ->whereIn('status', [
                AtlasLoopTask::STATUS_PENDING,
                AtlasLoopTask::STATUS_CLAIMED,
                AtlasLoopTask::STATUS_RUNNING,
            ])
            ->exists();
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

    private function completeTargetEnqueue(AtlasLoopTarget $target, ?AtlasLoopTask $task, string $queuedReason): string
    {
        // D2 — MATERIAL-SUPPLY GATE. A per-target REFACTOR task that carries no material proof is a
        // behaviour-preserving PROXY refactor (the same rule the honest scorecard uses). The driver only
        // governs the rédea; here the per-target lane refuses proxy too: the synthesized task is DROPPED so
        // it never enters the queue or gets ground, and the target is deferred. Coverage/bug/feature pass.
        // Fail-open: gate OFF, or a delete hiccup, falls through to the normal enqueue (byte-identical).
        if ($task instanceof AtlasLoopTask
            && (bool) config('atlas.loop.material_supply_gate_enabled', true)
            && $this->isProxyRefactorTask($task)) {
            try {
                $task->delete();
                $this->loopBack->reflect($target->campaign_id, [
                    'target_id' => $target->id,
                    'status' => 'no_winner',
                    'reason' => 'material_supply_gate:proxy_refactor_dropped',
                ]);
                $this->releaseDuplicateOrMissingTaskTarget($target, 'material_supply_gate_proxy_refactor');

                return 'deferred';
            } catch (Throwable) {
                // fail-open: never let the gate break a refill — fall through to the normal enqueue.
            }
        }

        if ($this->taskIsLiveForTarget($task, $target)) {
            $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, $queuedReason);
            // D2 — count a NON-coverage enqueue as substantive (coverage has its own counter), so the
            // relative coverage cap can keep coverage at or below the substantive work of this refill.
            if ($task instanceof AtlasLoopTask && ! $this->taskIsCoverage($task)) {
                $this->substantiveMintedThisRefill++;
            }

            return 'enqueued';
        }

        $this->releaseDuplicateOrMissingTaskTarget(
            $target,
            $task instanceof AtlasLoopTask ? 'duplicate_task_not_live_for_target' : 'task_enqueue_missing',
        );

        return 'deferred';
    }

    private function taskIsLiveForTarget(?AtlasLoopTask $task, AtlasLoopTarget $target): bool
    {
        if (! $task instanceof AtlasLoopTask) {
            return false;
        }
        if (! in_array((string) $task->status, [
            AtlasLoopTask::STATUS_PENDING,
            AtlasLoopTask::STATUS_CLAIMED,
            AtlasLoopTask::STATUS_RUNNING,
        ], true)) {
            return false;
        }
        $payload = is_array($task->payload) ? $task->payload : [];

        return (string) ($payload['_target_id'] ?? '') === (string) $target->id;
    }

    private function releaseDuplicateOrMissingTaskTarget(AtlasLoopTarget $target, string $reason): void
    {
        $attempts = (int) $target->attempts + 1;
        $maxAttempts = max(1, (int) $target->max_attempts);
        $status = $attempts >= $maxAttempts ? AtlasLoopTarget::STATUS_EXHAUSTED : AtlasLoopTarget::STATUS_CANDIDATE;

        $target->forceFill([
            'status' => $status,
            'attempts' => min($attempts, $maxAttempts),
            'reason' => $status === AtlasLoopTarget::STATUS_EXHAUSTED ? 'attempt_cap_'.$reason : $reason,
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'novelty_score' => max(0.0, (float) $target->novelty_score - 0.34),
            'score' => max(0.0, (float) $target->score - 0.1),
        ])->save();
    }

    /**
     * D2 — a refactor task that carries NO material proof is a behaviour-preserving PROXY refactor. Mirrors
     * the honest scorecard rule exactly: material iff revert_recheck OR red_required OR complexity_proof OR
     * the governed self-improvement triple (self-marked + complexity_proof + quality_bar). Non-refactor
     * kinds (coverage / bug / feature) are never proxy here — only the behaviour-preserving refactor is.
     */
    private function isProxyRefactorTask(AtlasLoopTask $task): bool
    {
        $p = is_array($task->payload) ? $task->payload : [];
        $kind = mb_strtolower(trim((string) ($p['objective_kind'] ?? '')));
        if (! str_starts_with($kind, 'refactor')) {
            return false;
        }

        $isTrue = static fn ($v): bool => $v === true || $v === 1
            || (is_string($v) && in_array(mb_strtolower(trim($v)), ['1', 'true', 'yes'], true));

        // EXACTLY the honest scorecard's real-work rule for a refactor: behaviour proof (revert_recheck /
        // red_required) OR the GOVERNED self-improvement triple (self-marked + complexity_proof + quality_bar).
        // A standalone complexity_proof is deliberately NOT material — the scorecard classifies such a
        // refactor as proxy, so the supply gate must drop it too (otherwise gate and ruler disagree and a
        // proxy still reaches the queue, which the live AFTER run exposed).
        $material = $isTrue($p['revert_recheck'] ?? null)
            || $isTrue(data_get($p, 'acceptance.revert_recheck'))
            || $isTrue(data_get($p, 'acceptance.red_required'))
            // §5.6 DEDUP — a clone-unification is behaviour-preserving (revert_recheck=false) but MATERIAL: its
            // value is duplication-removed, proven by the frozen judge's Guard 4d count-drop. The dedup_proof
            // pair (payload + frozen acceptance, which the provider cannot author) is the honest material mark.
            || ($isTrue($p['dedup_proof'] ?? null) && $isTrue(data_get($p, 'acceptance.dedup_proof')))
            || (
                $isTrue($p['is_self_improvement'] ?? null)
                && ($isTrue($p['complexity_proof'] ?? null) || $isTrue(data_get($p, 'acceptance.complexity_proof')))
                && ($isTrue($p['quality_bar_gate'] ?? null) || $isTrue(data_get($p, 'acceptance.quality_bar_gate')))
            );

        return ! $material;
    }

    /** A repo-relative path to a TEST file — not a valid MATERIAL (behaviour-changing production) target. */
    private static function isTestPath(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        return str_starts_with($rel, 'tests/')
            || str_contains($rel, '/tests/')
            || str_ends_with($rel, 'Test.php');
    }

    /** D2 — is this a characterization/coverage task (counted against the coverage cap, not substantive)? */
    private function taskIsCoverage(AtlasLoopTask $task): bool
    {
        $p = is_array($task->payload) ? $task->payload : [];
        $kind = mb_strtolower(trim((string) ($p['objective_kind'] ?? '')));

        return $kind === AtlasLoopCoverageDeficitSource::SHAPE || str_contains($kind, 'characterization');
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

                    return $this->completeTargetEnqueue($target, $enq, 'multi_file_refactor_task_synthesized');
                }
            }
        }

        // §11.4 BUG-FIX REPRODUCTION LANE: when this target's discovery signals carry a runnable
        // FAILURE handle (a reproducing test path or an explicit failing command), shape a
        // reproduce-then-fix RED-required objective and enqueue it as a FIRST-CLASS bug_fix —
        // BEFORE the refactor/framework cascade (a known break beats speculative complexity work).
        // The lane is fail-closed (it returns null unless target_path + a runnable handle exist), so
        // an ordinary target with no failure handle falls through byte-identical. Flag-gated default-ON.
        // NOTE: failure_test_path/failure_command ARE stamped into discovery signals by
        // AtlasLoopTargetDiscoveryService (lines ~106-138, behind discovery_failure_handle_stamp_enabled).
        // This lane is therefore fully wired end-to-end. It stays inert ONLY because the failure-handle
        // CORPUS is empty: the harvester keeps real_failure reds, and a self-evolving loop whose scope is
        // its OWN code keeps that scope GREEN by construction — a green suite harvests zero handles. So the
        // lane is DRY-by-construction, not unwired. Do NOT "fix" it by manufacturing synthetic/flaky reds
        // (that launders fake work as REAL_KIND_BUG_FIX — worse than cyclomatic faxina); feed it only from
        // an organically-red real scope with a provenance gate.
        $bugOutcome = $this->tryBugReproduction($campaign, $target, $signals, $provider, $repoRoot);
        if ($bugOutcome !== null) {
            return $bugOutcome;
        }

        // C1/L6-1 LIVE SUPPLY: meta-harness/backlog self-improvement candidates must not fall through to
        // the generic provider fallback (which is deliberately OFF in real-work soaks). Ground them through
        // the measured self-improvement bridge into an executable extract-class contract: sibling test frozen,
        // complexity proof required, quality bar stamped, and self-edit marker preserved for park gates.
        $selfOutcome = $this->tryGroundedSelfImprovement($campaign, $target, $signals, $provider, $repoRoot);
        if ($selfOutcome !== null) {
            return $selfOutcome;
        }

        $coverageOutcome = $this->tryCoverageDeficitCharacterization($campaign, $target, $signals, $provider, $source);
        if ($coverageOutcome !== null) {
            return $coverageOutcome;
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

                return $this->completeTargetEnqueue($target, $enq, 'framework_task_enqueued');
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

                    return $this->completeTargetEnqueue($target, $enq, 'refactor_task_synthesized');
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
            // §5.5 — research-as-assist: when armed, derive an egress-safe research topic from the target's
            // work-shape so the generator's withExternalResearchContext slot guides the authoring of a STRONGER
            // real improvement. Default-OFF => options() returns [] => $genOptions is byte-identical. The
            // obligation stays a genuinely RED-verified test on the real target (research only informs, never gates).
            $genOptions = array_merge(
                $genOptions,
                ($this->researchTopicDeriver ?? new AtlasLoopResearchTopicDeriver)->options($signals, $repoRoot),
            );
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
            $cleanup();

            return $this->completeTargetEnqueue($target, $enq, 'task_generated');
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
        $backoff = $this->extractClassTimeoutBackoff((string) $campaign->id);
        $extractClass = ! $backoff['active']
            && ($forceExtractClass || ((bool) config('atlas.loop.multi_file_refactor_via_normal_lane', false)
                && (int) ($signals['cyclomatic'] ?? 0) >= max(1, (int) config('atlas.loop.extract_class_min_cyclomatic', 15))));
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
        if ($backoff['active']) {
            $frPayload['_extract_class_backoff'] = $backoff;
        }
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

        return $this->completeTargetEnqueue($target, $enq, 'framework_refactor_task_synthesized');
    }

    /**
     * Back off from two-file extract-class when the live campaign has already proven that shape is
     * burning the time budget. The fallback still emits a governed refactor task for the same target,
     * only smaller. Fail-open: any read issue leaves the lane available.
     *
     * @return array{active:bool, timeouts:int, successes:int, window_hours:int}
     */
    private function extractClassTimeoutBackoff(string $campaignId): array
    {
        if (! (bool) config('atlas.loop.extract_class_timeout_backoff_enabled', true)) {
            return ['active' => false, 'timeouts' => 0, 'successes' => 0, 'window_hours' => 0];
        }
        if (isset($this->extractClassBackoffCache[$campaignId])) {
            return $this->extractClassBackoffCache[$campaignId];
        }

        $windowHours = max(1, (int) config('atlas.loop.extract_class_timeout_backoff_window_hours', 6));
        $minTimeouts = max(1, (int) config('atlas.loop.extract_class_timeout_backoff_min_timeouts', 3));
        $backoff = ['active' => false, 'timeouts' => 0, 'successes' => 0, 'window_hours' => $windowHours];

        $since = now()->subHours($windowHours)->toDateTimeString();
        try {
            $rows = DB::table('atlas_loop_tasks')
                ->where('campaign_id', $campaignId)
                ->where('updated_at', '>=', $since)
                ->get(['status', 'payload', 'result']);
        } catch (Throwable) {
            return $this->extractClassBackoffCache[$campaignId] = $backoff;
        }

        foreach ($rows as $row) {
            $payload = $this->jsonObject($row->payload ?? null);
            if (($payload['objective_kind'] ?? null) !== self::EXTRACT_CLASS_OBJECTIVE_KIND) {
                continue;
            }

            if ((string) ($row->status ?? '') === 'done') {
                $backoff['successes']++;
                continue;
            }

            $result = $this->jsonObject($row->result ?? null);
            if (($result['reason'] ?? null) === 'parallel_worker_timeout') {
                $backoff['timeouts']++;
            }
        }

        $backoff['active'] = $backoff['timeouts'] >= $minTimeouts
            && $backoff['timeouts'] > $backoff['successes'];

        return $this->extractClassBackoffCache[$campaignId] = $backoff;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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

        // C/D2 — PORTFOLIO CAP (CAMPAIGN-cumulative + per-refill inner bound). Characterization is
        // verification, not evolution, and must never dominate a campaign whose objective is loop evolution.
        // A per-refill cap is not enough — coverage ACCUMULATES across refills while substantive may stay
        // sparse. So the cap is measured over the WHOLE campaign: substantive == every non-coverage task
        // (the material gate already dropped proxy refactors), and coverage is DEFERRED once it reaches
        // floor(substantive/2) (floor 1 so a cold-start campaign still gets one verification). At
        // substantive ≥ 2 this keeps coverage STRICTLY below substantive by construction. The per-refill
        // counter is the cheap inner ceiling. Fail-open: portfolio gate OFF ⇒ no cap (byte-identical legacy).
        if ((bool) config('atlas.loop.coverage_portfolio_gate_enabled', true)) {
            $perRefillCap = (int) config('atlas.loop.coverage_characterization_max_per_refill', 2);
            if ((bool) config('atlas.loop.coverage_relative_to_substantive', true)) {
                // AVAILABILITY-based, not count-based. Coverage must never crowd out a refactor/bug/feature
                // target — so DEFER coverage WHILE a genuine substantive (non-coverage-shaped) target is still
                // open to do instead. But when substantive targets are EXHAUSTED, a characterization test is
                // NOT padding: it PINS an untested file's behaviour, the mandatory test-then-refactor STEP 1
                // (next cycle the now-tested file becomes a material refactor target). The old count-based cap
                // blocked step 1 and STARVED the loop into idling on its own untested files — the loop must
                // keep evolving (test → refactor across the whole codebase), never idle. Fail-OPEN (allow
                // coverage) on any query hiccup: doing real verification work beats idling.
                // Only a CONCRETELY-shaped substantive target blocks coverage — shape PRESENT and not the
                // coverage shape. A null/unclassified shape must NOT count: those route through the rédea/driver
                // which routinely DEFERS them (no leap / proxy), so they never mint — and if allowed to block
                // coverage, an unmintable null-shape backlog would DEADLOCK every coverage target forever (the
                // exact 26-coverage-starved-by-11-null-shape idle observed live). Excluding null means: when
                // only coverage + unmintable-null targets remain, the loop DOES the coverage (test-then-refactor
                // step 1) instead of idling on its own untested files.
                try {
                    $substantiveTargetAvailable = AtlasLoopTarget::query()
                        ->where('campaign_id', $campaign->id)
                        ->whereIn('status', [AtlasLoopTarget::STATUS_CANDIDATE, AtlasLoopTarget::STATUS_QUEUED])
                        ->where('target_path', '!=', $target->target_path)
                        ->whereNotNull('signals->shape')
                        ->where('signals->shape', '!=', AtlasLoopCoverageDeficitSource::SHAPE)
                        ->exists();
                } catch (Throwable) {
                    $substantiveTargetAvailable = false; // fail-open: allow coverage rather than idle
                }
                if ($substantiveTargetAvailable) {
                    $this->loopBack->reflect($campaign->id, [
                        'target_id' => $target->id,
                        'status' => 'no_winner',
                        'reason' => 'coverage_deferred_substantive_target_available',
                    ]);

                    return 'deferred';
                }
            }
            if ($this->coverageMintedThisRefill >= $perRefillCap) {
                $this->loopBack->reflect($campaign->id, [
                    'target_id' => $target->id,
                    'status' => 'no_winner',
                    'reason' => 'coverage_portfolio_cap_reached',
                ]);

                return 'deferred';
            }
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
            'target_content' => (string) @file_get_contents($source),
            'decision_operator' => $operator,
            'mutation_id' => 'coverage_deficit:'.$operator,
            'sibling_test' => $sibling !== '' ? $sibling : null,
            '_target_id' => (string) $target->id,
        ], $provider !== '' ? $provider : null, true);

        if ($taskId === null) {
            $this->repository->quarantine($target->id, 'coverage_deficit_unfeedable');

            return 'quarantined';
        }

        $objective = is_string($signals['coverage_objective'] ?? null) && trim((string) $signals['coverage_objective']) !== ''
            ? (string) $signals['coverage_objective']
            : 'Create a characterization test for '.ltrim((string) $target->target_path, '/');
        $this->stampLastObjective($target, $objective);

        $task = AtlasLoopTask::query()->find($taskId);

        $outcome = $this->completeTargetEnqueue($target, $task, 'coverage_deficit_characterization_task_enqueued');
        if ($outcome === 'enqueued') {
            $this->coverageMintedThisRefill++; // C — count it against this refill's coverage portfolio cap
        }

        return $outcome;
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
     * Ground a meta/backlog self-improvement target into the hard self-edit contract instead of letting it
     * die at `generic_provider_fallback_disabled`. This is intentionally narrower than the ordinary refactor
     * lanes: it fires only for explicit self-improvement signals and only when the grounding bridge can name a
     * concrete worst method + sibling test under the HarnessGuard gates.
     *
     * @param  array<string,mixed>  $signals
     */
    private function tryGroundedSelfImprovement(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $provider, string $repoRoot): ?string
    {
        if (! $this->isSelfImprovementCandidate($signals)) {
            return null;
        }
        if (! (bool) config('atlas.loop.self_improve_grounding_enabled', false)) {
            return null;
        }

        $rel = ltrim((string) $target->target_path, '/');
        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
        if ($guard->isForbiddenSelfTarget($rel) || ! $guard->isHarnessTarget($rel)) {
            return null;
        }

        $grounded = (new AtlasLoopSelfImprovementGroundingBridge)
            ->ground($rel, $repoRoot, $provider !== '' ? $provider : null);
        if (! is_array($grounded) || ($grounded['admitted'] ?? false) !== true) {
            return null;
        }
        if (! isset($grounded['objective'], $grounded['payload'], $grounded['acceptance_hash']) || ! is_array($grounded['payload'])) {
            return null;
        }

        $markerSignals = $signals;
        $markerSignals['is_self_improvement'] = true;
        if (isset($grounded['quality_bar'])) {
            $markerSignals['quality_bar'] = $grounded['quality_bar'];
        }

        $dp = $this->decidedPriority($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS);
        $payload = $this->withSelfImprovementMarker((array) $grounded['payload'], $markerSignals);
        $payload['_target_id'] = $target->id;
        $payload['self_improvement_source'] = (string) ($signals['backlog_source'] ?? 'self_improvement');
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask(
            $campaign->id,
            (string) $grounded['objective'],
            $payload,
            'self_improvement',
            (string) $target->target_path,
            $dp['priority'],
            true,
            (string) $grounded['acceptance_hash'],
        );
        $this->stampLastObjective($target, (string) $grounded['objective']);

        return $this->completeTargetEnqueue($target, $enq, 'self_improvement_task_synthesized');
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function isSelfImprovementCandidate(array $signals): bool
    {
        if (($signals['is_self_improvement'] ?? false) === true) {
            return true;
        }

        $source = trim((string) ($signals['backlog_source'] ?? ''));

        return in_array($source, ['self_improve', 'meta_harness_self_improve'], true);
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

        return $this->completeTargetEnqueue($target, $enq, 'bug_reproduction_task_synthesized');
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

        return $this->completeTargetEnqueue($target, $enq, 'multi_file_refactor_task_synthesized');
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
