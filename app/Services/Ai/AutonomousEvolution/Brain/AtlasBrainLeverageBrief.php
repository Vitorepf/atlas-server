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

    public const HINT_ROTATE_PATH = 'rotate_path';

    public const HINT_USE_ROUTED_PATH = 'use_routed_path';

    public const HINT_COMPOUND = 'compound';

    public const HINT_HARVEST_FRONTIER = 'harvest_frontier';

    public const HINT_ORIGINATE_FRESH = 'originate_fresh';

    public const REFUSAL_SURGE_THRESHOLD = 3;

    public const COMPOUND_STREAK_THRESHOLD = 3;

    /**
     * Build the consolidated leverage brief from a scope_signals block. Empty/null inputs degrade gracefully
     * (the brief always returns SOMETHING, even if just the default hint with empty evidence).
     *
     * @param  array<string,mixed>  $signals  the scope_signals block (as built by AtlasBrainNextCommand::scopeSignalsFor)
     * @return array{schema:string, action_hint:string, evidence:list<string>, rationale:string}
     */
    public function brief(array $signals): array
    {
        $recommendedPath = isset($signals['recommended_path']) && is_string($signals['recommended_path']) ? $signals['recommended_path'] : null;
        $compounding = is_array($signals['compounding'] ?? null) ? $signals['compounding'] : [];
        $streak = (int) ($compounding['success_streak'] ?? 0);
        $frontier = is_array($signals['frontier_candidates'] ?? null) ? $signals['frontier_candidates'] : [];
        $metrics = is_array($signals['metrics'] ?? null) ? $signals['metrics'] : [];

        $recentRefusals = 0;
        foreach ($metrics as $m) {
            if (is_array($m) && ($m['id'] ?? null) === 'recent_refusal_count') {
                $recentRefusals = (int) ($m['value'] ?? 0);
                break;
            }
        }

        if ($recentRefusals > self::REFUSAL_SURGE_THRESHOLD) {
            return $this->result(
                self::HINT_ROTATE_PATH,
                $this->evidenceFor($signals, ['recent_refusals' => $recentRefusals]),
                "recent_refusal_count={$recentRefusals} > ".self::REFUSAL_SURGE_THRESHOLD.' — origination is misfiring on the current path; rotate before authoring again',
            );
        }

        if ($recommendedPath !== null && $recommendedPath !== '') {
            return $this->result(
                self::HINT_USE_ROUTED_PATH,
                $this->evidenceFor($signals, ['recommended_path' => $recommendedPath]),
                "router recommended '{$recommendedPath}' based on the dominant structural signal — use it unless a stronger override exists",
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
