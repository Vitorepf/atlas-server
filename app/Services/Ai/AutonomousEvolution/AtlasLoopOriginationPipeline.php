<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldEwma;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPatternLearningLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionOriginationCandidates;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcComposer;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcLifecycle;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisComposer;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisLifecycle;
use App\Services\Ai\Cognition\AcosProgram\PredictedImpactBand;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Engineering\EliteCompactionFreezeGuard;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * §5.6 · LAYER 2 — the full "decide by ORIGINATING, then DESIGN" flow.
 *
 * {@see AtlasLoopComprehensionOriginator} ORIGINATES a grounded evolution (writer ≠ judge, every citation an
 * inventory member). But an origination is only WORK once it has been DESIGNED as a principal engineer. This
 * pipeline composes the two: originate → resolve the primary cited symbol to its real scope path → run the
 * {@see AtlasLoopArchitectPhaseGate} so the origination carries a converged design contract (caller
 * protection + the work-type's mandatory proof) or is suppressed (pétreo / blast-radius). So the brain's
 * "decide" phase ORIGINATES new work AND designs it before it can grind — never a raw, undesigned proposal.
 *
 * Fail-closed at every seam: no grounded origination, an unresolvable target, or a non-converged design ⇒ no
 * produced work. Deterministic except the writer (the model-bound origination, §9-fenced inside the originator).
 */
final class AtlasLoopOriginationPipeline
{
    /** @var array<string,mixed>|null */
    private ?array $lastComposedArc = null;

    public function __construct(
        private readonly ?AtlasLoopComprehensionOriginator $originator = null,
        private readonly ?AtlasLoopArchitectPhaseGate $gate = null,
        private readonly ?AtlasLoopCrossTypeLeverageSelector $selector = null,
        // FIX-2 writer-availability preflight (opt-in). A nullable predicate returning bool: true/absent ⇒ the
        // brain writer is available (existing behaviour byte-identical); false ⇒ short-circuit before originating.
        private readonly ?\Closure $writerPreflight = null,
    ) {}

    /**
     * @param  list<string>  $priorAttempts  campaign targets that did not converge — passed to the originator
     *                                       as CONTEXT (informs the writer, never vetoes). §5 learning.
     * @param  array<string,int>  $refusalCounts  per target rel_path => prior intrinsic-refusal count (S215
     *                                            Discovery→Brain coupling). Empty/OFF => byte-identical.
     * @return array{produced:bool, action?: 'proceed'|'abstain'|'writer_unavailable', operator_question?:?string, objective:?string, target_path:?string, obligations:list<array<string,mixed>>, reason:?string}
     */
    public function produce(AtlasLoopScopeComprehensionModel $model, string $repoRoot, array $priorAttempts = [], array $refusalCounts = []): array
    {
        $freezeRefusal = app(EliteCompactionFreezeGuard::class)->refusalPayload('AtlasLoopOriginationPipeline');
        if ($freezeRefusal !== null) {
            return [
                'produced' => false,
                'action' => 'abstain',
                'reason' => (string) ($freezeRefusal['reason'] ?? 'frozen'),
                'objective' => null,
                'target_path' => null,
                'obligations' => [],
                'freeze' => $freezeRefusal,
            ];
        }

        // FIX-2 writer-availability preflight (opt-in, docs/atlas-brain-harness-build-spec.md): if a preflight is
        // injected and reports the brain writer is NOT available (the resolved brain_default provider fails router
        // isConfigured), short-circuit BEFORE touching the originator/gate. Recording a dead writer as an honest
        // no_proposal refusal corrupts the dry-probe and done-set. No preflight ⇒ always-available ⇒ byte-identical.
        if ($this->writerPreflight !== null && ($this->writerPreflight)() !== true) {
            return [
                'produced' => false,
                'action' => 'writer_unavailable',
                'reason' => 'writer_unavailable',
                'objective' => null,
                'target_path' => null,
                'obligations' => [],
            ];
        }

        // Directive #2/#3 — LEVERAGE-FIRST, MATERIAL-ONLY origination. Rank the grounded candidates by leverage
        // (wiring the parked CrossTypeLeverageSelector — the loop's OWN self-chosen evolution), DROP the
        // behaviour-preserving clone-unification PROXY, and originate the TOP material candidate. Flag-gated;
        // OFF ⇒ the free-text writer path (slice 1a) is byte-identical.
        $objective = '';
        $target = null;
        if ((bool) config('atlas.loop.leverage_first_origination_enabled', false)) {
            $picked = $this->leverageFirstMaterialTarget($model, $repoRoot, $refusalCounts);
            if ($picked !== null) {
                [$objective, $target] = $picked;
            }
        }

        if ($objective === '' || $target === null) {
            $origination = ($this->originator ?? new AtlasLoopComprehensionOriginator)->originate($model, $priorAttempts);
            if (($origination['originated'] ?? false) !== true) {
                return $this->refuse((string) ($origination['reason'] ?? 'not_originated'));
            }
            $objective = (string) ($origination['objective'] ?? '');
            $target = $this->resolveTarget($model, (array) ($origination['cited_symbols'] ?? []));
            if ($target === null) {
                return $this->refuse('no_resolvable_inventory_target'); // cited a real symbol but none maps to a scope path
            }
        }

        // DESIGN the originated evolution as a feature (red→green) — caller protection + work-type proof, or PARK.
        $verdict = ($this->gate ?? new AtlasLoopArchitectPhaseGate)->admit($model, $target, 'feature', $repoRoot);
        if (($verdict['admitted'] ?? false) !== true) {
            return $this->refuse((string) ($verdict['reason'] ?? 'design_not_converged'));
        }

        // §5 ABSTAIN-AND-ASK — the frontier cerca. A free cross-model origination is grounded + designed, but
        // it is a NOVEL decision (the model proposed it freely). The honest move is to PARK + ASK the operator
        // unless the target already has real consumers (a modification WITH precedent, not greenfield). The
        // loop never fabricates a confident "proceed" on a greenfield origination.
        $hasPrecedent = count((array) ($verdict['consumer_contracts'] ?? [])) > 0;
        // The operator's autonomous-self-engineer directive: on a GREEN scope the loop ORIGINATES the next
        // material leap instead of parking-and-asking. With proceed_on_grounded_novelty ON, a grounded +
        // designed (architect-admitted) novel origination PROCEEDS; the red→green obligation + cert/refute
        // downstream are the Goodhart floor. Default OFF ⇒ byte-identical (novelty parks-and-asks).
        $proceedOnNovelty = (bool) config('atlas.loop.proceed_on_grounded_novelty_enabled', false);
        $frontier = (new AtlasLoopAbstainAndAsk(0.7, $proceedOnNovelty))->evaluate([
            'grounded' => true,             // it cleared the inventory grounding-veto
            'confidence' => 1.0,            // the deterministic gates (grounding + design) are satisfied
            'novel' => true,               // a free origination has no supply-lane precedent of its own
            'has_precedent' => $hasPrecedent,
            'summary' => $objective,
        ]);

        $result = [
            'produced' => true,
            'action' => $frontier['action'],                       // proceed | abstain (park + ask the operator)
            'operator_question' => $frontier['operator_question'], // non-null ⇒ the loop is asking, not guessing
            'objective' => $objective,
            'target_path' => $target,
            'obligations' => array_values((array) ($verdict['obligations'] ?? [])),
            'reason' => null,
        ];
        if ($this->lastComposedArc !== null) {
            $result['composed_arc'] = $this->lastComposedArc;
        }

        return $result;
    }

    /**
     * @return array{produced:false, objective:null, target_path:null, obligations:list<never>, reason:string}
     */
    private function refuse(string $reason): array
    {
        return ['produced' => false, 'objective' => null, 'target_path' => null, 'obligations' => [], 'reason' => $reason];
    }

    /**
     * Directive #2/#3 — the highest-leverage MATERIAL origination candidate, or null. Wires the parked
     * {@see AtlasLoopCrossTypeLeverageSelector} to RANK the grounded candidates by leverage, then takes the
     * top one whose kind is behaviour-CHANGING (orphan-wiring — a built-but-unwired capability) with a
     * resolvable target. The behaviour-PRESERVING clone-unification class is PROXY (canon: preserva
     * comportamento = melhoria ZERO) and is DROPPED here; doc-gap has no target file yet so it is skipped on
     * this deterministic path. The selector can only REORDER the grounded set (never fabricate), so this is
     * leverage-first WITHOUT a self-scored proxy.
     *
     * @param  array<string,int>  $refusalCounts
     * @return array{0:string, 1:string}|null [objective, target_relative_path]
     */
    private function leverageFirstMaterialTarget(AtlasLoopScopeComprehensionModel $model, string $repoRoot, array $refusalCounts = []): ?array
    {
        $ranked = ($this->selector ?? new AtlasLoopCrossTypeLeverageSelector)->rankedForModel($model);
        if ((bool) config('atlas.loop.multi_source_opportunity_scanner_enabled', false)) {
            $ranked = array_merge($this->multiSourceOpportunityCandidates($repoRoot), $ranked);
        }
        $dropped = [];
        $valid = []; // ordered [objective, rel, yield_path] in leverage-ranked order (was: take first via ??=)
        $groundedForArc = [];
        foreach ($ranked as $index => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            if ((string) ($candidate['kind'] ?? '') !== AtlasLoopComprehensionOriginationCandidates::KIND_ORPHAN_WIRING) {
                $this->recordDroppedLeverageCandidate($dropped, $candidate, $this->skippedReason($candidate));

                continue; // clone_unification = proxy (dropped); doc_gap has no target file (skipped here)
            }
            $objective = trim((string) ($candidate['summary'] ?? ''));
            $rel = is_string($candidate['target_path'] ?? null) ? ltrim((string) $candidate['target_path'], '/') : '';
            if ($objective === '' || $rel === '' || ! is_file(rtrim($repoRoot, '/').'/'.$rel)) {
                $this->recordDroppedLeverageCandidate($dropped, $candidate, 'target_path_missing');

                continue;
            }

            $valid[] = [$objective, $rel, $this->yieldPathForCandidate($candidate)];
            $groundedForArc[] = array_merge($candidate, [
                'id' => (string) ($candidate['id'] ?? 'rank-'.$index),
                'summary' => $objective,
                'target_path' => $rel,
            ]);
        }

        $this->appendLeverageDroppedCandidates($dropped);

        $arcPick = $this->composedArcFirstTask($groundedForArc);
        if ($arcPick !== null) {
            return $arcPick;
        }

        // QUEUE-AWARE ORIGINATION: consider CODE *and* the live TASK QUEUE. Demote any candidate whose target
        // already has a LIVE task packet (seeded by ANY brain/session) below fresh ones, so the brain stops
        // re-proposing work that already exists in the queue. Binary signal, fail-OPEN. OFF => never reads the
        // queue => byte-identical. Composes BEFORE refusalAwarePick so both demotions stack.
        if ((bool) config('atlas.loop.origination_queue_dedup_enabled', false)) {
            $valid = self::queueAwareDemote($valid, $this->liveQueuedKeys());
        }

        $yieldPick = self::yieldAwarePick(
            $valid,
            $this->provenYieldByPath(),
            (bool) config('atlas.loop.origination_yield_enabled', false),
        );
        if ($yieldPick !== null) {
            $valid = [$yieldPick];
        }

        // MULTN17-06 — active vision theses reorder candidates as WEIGHT, never veto.
        if ((bool) config('atlas.loop.vision_theses_enabled', false)) {
            $valid = EvidenceVisionThesisComposer::thesisAwareReorder(
                $valid,
                $this->activeVisionTheses($repoRoot, $ranked),
                true,
            );
        }

        // ORIGINATION REFUSAL MEMORY (S215 — Discovery→Brain coupling): demote targets the brain has
        // already refused >=N times below fresh ones, so it ORIGINATES a new target instead of
        // re-proposing a failed one. OFF/empty => head of the ranked set (byte-identical first-valid pick).
        return self::refusalAwarePick(
            $valid,
            $refusalCounts,
            (bool) config('atlas.loop.origination_refusal_memory_enabled', false),
            (int) config('atlas.loop.origination_refusal_memory_min', 2),
        );
    }

    /**
     * MULTN17-03 — local, evidence-backed opportunity leads. These are emitted
     * as ordinary orphan_wiring candidates so the existing selector/yield/queue
     * logic remains the only picker; this scanner only adds grounded sources.
     *
     * @return list<array<string,mixed>>
     */
    private function multiSourceOpportunityCandidates(string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        if ($repoRoot === '') {
            return [];
        }

        return array_values(array_merge(
            $this->openGapOpportunityCandidates($repoRoot),
            $this->ponytailOpportunityCandidates($repoRoot),
        ));
    }

    /** @return list<array<string,mixed>> */
    private function openGapOpportunityCandidates(string $repoRoot): array
    {
        $ledger = $repoRoot.'/docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md';
        if (! is_file($ledger)) {
            return [];
        }

        $lines = @file($ledger, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $candidates = [];
        foreach ($lines as $index => $line) {
            $text = trim((string) $line);
            if ($text === '' || ! str_contains($text, '[ ]')) {
                continue;
            }
            $rel = $this->firstBacktickedRepoPath($text);
            if ($rel === null || ! is_file($repoRoot.'/'.$rel)) {
                continue;
            }

            $candidates[] = $this->evidenceOpportunityCandidate(
                source: 'open_gap_ledger',
                summary: $text,
                targetPath: $rel,
                evidenceFile: 'docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md',
                evidenceLine: $index + 1,
            );
        }

        return $candidates;
    }

    /** @return list<array<string,mixed>> */
    private function ponytailOpportunityCandidates(string $repoRoot): array
    {
        $roots = ['app', 'tests'];
        $candidates = [];
        foreach ($roots as $root) {
            $dir = $repoRoot.'/'.$root;
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $lines = @file($path, FILE_IGNORE_NEW_LINES);
                if (! is_array($lines)) {
                    continue;
                }
                foreach ($lines as $index => $line) {
                    $text = trim((string) $line);
                    if (! str_contains($text, 'ponytail:')) {
                        continue;
                    }
                    $rel = ltrim(str_replace($repoRoot, '', $path), '/');
                    $candidates[] = $this->evidenceOpportunityCandidate(
                        source: 'ponytail_debt',
                        summary: $text,
                        targetPath: $rel,
                        evidenceFile: $rel,
                        evidenceLine: $index + 1,
                    );
                    break;
                }
            }
        }

        return $candidates;
    }

    /** @return array<string,mixed> */
    private function evidenceOpportunityCandidate(string $source, string $summary, string $targetPath, string $evidenceFile, int $evidenceLine): array
    {
        return [
            'kind' => AtlasLoopComprehensionOriginationCandidates::KIND_ORPHAN_WIRING,
            'summary' => "Evidence-backed opportunity ({$source}): {$summary}",
            'target_path' => ltrim($targetPath, '/'),
            'target_fqcn' => null,
            'members' => [],
            'capability' => null,
            'evidence' => [
                'source' => $source,
                'file' => $evidenceFile,
                'line' => $evidenceLine,
                'resolves_to_file' => true,
            ],
        ];
    }

    private function firstBacktickedRepoPath(string $line): ?string
    {
        if (preg_match('/`([^`]+)`/', $line, $matches) !== 1) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim((string) $matches[1])), '/');

        return preg_match('/^(app|tests|config|routes|docs)\//', $path) === 1 ? $path : null;
    }

    /**
     * Pure refusal-aware selection over the leverage-ranked valid candidates. Public+static so the reorder
     * is directly unit-testable (the architect gate / selector are final and un-fakeable). DEMOTE, never
     * exclude: a fully-refused set still yields its best candidate (the loop never dead-stalls). isDone()
     * sticky-dedups SERVED targets upstream, so this governs only refused-but-never-served targets — exactly
     * the perseveration the operator named "substrato sem circulação".
     *
     * @param  list<array{0:string,1:string}>  $valid  [objective, rel] in leverage-ranked order
     * @param  array<string,int>  $refusalCounts  per target rel_path
     * @return array{0:string,1:string}|null
     */
    public static function refusalAwarePick(array $valid, array $refusalCounts, bool $enabled, int $minRefusals): ?array
    {
        if ($valid === []) {
            return null;
        }
        if (! $enabled || $refusalCounts === []) {
            return $valid[0];
        }
        $min = max(1, $minRefusals);
        $fresh = [];
        $refused = [];
        foreach ($valid as $pair) {
            if ((int) ($refusalCounts[$pair[1]] ?? 0) >= $min) {
                $refused[] = $pair;
            } else {
                $fresh[] = $pair;
            }
        }

        // Stable: fresh keep leverage order; fully-refused fall to the back in leverage order.
        $ordered = array_merge($fresh, $refused);

        return $ordered[0];
    }

    /**
     * Pure queue-aware demotion: targets that already have a LIVE task packet fall to the back in stable
     * leverage order (DEMOTE, never exclude — an all-queued set still yields its best candidate, which
     * isDone()/the dry-probe drive to an honest stop, never a dead-stall). Public+static so the keying is
     * directly unit-testable. Binary membership only — never a scalar (anti-Goodhart). Empty keys => no-op.
     *
     * @param  list<array{0:string,1:string}>  $valid  [objective, rel] in leverage-ranked order
     * @param  array<string,bool>  $queuedKeys  set of normKey(rel) for every live-queued target
     * @return list<array{0:string,1:string}>
     */
    public static function queueAwareDemote(array $valid, array $queuedKeys): array
    {
        if ($valid === [] || $queuedKeys === []) {
            return $valid;
        }
        $fresh = [];
        $queued = [];
        foreach ($valid as $pair) {
            if (isset($queuedKeys[self::normKey((string) $pair[1])])) {
                $queued[] = $pair;
            } else {
                $fresh[] = $pair;
            }
        }

        return array_merge($fresh, $queued);
    }

    /**
     * Pure proven-yield selection over leverage-ranked candidates. Unknown-yield
     * paths stay ahead of known low-yield paths to preserve exploration; known
     * paths are ordered by proven_real EWMA only, never raw accept/refuse counts.
     *
     * @param  list<array{0:string,1:string,2?:string|null}>  $valid
     * @param  array<string,array{ewma?:float,samples?:int}>  $yieldByPath
     * @return array{0:string,1:string,2?:string|null}|null
     */
    public static function yieldAwarePick(array $valid, array $yieldByPath, bool $enabled): ?array
    {
        if ($valid === []) {
            return null;
        }
        if (! $enabled || $yieldByPath === []) {
            return $valid[0];
        }

        $unknown = [];
        $known = [];
        foreach ($valid as $index => $pair) {
            $path = is_string($pair[2] ?? null) ? (string) $pair[2] : '';
            if ($path === '' || ! isset($yieldByPath[$path])) {
                $unknown[] = [$index, $pair];
            } else {
                $known[] = [$index, $pair, (float) ($yieldByPath[$path]['ewma'] ?? 0.0)];
            }
        }

        if ($unknown !== []) {
            return $unknown[0][1];
        }
        usort($known, static function (array $a, array $b): int {
            $byYield = $b[2] <=> $a[2];

            return $byYield !== 0 ? $byYield : ($a[0] <=> $b[0]);
        });

        return $known[0][1] ?? $valid[0];
    }

    /**
     * Canonical path key both the candidate side and the queue side reduce to, so the membership test can
     * never silently miss on a leading slash / backslash / './' prefix / surrounding whitespace. NO case-fold
     * (both sides derive case from the same real filesystem walk — folding would only risk a false collision).
     */
    private static function normKey(string $s): string
    {
        $s = ltrim(str_replace('\\', '/', trim($s)), '/');
        if (str_starts_with($s, './')) {
            $s = substr($s, 2);
        }

        return ltrim($s, '/');
    }

    /**
     * Set of normKey(target) over every LIVE task packet in the serving queue (the brain's view of "work that
     * already exists"). list(['status'=>$s]) filters on the REGISTRY entry status — the live lifecycle status;
     * record['task_packet']['status'] is frozen at build-time ('planned') and is NOT read. Fail-OPEN: any
     * queue-read error returns [] so a disk hiccup never blocks origination (isDone() still catches served
     * targets). ponytail: N small-file reads per cycle at live volume; if live count ever hits thousands,
     * index allowed_files in one registry pass instead.
     *
     * @return array<string,bool>
     */
    private function liveQueuedKeys(): array
    {
        $keys = [];
        try {
            foreach (['queued', 'claimable', 'claimed', 'lease_expired', 'released', 'blocked'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $record) {
                    $scope = (array) (data_get($record, 'task_packet.normalized_scope') ?? []);
                    $paths = (array) ($scope['allowed_files'] ?? []);
                    if ($paths === []) {
                        $paths = (array) ($scope['scope_in'] ?? []);
                    }
                    foreach ($paths as $path) {
                        $key = self::normKey((string) $path);
                        if ($key !== '') {
                            $keys[$key] = true;
                        }
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $keys;
    }

    /**
     * MULTN17-02 — when armed, compose neighbor candidates into one obra arc and originate
     * the first ordered task. Flag OFF ⇒ null (byte-identical single-task pick).
     *
     * @param  list<array<string,mixed>>  $groundedCandidates
     * @return array{0:string,1:string}|null
     */
    private function composedArcFirstTask(array $groundedCandidates): ?array
    {
        $this->lastComposedArc = null;
        if (! (bool) config('atlas.loop.composed_obra_arc_enabled', false)) {
            return null;
        }

        $composed = ComposedObraArcComposer::compose($groundedCandidates, [], [
            'enabled' => true,
        ]);
        if (($composed['composed'] ?? false) !== true) {
            return null;
        }

        $arc = is_array($composed['arcs'][0] ?? null) ? $composed['arcs'][0] : null;
        if ($arc === null) {
            return null;
        }

        ComposedObraArcLifecycle::register($arc);
        $first = is_array($arc['tasks'][0] ?? null) ? $arc['tasks'][0] : null;
        if ($first === null) {
            return null;
        }

        $this->lastComposedArc = $arc;

        return [
            (string) ($first['objective'] ?? ''),
            ltrim((string) ($first['target_path'] ?? ''), '/'),
        ];
    }

    private function yieldPathForCandidate(array $candidate): string
    {
        return match ((string) ($candidate['kind'] ?? '')) {
            AtlasLoopComprehensionOriginationCandidates::KIND_ORPHAN_WIRING => 'pattern-design',
            AtlasLoopComprehensionOriginationCandidates::KIND_DOC_GAP_CAPABILITY => 'frontier-harvest',
            default => 'comprehension-deepening',
        };
    }

    /**
     * MULTN17-06 — derive and maintain ≤3 active vision theses from local evidence.
     *
     * @param  list<array<string,mixed>>  $rankedCandidates
     * @return list<array<string,mixed>>
     */
    private function activeVisionTheses(string $repoRoot, array $rankedCandidates = []): array
    {
        $leads = [];
        foreach ($rankedCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $evidence = (array) ($candidate['evidence'] ?? []);
            if ($evidence === []) {
                continue;
            }
            $leads[] = [
                'target_path' => (string) ($candidate['target_path'] ?? ''),
                'evidence' => $evidence,
            ];
        }

        $calibrationRows = [];
        try {
            foreach (app(AtlasBrainPatternLearningLedger::class)->entries() as $row) {
                $band = PredictedImpactBand::classify([
                    'rung' => (string) ($row['rung'] ?? 'task'),
                    'rank' => (int) ($row['rank'] ?? 99),
                    'path_yield' => (float) ($row['path_yield'] ?? 0.0),
                ]);
                $calibrationRows[] = [
                    'band' => (string) ($band['band'] ?? 'low'),
                    'realized' => ($row['proven_real'] ?? null) === true,
                    'status' => ($row['proven_real'] ?? null) === null ? 'unresolved' : 'resolved',
                ];
            }
        } catch (Throwable) {
            $calibrationRows = [];
        }

        $composed = EvidenceVisionThesisComposer::compose([
            'enabled' => true,
            'series_windows' => $this->visionSeriesWindows(),
            'leads' => $leads,
            'calibration' => PredictedImpactBand::calibration($calibrationRows),
            'outcomes' => $this->visionOutcomeRows(),
        ]);
        if (($composed['composed'] ?? false) === true) {
            EvidenceVisionThesisLifecycle::ingestComposed($composed);
        }

        EvidenceVisionThesisLifecycle::evaluateAndArchive(
            $this->visionSeriesWindows(),
            $this->visionOutcomeRows(),
            PredictedImpactBand::calibration($calibrationRows),
            $leads,
        );

        return EvidenceVisionThesisLifecycle::activeTheses();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function visionSeriesWindows(): array
    {
        try {
            $byPath = (array) data_get(
                app(AtlasBrainPathYieldEwma::class)->compute(
                    $this->patternLearningTail(),
                    app(AtlasBrainHintToPathTranslator::class),
                ),
                'by_path',
                [],
            );
            $windows = [];
            $index = 0;
            foreach ($byPath as $path => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $windows[] = [
                    'series' => 'atlas.brain.path_yield_ewma.v1',
                    'stage' => (string) $path,
                    'yield' => (float) ($row['ewma'] ?? 0.0),
                    'window' => $index++,
                ];
            }

            return $windows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function visionOutcomeRows(): array
    {
        $rows = [];
        try {
            foreach (app(AtlasBrainPatternLearningLedger::class)->entries() as $row) {
                $path = trim((string) ($row['action_hint'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $rows[] = [
                    'path' => $path,
                    'proven_real' => ($row['proven_real'] ?? null) === true ? true : false,
                    'outcome_id' => hash('sha256', json_encode([
                        $path,
                        $row['result_kind'] ?? '',
                        $row['recorded_at'] ?? '',
                    ], JSON_UNESCAPED_SLASHES)),
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function patternLearningTail(): array
    {
        $tail = [];
        try {
            foreach (app(AtlasBrainPatternLearningLedger::class)->entries() as $row) {
                $tail[] = [
                    'action_hint' => (string) ($row['action_hint'] ?? ''),
                    'result_kind' => (string) ($row['result_kind'] ?? ''),
                    'proven_real' => ($row['proven_real'] ?? null) === true,
                ];
            }
            foreach (app(AtlasBrainReflectionStream::class)->entries() as $row) {
                $tail[] = [
                    'action_hint' => (string) (data_get($row, 'signals.0', '')),
                    'result_kind' => match ((string) ($row['result_kind'] ?? '')) {
                        AtlasBrainReflectionStream::KIND_SUCCESS => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
                        AtlasBrainReflectionStream::KIND_BLOCKED => AtlasBrainPatternLearningLedger::RESULT_BLOCKED,
                        AtlasBrainReflectionStream::KIND_CLEAN_NO_OP => AtlasBrainPatternLearningLedger::RESULT_NO_OP,
                        default => AtlasBrainPatternLearningLedger::RESULT_REJECTED,
                    },
                    'proven_real' => ($row['proven_real'] ?? null) === true,
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return $tail;
    }

    /** @return array<string,array{ewma:float,samples:int}> */
    private function provenYieldByPath(): array
    {
        try {
            return (array) data_get(
                app(AtlasBrainPathYieldEwma::class)->compute($this->patternLearningTail(), app(AtlasBrainHintToPathTranslator::class)),
                'by_path',
                [],
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $dropped
     * @param  array<string,mixed>  $candidate
     */
    private function recordDroppedLeverageCandidate(array &$dropped, array $candidate, string $reason): void
    {
        if (count($dropped) >= 20) {
            return;
        }

        $target = is_string($candidate['target_path'] ?? null)
            ? ltrim((string) $candidate['target_path'], '/')
            : null;

        $dropped[] = [
            'kind' => (string) ($candidate['kind'] ?? 'unknown'),
            'summary' => trim((string) ($candidate['summary'] ?? '')),
            'target_path' => $target !== '' ? $target : null,
            'skipped_reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function skippedReason(array $candidate): string
    {
        return match ((string) ($candidate['kind'] ?? '')) {
            AtlasLoopComprehensionOriginationCandidates::KIND_CLONE_UNIFICATION => 'proxy_clone_unification',
            AtlasLoopComprehensionOriginationCandidates::KIND_DOC_GAP_CAPABILITY => 'doc_gap_no_target',
            default => 'target_path_missing',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function appendLeverageDroppedCandidates(array $records): void
    {
        if ($records === [] || ! (bool) config('atlas.loop.leverage_first_origination_enabled', false)) {
            return;
        }

        $path = (string) config(
            'atlas.loop.morning_digest.leverage_dropped_log_path',
            storage_path('app/atlas/loop/leverage-dropped-candidates.jsonl'),
        );
        if ($path === '') {
            return;
        }

        try {
            $store = new JsonlReceiptStore($path);
            foreach ($records as $record) {
                $store->append(array_merge([
                    'schema_version' => 'atlas.loop.leverage_dropped_candidates.v1',
                    'recorded_at' => Carbon::now()->toIso8601String(),
                ], $record));
            }

            AtlasLoopMorningDigestService::trimJsonl(
                $path,
                (int) config('atlas.loop.leverage_dropped_candidates_max_lines', 1000),
            );
        } catch (Throwable) {
            // Operator visibility is best-effort; origination must never fail because the digest log is unavailable.
        }
    }

    /**
     * Resolve the FIRST cited symbol that maps to a real inventory member's rel-path (by fqcn, rel-path, or
     * class-name). Deterministic; null when none of the (already inventory-grounded) citations names a file.
     *
     * @param  list<string>  $cited
     */
    private function resolveTarget(AtlasLoopScopeComprehensionModel $model, array $cited): ?string
    {
        foreach ($cited as $symbol) {
            $needle = strtolower(ltrim(trim((string) $symbol), '\\/'));
            $needleClass = $this->classOf($needle);
            foreach ($model->inventory as $item) {
                $rel = (string) ($item['rel_path'] ?? '');
                $fqcn = strtolower(ltrim((string) ($item['fqcn'] ?? ''), '\\'));
                if ($rel === '') {
                    continue;
                }
                if ($needle === strtolower(ltrim($rel, '/')) || $needle === $fqcn || $needleClass === $this->classOf($fqcn) || $needleClass === $this->classOf(strtolower($rel))) {
                    return ltrim($rel, '/');
                }
            }
        }

        return null;
    }

    private function classOf(string $value): string
    {
        foreach (['\\', '/'] as $sep) {
            $pos = strrpos($value, $sep);
            if ($pos !== false) {
                $value = substr($value, $pos + 1);
            }
        }

        return str_ends_with($value, '.php') ? substr($value, 0, -4) : $value;
    }
}
