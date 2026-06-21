<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * P27 · SLICE 1 — the research-to-RED ORIGINATION chain (the net-new supply lever the local-failure lanes
 * cannot reach). It turns an egress-safe research TOPIC into a RED-gated, source-QUARANTINED loop objective:
 *
 *   topic ─▶ {@see AtlasLoopExternalResearchService::research} (egress check + fail-closed backend)
 *         ─▶ advisory note (NEVER a proof) ─▶ objective that demands the loop's OWN RED test.
 *
 * The two non-negotiables the mission fixes for research supply are enforced HERE, in code, not by trust:
 *   1. SOURCE-QUARANTINE — the research note is ideation CONTEXT only. It is stamped `research_source` +
 *      `source_quarantined=true` and the objective text explicitly says the note is advisory, so the
 *      out-of-process FrozenJudge + SemanticImplementationCertifier never see it as evidence.
 *   2. RED-REQUIRED — every minted objective carries `acceptance.red_required=true`. A research idea that
 *      cannot be turned into a failing-test-first change earns NOTHING; the source's claim is not the proof.
 *
 * FAIL-CLOSED + default-OFF: with no search backend wired (today's default) `research()` returns
 * `researched=false`, so {@see originate} returns null and ZERO work is minted — never a silent fallback that
 * launders the bare topic into an objective. Pure aside from the injected research service (no DB/provider).
 * The search BACKEND and the refiller wiring are the next slices; this slice fixes the honest contract.
 */
final class AtlasLoopResearchOriginator
{
    public function __construct(
        private readonly ?AtlasLoopExternalResearchService $research = null,
    ) {
    }

    /**
     * The (config-gated, default-OFF) research backend. The loop's grind engine (Hermes) ships native
     * browser/web/web_extract tools, so arming `ATLAS_LOOP_RESEARCH_BACKEND_ENABLED` lets the grind research a
     * topic LIVE and prove the improvement with its OWN red change. Default-OFF keeps the whole chain
     * fail-closed until the operator arms the 24/7 regime.
     */
    private function service(): AtlasLoopExternalResearchService
    {
        return $this->research ?? new AtlasLoopExternalResearchService(
            (bool) config('atlas.loop.research_backend_enabled', false),
        );
    }

    /**
     * Turn a research topic into a RED-gated, source-quarantined objective candidate — or null when research
     * is blocked / unavailable (fail-closed) so nothing is ever invented from an unresearched topic.
     *
     * @param  list<string>  $allowedGlobs  the files the resulting change may touch (the frozen acceptance scope)
     * @return array{objective:string, shape:string, research_source:array{topic:string, note:string},
     *               source_quarantined:bool, acceptance:array{red_required:bool, allowed_globs:list<string>}}|null
     */
    public function originate(string $topic, string $repoRoot, array $allowedGlobs): ?array
    {
        $topic = trim($topic);
        if ($topic === '' || $allowedGlobs === []) {
            return null;
        }

        $verdict = $this->service()->research($topic, $repoRoot);
        // FAIL-CLOSED: egress-blocked or no backend ⇒ no objective. The bare topic is NEVER laundered through.
        if (($verdict['researched'] ?? false) !== true) {
            return null;
        }
        $note = trim((string) ($verdict['note'] ?? ''));
        if ($note === '') {
            return null;
        }

        return [
            'objective' => "Improve the target informed by external research on \"{$topic}\". The research note "
                ."is ADVISORY CONTEXT ONLY — it is NOT proof. You must establish the improvement with your own "
                ."failing-test-first (RED) change and let the frozen judge certify it. Research note: {$note}",
            'shape' => 'research',
            'research_source' => ['topic' => $topic, 'note' => $note],
            'source_quarantined' => true,
            'acceptance' => [
                'red_required' => true,
                'allowed_globs' => array_values($allowedGlobs),
            ],
        ];
    }
}
