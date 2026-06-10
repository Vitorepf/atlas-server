<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Throwable;

/**
 * S3.F1 — THE RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP ("Atlas improves Atlas").
 *
 * S3.F4 — GOVERNANCE HARDENING (safe to leave running): on top of F1-F3 this adds the
 * floors that make an autonomous self-modifying loop safe to leave running:
 *   - ADVERSARIAL RE-CHECK ({@see AtlasSelfImprovementAdversarialRecheck}): a gate-passed
 *     branch is INDEPENDENTLY re-verified (default-refute) before being surfaced as
 *     worthy. A branch that passes generation+gate but fails the re-check is HELD as
 *     needs_review (kept for the operator, never claimed as a vetted improvement).
 *   - PER-RUN BRANCH CAP: a single run can deliver at most a bounded number of branches
 *     (max_branches_per_run) — beyond it, further signals are skipped (capped), so an
 *     unattended run can never fan out unbounded self-modifying work.
 *   - KILL-SWITCH honored MID-RUN: the stop file is checked BEFORE each signal, so a run
 *     stops cleanly the moment the operator trips it (not only between --watch cycles).
 *   - EVIDENCE / RECEIPT ({@see AtlasSelfImprovementReceiptLog}): every decision — pass,
 *     reject, needs_review, blocked — writes an honest auditable receipt. No silent
 *     action.
 *
 * This is the SYNTHESIS of the session's work, NOT a rebuild. It routes each Atlas
 * improvement signal through the BRAIN-ANCHORED mission loop (Salto 1+2) and adds the
 * one safety the bare pipe lacked:
 *
 *   detect  (AtlasSelfConstructionDetector: TODO/FIXME + operator gaps, file-anchored)
 *     → BRAIN-ANCHORED mission  ({@see AtlasMissionService::run}: AURG provider-bound
 *        context for THAT signal threads into the prompt → governed delivery → branch →
 *        outcome recorded back INTO the brain, so the NEXT cycle SEES it = recursive
 *        compounding) — replaces the bare MissionDeliveryOrchestrator the loop used
 *        before, which had no aim and produced 412 lines of off-target garbage;
 *     → RELEVANCE GATE  ({@see AtlasSelfImprovementRelevanceGate}: OUT-OF-PROCESS,
 *        not gameable by the generation prompt — it checks the FACTS of what was
 *        produced against the FACTS of the signal). On REJECT the branch is DISCARDED
 *        (no garbage presented as worthy) and the rejection is logged HONESTLY;
 *     → on PASS: a branch the OPERATOR reviews + merges
 *     → an HONEST cycle meta-metric (on-target vs off-target-rejected — no
 *        self-declared success; the gate's verdict, not the loop's optimism).
 *
 * WHY THIS FIXES THE 412-LINE-GARBAGE ROOT CAUSE (two independent mechanisms):
 *   1. AIM — the mission gets the AURG context for the actual signal, and the request
 *      NAMES the signal's file:line + concern + passes target_file, so the generation
 *      targets the right PLACE instead of inventing a generic feature.
 *   2. CHECK — even if the provider still drifts, the out-of-process relevance gate
 *      rejects a generation that did not touch the file the signal named. The provider
 *      cannot talk past a deterministic comparison of fact sets it does not control.
 *
 * GOVERNANCE (non-negotiable, inherited + re-stated):
 *   - NEVER-MERGE: every PASS is a branch the OPERATOR merges; the loop never merges,
 *     never pushes, never touches main (the materializer enforces; kept).
 *   - DEFAULT-OFF AUTONOMY: the --watch autonomous mode (the command) stays flag-gated
 *     default-OFF + kill-switch + per-cycle bounds. This service is BOUNDED (max).
 *   - HONEST MEASUREMENT: no self-declared success — the relevance verdict + the
 *     mission's own brain flags ride the result; rejected work is reported, not hidden.
 *   - COST-FREE TESTS: a fake mission service / fake delivery stands in (zero tokens),
 *     exactly as the mission + orchestrator tests do. The real spend is the operator's
 *     command.
 */
final class AtlasSelfConstructionLoopService
{
    public const SCHEMA = 'atlas.ai.self_construction_loop.v2';

    public function __construct(
        private readonly AtlasSelfConstructionDetector $detector,
        // Salto 2: the brain-anchored mission surface (was the bare orchestrator).
        private readonly AtlasMissionService $mission,
        // S3.F1: the out-of-process relevance gate (the load-bearing safety).
        private readonly AtlasSelfImprovementRelevanceGate $relevanceGate,
        // The governed materializer — used ONLY to discard an off-target branch the
        // mission already cut (the gate runs after delivery). Never merges/pushes.
        private readonly GovernedBranchMaterializationService $materializer,
        // S3.F3: the HONEST meta-metric. Nullable so the legacy 4-arg construction (and
        // the F1/F2 tests) keep working with no history wiring — when absent the cycle
        // simply isn't persisted (the run result is byte-identical). When present, ONE
        // durable history row is written AFTER each run, measuring the brain-node delta
        // around the cycle (the recursion substrate, quantified). FAIL-OPEN.
        private readonly ?AtlasSelfImprovementMetaMetricService $metaMetric = null,
        // S3.F4: the ADVERSARIAL RE-CHECK (default-refute independent re-verification) and
        // the EVIDENCE / RECEIPT LOG. BOTH nullable so the F1/F2/F3 constructions keep
        // working byte-identically: when the recheck is absent a gate PASS is surfaced
        // directly (the F1-F3 behaviour); when the receipt log is absent no audit line is
        // written. Wired in the container so the real command always gets both.
        private readonly ?AtlasSelfImprovementAdversarialRecheck $adversarialRecheck = null,
        private readonly ?AtlasSelfImprovementReceiptLog $receiptLog = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $root = (string) ($options['repo_dir'] ?? base_path());
        $cap = (int) config('atlas.self_construction.max_signals', 5);
        $max = max(1, min($cap, (int) ($options['max'] ?? 1)));
        $extra = array_values(array_filter((array) ($options['requests'] ?? []), 'is_string'));
        $deliveryOptions = (array) ($options['delivery'] ?? []);

        // Config: brain-anchoring is the WHOLE POINT (default true). A per-run override
        // can flip it off for a debug/A-B run without mutating config.
        $useBrain = (bool) ($options['use_brain_context']
            ?? config('atlas.self_construction.use_brain_context', true));

        // S3.F4 — BOUNDS + KILL. The per-run branch cap halts further delivery once this
        // run has kept its allotted number of branches (an unattended run can never fan
        // out unbounded self-modifying work). The kill-switch file is honored BEFORE each
        // signal so a run stops cleanly the instant the operator trips it. Both are
        // overridable per-run for tests; defaults come from config.
        $branchCap = $this->branchCap($options);
        $killFile = $this->killFile($options);

        // S3.F3 — measure the brain BEFORE the cycle so the per-cycle brain-node delta
        // (the recursion substrate: refs the NEXT cycle can reach) is real, not derived.
        $brainBefore = $this->metaMetric?->brainNodeCount() ?? 0;

        $signals = $this->detector->detect($root, $max, $extra);

        $outcomes = [];
        $branchesKept = 0;
        $killed = false;
        $capped = false;
        foreach ($signals as $signal) {
            // KILL-SWITCH: honored mid-run, before each signal — the operator can stop an
            // in-flight run cleanly (not only between --watch cycles). Already-processed
            // signals' outcomes (and their receipts) are preserved; the rest are skipped.
            if ($killFile !== null && @is_file($killFile)) {
                $killed = true;
                break;
            }

            // PER-RUN BRANCH CAP: once this run has kept its allotted branches, halt
            // further DELIVERY. We stop before processing more signals rather than deliver
            // then discard — the cheapest safe behaviour (no extra spend, no extra branch).
            if ($branchesKept >= $branchCap) {
                $capped = true;
                break;
            }

            $outcome = $this->processSignal($signal, $root, $deliveryOptions, $useBrain);
            $outcomes[] = $outcome;

            // Count only branches actually KEPT (accepted + held-for-review) toward the
            // cap — a rejected+discarded branch consumed no lasting artifact, so it does
            // not burn the run's branch budget.
            if (is_string($outcome['branch'] ?? null) || is_string($outcome['held_branch'] ?? null)) {
                $branchesKept++;
            }
        }

        $summary = $this->summary($signals, $outcomes, $useBrain);
        $summary['branch_cap'] = $branchCap;
        $summary['branch_cap_reached'] = $capped;
        $summary['killed_mid_run'] = $killed;

        // S3.F3 — persist ONE durable history row of this cycle's MEASURED counts +
        // the brain-node delta. FAIL-OPEN: a history outage never changes the run
        // result (the meta is observation, not control). Skipped when unwired.
        if ($this->metaMetric instanceof AtlasSelfImprovementMetaMetricService) {
            $brainAfter = $this->metaMetric->brainNodeCount();
            $record = $this->metaMetric->record($summary, $brainBefore, $brainAfter);
            $summary['cycle_recorded'] = (bool) ($record['recorded'] ?? false);
            $summary['brain_nodes_added'] = max(0, $brainAfter - $brainBefore);
        }

        return $summary;
    }

    /**
     * Route ONE signal through the brain-anchored mission, then the relevance gate.
     *
     * @param  array<string,mixed>  $signal
     * @param  array<string,mixed>  $deliveryOptions
     * @return array<string,mixed>
     */
    private function processSignal(array $signal, string $root, array $deliveryOptions, bool $useBrain): array
    {
        // Enrich the request so the generation TARGETS the signal's real place
        // (file:line + concern) instead of a generic snippet — half the 412-fix.
        $request = $this->enrichedRequest($signal);
        $missionId = 'selfconstruct-'.substr(hash('sha256', $request), 0, 10);

        $opts = array_merge($deliveryOptions, [
            'id' => $missionId,
            'repo_dir' => $root,
            // --no-brain is the mission's per-run bypass of the brain-context bridge.
            // We INVERT use_brain into it so the brain-anchoring default (true) means
            // "pass brain context"; the outcome write-back is NOT silenced either way
            // (the loop always compounds — see AtlasMissionService::run docblock).
            'no_brain' => ! $useBrain,
        ]);

        // Pass target_file so the delivery step aims the provider at the named file
        // (the other half of the 412-fix). Operator gaps name no file → unset.
        $targetFile = is_string($signal['file'] ?? null) && ($signal['file'] ?? '') !== ''
            ? (string) $signal['file']
            : null;
        if ($targetFile !== null) {
            $opts['target_file'] = $targetFile;
        }

        // BRAIN-ANCHORED governed delivery (brain context in → branch → outcome into
        // the brain, so the next cycle sees this one: recursive compounding).
        $mission = $this->mission->run($request, $opts);

        // OUT-OF-PROCESS relevance gate — the load-bearing safety. Default-refute if
        // the generation did not hit the file the signal named.
        $verdict = $this->relevanceGate->evaluate($signal, $mission);
        $relevant = (bool) ($verdict['relevant'] ?? false);

        $delivered = (bool) ($mission['delivered'] ?? false);
        $branch = is_string($mission['branch'] ?? null) ? $mission['branch'] : null;

        // S3.F4 — ADVERSARIAL RE-CHECK. A gate PASS is not yet trusted: an INDEPENDENT,
        // default-refute re-check (a fresh gate re-evaluating the same facts + a measure
        // confirmation) must also pass before the branch is surfaced as worthy. When the
        // recheck collaborator is unwired (F1-F3 construction) a PASS surfaces directly,
        // preserving byte-identical legacy behaviour. confirmed defaults true so an absent
        // recheck never holds a relevant outcome.
        $recheck = null;
        $confirmed = true;
        if ($delivered && $relevant && $this->adversarialRecheck instanceof AtlasSelfImprovementAdversarialRecheck) {
            $recheck = $this->adversarialRecheck->recheck($signal, $mission, $verdict);
            $confirmed = (bool) ($recheck['confirmed'] ?? false);
        }

        // DECISION (three states, all honest):
        //   accepted   = delivered AND gate-relevant AND adversarial-confirmed → keep + surface.
        //   needs_review = delivered AND gate-relevant BUT recheck refused → HOLD the branch
        //                  (kept for the operator to inspect, NOT discarded — it may be
        //                  salvageable; it is simply never claimed as a vetted improvement).
        //   rejected   = delivered AND NOT gate-relevant → DISCARD (off-target/off-concern
        //                  garbage is never presented; governed delete, never main).
        $accepted = $delivered && $relevant && $confirmed;
        $needsReview = $delivered && $relevant && ! $confirmed;

        $discard = null;
        $keptBranch = null;   // surfaced as the accepted, ready-to-merge branch
        $heldBranch = null;   // held for operator review (needs_review)
        $rejectedBranch = null;
        if ($accepted) {
            $keptBranch = $branch;
        } elseif ($needsReview) {
            // Held: not surfaced as accepted, but NOT discarded — the operator decides.
            $heldBranch = $branch;
        } elseif ($delivered && ! $relevant && $branch !== null) {
            // Off-target/off-concern: discard so garbage is never presented as worthy.
            $discard = $this->materializer->discardBranch($root, $branch);
            $rejectedBranch = $branch;
        }

        $outcome = [
            'signal' => $signal,
            'request' => $request,
            'mission_id' => $mission['mission_id'] ?? $missionId,
            // accepted = delivered AND gate-relevant AND adversarial-confirmed.
            'accepted' => $accepted,
            // S3.F4: a gate-passed-but-recheck-refused outcome is held, not accepted.
            'needs_review' => $needsReview,
            'delivered' => $delivered,
            'relevant' => $relevant,
            'relevance_reason' => (string) ($verdict['reason'] ?? 'unknown'),
            'target_file' => $verdict['target_file'] ?? $targetFile,
            'matched_file' => $verdict['matched_file'] ?? null,
            // S3.F2: the two relevance dimensions + the content scoring method actually
            // used (semantic vs the honest token-overlap fallback) ride the outcome for
            // honest measurement — never a self-declared pass, the gate's numbers.
            'target_match' => $verdict['target_match'] ?? null,
            'content_relevance' => $verdict['content_relevance'] ?? null,
            'content_method' => $verdict['content_method'] ?? null,
            // Touched files (PATHS only) ride the outcome for the receipt's provenance.
            'touched_files' => array_values((array) ($verdict['touched_files'] ?? [])),
            // S3.F4: the adversarial re-check verdict (null when the recheck is unwired).
            'recheck' => $recheck,
            'branch' => $keptBranch,
            'held_branch' => $heldBranch,
            'rejected_branch' => $rejectedBranch,
            'discarded' => $discard,
            'stage' => $mission['stage'] ?? null,
            'reason' => $mission['reason'] ?? null,
            // Brain flags ride straight from the mission (honest: did the loop compound?).
            'brain_context_used' => (bool) ($mission['brain_context_used'] ?? false),
            'evidence_recorded' => (bool) ($mission['evidence_recorded'] ?? false),
            'main_untouched' => (bool) ($mission['main_untouched'] ?? true),
            'never_merged' => (bool) ($mission['never_merged'] ?? true),
            'review_commands' => array_values((array) ($mission['review_commands'] ?? [])),
        ];

        // S3.F4 — EVIDENCE / RECEIPT. NO SILENT ACTION: every decision (accepted, reject,
        // needs_review, blocked) writes one honest auditable receipt. FAIL-OPEN — a log
        // outage never breaks the cycle; the receipt marker rides the outcome.
        if ($this->receiptLog instanceof AtlasSelfImprovementReceiptLog) {
            $receipt = $this->receiptLog->record($outcome);
            $outcome['receipt_hash'] = $receipt['receipt_hash'] ?? null;
            $outcome['receipt_written'] = (bool) ($receipt['receipt_written'] ?? false);
            $outcome['decision'] = $receipt['decision'] ?? null;
        }

        return $outcome;
    }

    /**
     * The per-run branch cap (S3.F4 bound). A per-run override (tests / a debug run) wins
     * over config; the config default itself is clamped to [1, max_signals] so the cap can
     * never silently exceed the run's signal fan-out bound.
     *
     * @param  array<string,mixed>  $options
     */
    private function branchCap(array $options): int
    {
        $signalCap = (int) config('atlas.self_construction.max_signals', 5);
        $default = (int) config('atlas.self_construction.max_branches_per_run', $signalCap);
        $cap = (int) ($options['max_branches_per_run'] ?? $default);

        return max(1, min(max(1, $signalCap), $cap));
    }

    /**
     * The kill-switch file path (S3.F4). A per-run override lets tests point at an
     * isolated file; the default is the same stop file the --watch command honors, so a
     * single trip stops BOTH a mid-run cycle and the autonomous repetition. Returns null
     * only when no path can be resolved (the kill check then no-ops, never throws).
     *
     * @param  array<string,mixed>  $options
     */
    private function killFile(array $options): ?string
    {
        $override = $options['kill_file'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        try {
            return storage_path('app/atlas-self-construct.stop');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build the file-anchored mission request from a signal. A code marker becomes a
     * minimal, place-named instruction; an operator gap rides its own request text.
     *
     * @param  array<string,mixed>  $signal
     */
    private function enrichedRequest(array $signal): string
    {
        $file = is_string($signal['file'] ?? null) ? trim((string) $signal['file']) : '';
        $line = (int) ($signal['line'] ?? 0);

        // Operator gap (no file): use its prepared request verbatim — the operator's
        // own words are the aim; the relevance gate admits it as unverifiable-by-target.
        if ($file === '') {
            $req = trim((string) ($signal['request'] ?? $signal['signal'] ?? ''));

            return $req !== '' ? $req : 'Improve Atlas per the operator-supplied gap.';
        }

        // File-anchored: NAME the exact file + line + concern so the generation targets
        // the right place and stays minimal + self-contained.
        $concern = trim((string) ($signal['signal'] ?? $signal['request'] ?? 'address this marker'));

        return sprintf(
            'Resolve the concern at %s:%d — %s. Modify that file (%s); keep the change minimal and self-contained.',
            $file,
            $line,
            $concern,
            $file,
        );
    }

    /**
     * The HONEST cycle summary — the gate's verdict, not the loop's optimism. No
     * self-declared success: accepted counts only branches that DELIVERED AND PASSED
     * the relevance gate; rejected off-target work is reported, never hidden.
     *
     * @param  list<array<string,mixed>>  $signals
     * @param  list<array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    private function summary(array $signals, array $outcomes, bool $useBrain): array
    {
        $accepted = array_values(array_filter($outcomes, static fn (array $o): bool => (bool) $o['accepted']));
        $rejected = array_values(array_filter(
            $outcomes,
            static fn (array $o): bool => (bool) $o['delivered'] && ! (bool) $o['relevant'],
        ));
        // S3.F4 — needs_review: delivered + gate-relevant but the adversarial re-check
        // refused. A distinct, honest third bucket — NOT counted as accepted (it is not a
        // vetted improvement) NOR as rejected (it is not off-target garbage; the branch is
        // held for the operator). Surfaced so the trail is complete, not hidden.
        $needsReview = array_values(array_filter($outcomes, static fn (array $o): bool => (bool) ($o['needs_review'] ?? false)));
        $branches = array_values(array_filter(array_map(
            static fn (array $o): ?string => is_string($o['branch'] ?? null) ? $o['branch'] : null,
            $accepted,
        )));
        $heldBranches = array_values(array_filter(array_map(
            static fn (array $o): ?string => is_string($o['held_branch'] ?? null) ? $o['held_branch'] : null,
            $needsReview,
        )));
        $detected = count($signals);
        $deliveredCount = count(array_filter($outcomes, static fn (array $o): bool => (bool) $o['delivered']));
        $brainCompounded = count(array_filter($outcomes, static fn (array $o): bool => (bool) $o['evidence_recorded']));

        return [
            'schema_version' => self::SCHEMA,
            'brain_anchored' => $useBrain,
            'detected' => $detected,
            'delivered_count' => $deliveredCount,
            'accepted_count' => count($accepted),
            'rejected_count' => count($rejected),
            // S3.F4 — the held-for-review count + branches (the adversarial re-check's
            // honest third bucket). Distinct from accepted (not vetted) and rejected (not
            // discarded garbage). The operator inspects these; they are never auto-merged.
            'needs_review_count' => count($needsReview),
            'held_branches' => $heldBranches,
            'branches' => $branches,
            // HONEST meta-metric (anti-Goodhart): of what was delivered, the share the
            // OUT-OF-PROCESS gate certified on-target. Null when nothing was delivered
            // (no rate to claim — never a fabricated 1.0). This is the rate the recursive
            // loop must IMPROVE over real cycles; the property is EMERGENT, not asserted.
            'relevance_precision' => $deliveredCount > 0
                ? round(count($accepted) / $deliveredCount, 4)
                : null,
            // The loop COMPOUNDS: how many outcomes fed the brain back (the recursion
            // substrate — the next cycle's brain query reaches these).
            'brain_compounded_count' => $brainCompounded,
            'outcomes' => $outcomes,
            'never_merged' => true,
            'main_untouched' => true,
            'operator_action' => 'review + merge the branches you approve — Atlas built them, the relevance gate vetted them, the merge is yours',
            'receipt_hash' => hash('sha256', (string) json_encode([
                'schema' => self::SCHEMA,
                'branches' => $branches,
                'held_branches' => $heldBranches,
                'detected' => $detected,
                'accepted' => count($accepted),
                'rejected' => count($rejected),
                'needs_review' => count($needsReview),
            ], JSON_UNESCAPED_SLASHES)),
        ];
    }
}
