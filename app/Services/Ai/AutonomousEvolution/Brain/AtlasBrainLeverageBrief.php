<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * INTEGRATION substrate — with all 7 portfolio paths now feeding signals into the payload (structural
 * digest + portfolio router + frontier source + compounding + metrics + simulation twin), the brain
 * receives a LOT of data but no single recommendation tying it together. This organ is the consolidation:
 * a pure, deterministic READ over the assembled scope_signals block that emits ONE action_hint + the
 * 3-strongest evidence cues + a rationale referencing the underlying facts.
 *
 * DECISION RULES (deterministic, fact-based; rules apply in order — first match wins):
 *
 *   1. RECENT REFUSAL SURGE — if recent_refusal_count > REFUSAL_SURGE_THRESHOLD ⇒ "rotate_path".
 *      A surge means origination is misfiring on the current path; the brain should switch even if a
 *      structural signal also exists (a high refusal count IS the dominant signal).
 *   2. PORTFOLIO ROUTER RECOMMENDATION — if recommended_path is set ⇒ use it. The router already
 *      picked deterministically based on the dominant structural signal.
 *   3. COMPOUNDING STREAK — if recent_served_streak >= COMPOUND_STREAK_THRESHOLD ⇒ "compound".
 *      The brain is on a productive run; the next leap should build on the winning theme.
 *   4. FRONTIER CANDIDATE PRESENT — if frontier_candidates not empty ⇒ "harvest_frontier".
 *      An external/curated source has material ready to port.
 *   5. DEFAULT — "originate_fresh" (no dominant signal, default to the brain's normal rotation).
 *
 * Author≠judge intact: the brief is a HINT, never a directive. The brain still authors the spec; the
 * gate still vets it. Pétreo: the réu never edits the organ that recommends what it should do next —
 * else it'd always recommend whichever leap it wanted to "win", collapsing the rotation again.
 */
final class AtlasBrainLeverageBrief
{
    public const SCHEMA = 'atlas.brain.leverage_brief.v1';

    public const HINT_FIX_GATE_REGRESSION = 'fix_gate_regression';

    public const HINT_ROTATE_PATH = 'rotate_path';

    public const HINT_ESCALATE_PERSEVERATION = 'escalate_perseveration';

    public const HINT_USE_DRAFTED_CANDIDATE = 'use_drafted_candidate';

    public const HINT_USE_ROUTED_PATH = 'use_routed_path';

    public const HINT_COMPOUND = 'compound';

    public const HINT_HARVEST_FRONTIER = 'harvest_frontier';

    public const HINT_ORIGINATE_FRESH = 'originate_fresh';

    public const REFUSAL_SURGE_THRESHOLD = 3;

    public const COMPOUND_STREAK_THRESHOLD = 3;

    public const PERSEVERATION_STREAK_THRESHOLD = 3;

    /**
     * Build the consolidated leverage brief from a scope_signals block. Empty/null inputs degrade gracefully
     * (the brief always returns SOMETHING, even if just the default hint with empty evidence).
     *
     * @param  array<string,mixed>  $signals  the scope_signals block (as built by AtlasBrainNextCommand::scopeSignalsFor)
     * @return array{schema:string, action_hint:string, evidence:list<string>, rationale:string}
     */
    /**
     * @param  array<string,mixed>  $signals
     * @param  list<array{kind?:string, reflection?:string}>  $priorBriefs  top-K newest-first reflections
     *                                                                      (L14 time series of action_hints)
     * @return array{schema:string, action_hint:string, evidence:list<string>, rationale:string}
     */
    public function brief(array $signals, array $priorBriefs = []): array
    {
        $base = $this->computeHint($signals, $priorBriefs);
        $previous = $this->previousActionHintOf($priorBriefs);
        $base['previous_action_hint'] = $previous;
        $base['continuity'] = match (true) {
            $previous === null => 'first',
            $previous === $base['action_hint'] => 'held',
            default => 'changed',
        };

        return $base;
    }

    /**
     * @param  array<string,mixed>  $signals
     * @param  list<array{kind?:string, reflection?:string}>  $priorBriefs
     * @return array{schema:string, action_hint:string, evidence:list<string>, rationale:string}
     */
    private function computeHint(array $signals, array $priorBriefs): array
    {
        // RULE -1 — GATE REGRESSION (foundational): if the runtime adversarial auditors found ANY hole, the
        // gate that protects the muscle is broken. Every other recommendation is moot until the wall is
        // restored. Trumps perseveration (which is about strategy) because this is about structural safety.
        $gateHealth = is_array($signals['gate_health'] ?? null) ? $signals['gate_health'] : [];
        $inspectorHoles = (int) ($gateHealth['inspector_holes'] ?? 0);
        $seedGateHoles = (int) ($gateHealth['seed_gate_holes'] ?? 0);
        if ($inspectorHoles > 0 || $seedGateHoles > 0) {
            return $this->result(
                self::HINT_FIX_GATE_REGRESSION,
                ['inspector_holes='.$inspectorHoles, 'seed_gate_holes='.$seedGateHoles],
                "gate_health reports {$inspectorHoles} inspector hole(s) and {$seedGateHoles} seed-gate hole(s) — the wall the muscle relies on is broken; originate a fix for the failing audit attack(s) BEFORE any other leap",
            );
        }

        // RULE 0 — PERSEVERATION (highest priority, meta-signal): if the time series of recent briefs shows
        // the SAME action_hint K times in a row, the brain has been recommending the same dead path; escalate
        // to abstain/operator rather than recommend it again. Reads the L14-populated prior_briefs prefix
        // text — each entry is "leverage_brief: <hint> — <rationale>". Cheap textual sameness check.
        $streakHint = $this->perseverationStreakHint($priorBriefs);
        if ($streakHint !== null) {
            return $this->result(
                self::HINT_ESCALATE_PERSEVERATION,
                ['perseveration_streak='.self::PERSEVERATION_STREAK_THRESHOLD, 'stuck_on='.$streakHint],
                'the last '.self::PERSEVERATION_STREAK_THRESHOLD."+ briefs all recommended '{$streakHint}' — the scope isn't converging on that path; escalate (abstain harder, switch scope, surface to the operator) rather than recommend it again",
            );
        }

        $recommendedPath = isset($signals['recommended_path']) && is_string($signals['recommended_path']) ? $signals['recommended_path'] : null;
        $compounding = is_array($signals['compounding'] ?? null) ? $signals['compounding'] : [];
        $streak = (int) ($compounding['success_streak'] ?? 0);
        $frontier = is_array($signals['frontier_candidates'] ?? null) ? $signals['frontier_candidates'] : [];
        $metrics = is_array($signals['metrics'] ?? null) ? $signals['metrics'] : [];

        $recentRefusals = 0;
        $worstRefusalStreak = 0;
        foreach ($metrics as $m) {
            if (! is_array($m)) {
                continue;
            }
            if (($m['id'] ?? null) === 'recent_refusal_count') {
                $recentRefusals = (int) ($m['value'] ?? 0);
            } elseif (($m['id'] ?? null) === 'worst_refusal_streak') {
                $worstRefusalStreak = (int) ($m['value'] ?? 0);
            }
        }

        if ($recentRefusals > self::REFUSAL_SURGE_THRESHOLD) {
            return $this->result(
                self::HINT_ROTATE_PATH,
                $this->evidenceFor($signals, ['recent_refusals' => $recentRefusals, 'worst_streak' => $worstRefusalStreak]),
                "recent_refusal_count={$recentRefusals} > ".self::REFUSAL_SURGE_THRESHOLD." (worst_refusal_streak={$worstRefusalStreak}) — origination is misfiring; rotate before authoring again",
            );
        }

        // RULE 2 — when the drafter has already shaped ready-to-seed specs from the digest's orphans, those
        // are MORE concrete than any abstract path recommendation: the brain can pick one + go straight to
        // brain:seed. The router's recommendation still ships in scope_signals; the brief just elevates the
        // drafted candidates because they're a smaller next-step (lower friction = higher leverage now).
        $drafts = is_array($signals['drafted_candidates'] ?? null) ? $signals['drafted_candidates'] : [];
        if ($drafts !== []) {
            return $this->result(
                self::HINT_USE_DRAFTED_CANDIDATE,
                $this->evidenceFor($signals, ['drafted_candidates' => count($drafts)]),
                count($drafts).' draft(s) ready-to-seed from the orphan digest — pick the best and brain:seed it instead of authoring fresh',
            );
        }

        if ($recommendedPath !== null && $recommendedPath !== '') {
            $executor = $this->executorOrganFor($recommendedPath);
            $cues = ['recommended_path' => $recommendedPath];
            if ($executor !== null) {
                $cues['executor'] = $executor;
            }

            return $this->result(
                self::HINT_USE_ROUTED_PATH,
                $this->evidenceFor($signals, $cues),
                "router recommended '{$recommendedPath}'".($executor !== null ? " (executor: {$executor})" : '').' based on the dominant structural signal — use it unless a stronger override exists',
            );
        }

        if ($streak >= self::COMPOUND_STREAK_THRESHOLD) {
            return $this->result(
                self::HINT_COMPOUND,
                $this->evidenceFor($signals, ['success_streak' => $streak]),
                "served streak of {$streak} ≥ ".self::COMPOUND_STREAK_THRESHOLD.' — compound the winning theme rather than switching cold',
            );
        }

        if ($frontier !== []) {
            return $this->result(
                self::HINT_HARVEST_FRONTIER,
                $this->evidenceFor($signals, ['frontier_count' => count($frontier)]),
                'frontier source has '.count($frontier).' curated candidate(s) — port one rather than originating from scratch',
            );
        }

        return $this->result(
            self::HINT_ORIGINATE_FRESH,
            $this->evidenceFor($signals, []),
            'no dominant signal — originate fresh via the default rotation',
        );
    }

    /**
     * Build the top-3 evidence cues — counts + facts pulled from the signals block. Bounded to keep
     * the brief small even when the payload is rich.
     *
     * @param  array<string,mixed>  $signals
     * @param  array<string,int|string>  $highlights
     * @return list<string>
     */
    private function evidenceFor(array $signals, array $highlights): array
    {
        $cues = [];
        foreach ($highlights as $key => $value) {
            $cues[] = $key.'='.$value;
        }

        $orphans = is_array($signals['orphans'] ?? null) ? $signals['orphans'] : [];
        $clones = is_array($signals['clone_clusters'] ?? null) ? $signals['clone_clusters'] : [];
        $gaps = is_array($signals['doc_stated_gaps'] ?? null) ? $signals['doc_stated_gaps'] : [];
        if ($orphans !== []) {
            $cues[] = 'orphans='.count($orphans);
        }
        if ($clones !== []) {
            $cues[] = 'clone_clusters='.count($clones);
        }
        if ($gaps !== []) {
            $cues[] = 'doc_stated_gaps='.count($gaps);
        }

        // Dedup + bound to 3 cues (small + scannable).
        $cues = array_values(array_unique($cues));

        return array_slice($cues, 0, 3);
    }

    /**
     * Resolve the executor_organ FQCN for a portfolio path id. Delegated to AtlasBrainPathCatalog
     * (single-source lookup over config('atlas.brain.paths')); returns null when the path is unknown.
     */
    private function executorOrganFor(string $pathId): ?string
    {
        return app(AtlasBrainPathCatalog::class)->executorOrganFor($pathId);
    }

    /**
     * Detect a perseveration streak in the prior-briefs time series. Returns the repeated action_hint when the
     * NEWEST K entries all share the same hint, else null. Reflection text format is "leverage_brief: <hint>
     * — <rationale>" (L14); we parse the hint by extracting the token between "leverage_brief: " and " —".
     *
     * @param  list<array{kind?:string, reflection?:string}>  $priorBriefs
     */
    private function perseverationStreakHint(array $priorBriefs): ?string
    {
        if (count($priorBriefs) < self::PERSEVERATION_STREAK_THRESHOLD) {
            return null;
        }
        $hints = [];
        foreach (array_slice($priorBriefs, 0, self::PERSEVERATION_STREAK_THRESHOLD) as $brief) {
            $text = (string) ($brief['reflection'] ?? '');
            if (! str_starts_with($text, 'leverage_brief: ')) {
                return null;
            }
            $rest = substr($text, strlen('leverage_brief: '));
            $cut = strpos($rest, ' — ');
            $hint = trim($cut === false ? $rest : substr($rest, 0, $cut));
            if ($hint === '') {
                return null;
            }
            $hints[] = $hint;
        }

        return count(array_unique($hints)) === 1 ? $hints[0] : null;
    }

    /**
     * Extract the immediate prior action_hint from the time series (the newest entry only). Returns null
     * when no prior briefs exist or the newest entry isn't a leverage_brief reflection.
     *
     * @param  list<array{kind?:string, reflection?:string}>  $priorBriefs
     */
    private function previousActionHintOf(array $priorBriefs): ?string
    {
        if ($priorBriefs === []) {
            return null;
        }
        $text = (string) ($priorBriefs[0]['reflection'] ?? '');
        if (! str_starts_with($text, 'leverage_brief: ')) {
            return null;
        }
        $rest = substr($text, strlen('leverage_brief: '));
        $cut = strpos($rest, ' — ');
        $hint = trim($cut === false ? $rest : substr($rest, 0, $cut));

        return $hint === '' ? null : $hint;
    }

    /**
     * @param  list<string>  $evidence
     * @return array{schema:string, action_hint:string, evidence:list<string>, rationale:string}
     */
    private function result(string $hint, array $evidence, string $rationale): array
    {
        return [
            'schema' => self::SCHEMA,
            'action_hint' => $hint,
            'evidence' => $evidence,
            'rationale' => $rationale,
        ];
    }
}
