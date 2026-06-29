<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionOriginationCandidates;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
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

        return [
            'produced' => true,
            'action' => $frontier['action'],                       // proceed | abstain (park + ask the operator)
            'operator_question' => $frontier['operator_question'], // non-null ⇒ the loop is asking, not guessing
            'objective' => $objective,
            'target_path' => $target,
            'obligations' => array_values((array) ($verdict['obligations'] ?? [])),
            'reason' => null,
        ];
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
        $dropped = [];
        $valid = []; // ordered [objective, rel] in leverage-ranked order (was: take first via ??=)
        foreach ($ranked as $candidate) {
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

            $valid[] = [$objective, $rel];
        }

        $this->appendLeverageDroppedCandidates($dropped);

        // QUEUE-AWARE ORIGINATION: consider CODE *and* the live TASK QUEUE. Demote any candidate whose target
        // already has a LIVE task packet (seeded by ANY brain/session) below fresh ones, so the brain stops
        // re-proposing work that already exists in the queue. Binary signal, fail-OPEN. OFF => never reads the
        // queue => byte-identical. Composes BEFORE refusalAwarePick so both demotions stack.
        if ((bool) config('atlas.loop.origination_queue_dedup_enabled', false)) {
            $valid = self::queueAwareDemote($valid, $this->liveQueuedKeys());
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
            File::ensureDirectoryExists(dirname($path));
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return;
            }

            try {
                if (! flock($handle, LOCK_EX)) {
                    return;
                }

                foreach ($records as $record) {
                    $payload = array_merge([
                        'schema_version' => 'atlas.loop.leverage_dropped_candidates.v1',
                        'recorded_at' => Carbon::now()->toIso8601String(),
                    ], $record);
                    fwrite($handle, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                }
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
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
