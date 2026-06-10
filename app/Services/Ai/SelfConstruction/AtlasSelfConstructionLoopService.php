<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;

/**
 * S3.F1 — THE RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP ("Atlas improves Atlas").
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

        $signals = $this->detector->detect($root, $max, $extra);

        $outcomes = [];
        foreach ($signals as $signal) {
            $outcomes[] = $this->processSignal($signal, $root, $deliveryOptions, $useBrain);
        }

        return $this->summary($signals, $outcomes, $useBrain);
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

        // On REJECT of an already-materialized branch: DISCARD it so off-target
        // garbage is never presented to the operator as worthy (governed delete; the
        // materializer refuses anything outside atlas/materialize/, never main).
        $discard = null;
        $keptBranch = $branch;
        if ($delivered && ! $relevant && $branch !== null) {
            $discard = $this->materializer->discardBranch($root, $branch);
            $keptBranch = null; // the rejected branch is no longer presented
        }

        return [
            'signal' => $signal,
            'request' => $request,
            'mission_id' => $mission['mission_id'] ?? $missionId,
            // accepted = delivered AND passed the relevance gate (the only "worthy" state)
            'accepted' => $delivered && $relevant,
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
            'branch' => $keptBranch,
            'rejected_branch' => ($delivered && ! $relevant) ? $branch : null,
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
        $branches = array_values(array_filter(array_map(
            static fn (array $o): ?string => is_string($o['branch'] ?? null) ? $o['branch'] : null,
            $accepted,
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
                'detected' => $detected,
                'accepted' => count($accepted),
                'rejected' => count($rejected),
            ], JSON_UNESCAPED_SLASHES)),
        ];
    }
}
