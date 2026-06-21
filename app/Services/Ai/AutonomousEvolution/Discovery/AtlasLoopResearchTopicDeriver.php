<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §5.5 — the egress-safe research TOPIC producer (the missing trigger that makes the research live-path live).
 *
 * The loop's authoring step ({@see \App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator::generateForTarget})
 * already has an advisory EXTERNAL-RESEARCH slot ({@see \App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator}
 * ::withExternalResearchContext) that injects a research note INTO the prompt — but ONLY when
 * `options['research_topic']` is set, and NOTHING ever set it. This deriver is that producer: it turns a target's
 * work-SHAPE into a generalized, public engineering-concept topic the loop can research to author a STRONGER real
 * improvement. The research note guides AUTHORING; the obligation stays a genuinely RED-verified test on the real
 * target (no synthetic obligation — never the Goodhart "self-talk loop dressed as research").
 *
 * SOVEREIGNTY (two independent guards):
 *   1. BY CONSTRUCTION — the topic is drawn ONLY from a fixed shape→concept allow-list of public engineering
 *      phrases. It is NEVER built from a repo path, symbol, file content, diff, or any repo-specific datum.
 *   2. DEFENCE IN DEPTH — every derived topic is routed through the SAME deterministic egress filter the live
 *      pull uses ({@see AtlasLoopExternalResearchService::egressCheck}). If a careless future edit ever injected
 *      a repo path into the table, the filter REJECTS it and the deriver returns null (no topic, no research).
 *
 * FAIL-CLOSED / DEFAULT-OFF: gated `atlas.loop.research_authoring_enabled` (default-OFF) => returns null =>
 * `options()` returns [] => the generator's options are byte-identical to today. Pure, no DB, no provider.
 */
final class AtlasLoopResearchTopicDeriver
{
    /**
     * Fixed work-shape → generalized public engineering-concept topic. Values are public technique vocabulary
     * ONLY — never repo data. Keys mirror {@see AtlasLoopWorkShapeRouter} shapes.
     *
     * @var array<string,string>
     */
    private const SHAPE_CONCEPTS = [
        AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS => 'extract-class refactoring and god-class decomposition patterns in object-oriented PHP',
        AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE => 'cross-module refactoring, dependency inversion and coupling-reduction patterns',
        AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX => 'defensive programming, boundary conditions and edge-case handling patterns',
        AtlasLoopWorkShapeRouter::SHAPE_REFACTOR => 'behaviour-preserving refactoring and cyclomatic-complexity reduction patterns',
    ];

    /** When the shape is unknown/absent, fall back to a broad public concept (still egress-safe). */
    private const DEFAULT_CONCEPT = 'software design, refactoring and automated-testing best practices';

    public function __construct(private readonly ?AtlasLoopExternalResearchService $egress = null) {}

    /**
     * Derive an egress-safe research topic from a target's stamped signals, or null when the producer is OFF
     * or the derived topic somehow fails the egress filter (defence in depth).
     *
     * @param  array<string,mixed>  $signals  the target's stamped signals (shape, cyclomatic, …)
     * @param  string  $repoRoot  the real repo root the egress filter resolves candidate paths against
     */
    public function derive(array $signals, string $repoRoot): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $shape = is_string($signals['shape'] ?? null) ? trim((string) $signals['shape']) : '';
        $topic = self::SHAPE_CONCEPTS[$shape] ?? self::DEFAULT_CONCEPT;

        // DEFENCE IN DEPTH: route the fixed concept through the live pull's own egress filter. A clean public
        // concept always passes; a (future, accidental) repo-path injection would be REJECTED here => null.
        $verdict = ($this->egress ?? new AtlasLoopExternalResearchService)->egressCheck($topic, $repoRoot);
        if (($verdict['allowed'] ?? false) !== true) {
            return null;
        }

        return $topic;
    }

    /**
     * The research augmentation to MERGE into a generator's options: `{research_topic, repo_root}` when a topic
     * is derivable, else `[]` so the caller's options stay byte-identical when the producer is OFF.
     *
     * @param  array<string,mixed>  $signals
     * @return array{research_topic?:string, repo_root?:string}
     */
    public function options(array $signals, string $repoRoot): array
    {
        $topic = $this->derive($signals, $repoRoot);
        if ($topic === null) {
            return [];
        }

        return ['research_topic' => $topic, 'repo_root' => $repoRoot];
    }

    /** Read the gate defensively so a container-less caller never fatals (=> OFF). */
    private function enabled(): bool
    {
        try {
            return (bool) config('atlas.loop.research_authoring_enabled', false);
        } catch (\Throwable) {
            return false;
        }
    }
}
