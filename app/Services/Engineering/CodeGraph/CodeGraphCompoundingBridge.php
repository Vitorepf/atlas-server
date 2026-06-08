<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Compounding bridge for the Atlas Code Graph (AP-811/AP-812 M-6).
 *
 * Turns read-model graph insights — degree-centrality god-nodes
 * ({@see CodeGraphAnalytics::godNodes()}) and surprising connections
 * ({@see \Atlas code_graph insights.surprising_connections}) — into MEMORY
 * REVIEW candidates, matching Atlas's proposal-only memory governance
 * (runtime_promotion_policy.v1): the graph is a read model that FEEDS, it never
 * DECIDES. Every candidate is emitted as a proposal for a human to accept or
 * reject — NOTHING here is ever auto-promoted to memory.
 *
 * Hard governance invariant (asserted by the test): on every emitted candidate
 *   promotion_allowed === false  AND  requires_review === true
 * There is no code path, parameter or branch that can flip these. The bridge is
 * a pure transform: no DB, no IO, no provider, no clock — same family as
 * {@see CodeGraphEdgeResolver} and {@see CodeGraphAnalytics}.
 *
 * Output candidate shape (mirrors the proposal-only fields the existing
 * {@see \App\Models\AiLearningCandidate} model already governs):
 *   {
 *     candidate_type: 'code_graph_insight',
 *     summary: string,            // human-readable headline of the insight
 *     basis: string,              // why this insight was surfaced (auditable)
 *     promotion_allowed: false,   // INVARIANT — never auto-promote
 *     requires_review: true,      // INVARIANT — always human-gated
 *     metadata: {...},            // structured detail (kind + raw signal)
 *   }
 */
class CodeGraphCompoundingBridge
{
    public const SCHEMA = 'atlas.code_graph.compounding_bridge.v1';

    public const CANDIDATE_TYPE = 'code_graph_insight';

    public const KIND_GOD_NODE = 'god_node';
    public const KIND_SURPRISE = 'surprising_connection';

    /**
     * Build memory review candidates from graph insights. Proposal-only:
     * promotion_allowed is hard-false and requires_review is hard-true on every
     * returned candidate.
     *
     * @param  array<int,array<string,mixed>>  $godNodes  CodeGraphAnalytics::godNodes()['god_nodes']
     *   entries: {node_id:string, degree:int, in_degree?:int, out_degree?:int}
     * @param  array<int,array<string,mixed>>  $surprises  surprising_connections(...) entries:
     *   {from_node_id:string, to_node_id:string, score:float, why?:array<int,string>}
     * @param  int  $maxPerKind  cap per insight kind so a huge graph cannot flood the review queue
     * @return array<int,array{candidate_type:string,summary:string,basis:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}>
     */
    public function toMemoryCandidates(array $godNodes, array $surprises, int $maxPerKind = 10): array
    {
        $cap = max(1, $maxPerKind);
        $candidates = [];

        $godCount = 0;
        foreach ($godNodes as $node) {
            if ($godCount >= $cap) {
                break;
            }
            $candidate = $this->godNodeCandidate($node);
            if ($candidate !== null) {
                $candidates[] = $candidate;
                $godCount++;
            }
        }

        $surpriseCount = 0;
        foreach ($surprises as $surprise) {
            if ($surpriseCount >= $cap) {
                break;
            }
            $candidate = $this->surpriseCandidate($surprise);
            if ($candidate !== null) {
                $candidates[] = $candidate;
                $surpriseCount++;
            }
        }

        return $candidates;
    }

    /**
     * @param  mixed  $node
     * @return array{candidate_type:string,summary:string,basis:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}|null
     */
    private function godNodeCandidate(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        $nodeId = $this->stringOrNull($node['node_id'] ?? null);
        if ($nodeId === null) {
            return null;
        }

        $degree = $this->intOrZero($node['degree'] ?? null);
        $inDegree = $this->intOrZero($node['in_degree'] ?? null);
        $outDegree = $this->intOrZero($node['out_degree'] ?? null);

        $summary = sprintf(
            'Architectural hub: %s (degree %d) is a high-connectivity god-node; changes here carry wide blast radius.',
            $nodeId,
            $degree,
        );
        $basis = sprintf(
            'code-graph degree centrality: in_degree=%d, out_degree=%d, total_degree=%d',
            $inDegree,
            $outDegree,
            $degree,
        );

        return $this->candidate(
            kind: self::KIND_GOD_NODE,
            summary: $summary,
            basis: $basis,
            signal: [
                'node_id' => $nodeId,
                'degree' => $degree,
                'in_degree' => $inDegree,
                'out_degree' => $outDegree,
            ],
        );
    }

    /**
     * @param  mixed  $surprise
     * @return array{candidate_type:string,summary:string,basis:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}|null
     */
    private function surpriseCandidate(mixed $surprise): ?array
    {
        if (! is_array($surprise)) {
            return null;
        }
        $from = $this->stringOrNull($surprise['from_node_id'] ?? null);
        $to = $this->stringOrNull($surprise['to_node_id'] ?? null);
        if ($from === null || $to === null) {
            return null;
        }

        $score = $this->floatOrZero($surprise['score'] ?? null);
        $why = $this->reasonList($surprise['why'] ?? null);
        $whyText = $why === [] ? 'no explicit reasons recorded' : implode('; ', $why);

        $summary = sprintf(
            'Surprising connection: %s -> %s (surprise score %s) — unexpected coupling worth a reviewer look.',
            $from,
            $to,
            $this->formatScore($score),
        );
        $basis = sprintf('code-graph surprise signal: %s', $whyText);

        return $this->candidate(
            kind: self::KIND_SURPRISE,
            summary: $summary,
            basis: $basis,
            signal: [
                'from_node_id' => $from,
                'to_node_id' => $to,
                'score' => $score,
                'why' => $why,
            ],
        );
    }

    /**
     * Single construction point for the candidate shape. promotion_allowed and
     * requires_review are written as literals here and nowhere else, so the
     * proposal-only invariant cannot be parameterised away.
     *
     * @param  array<string,mixed>  $signal
     * @return array{candidate_type:string,summary:string,basis:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}
     */
    private function candidate(string $kind, string $summary, string $basis, array $signal): array
    {
        return [
            'candidate_type' => self::CANDIDATE_TYPE,
            'summary' => $summary,
            'basis' => $basis,
            // INVARIANT: graph insights are a read model — they FEED, never DECIDE.
            'promotion_allowed' => false,
            'requires_review' => true,
            'metadata' => [
                'bridge' => self::SCHEMA,
                'insight_kind' => $kind,
                'signal' => $signal,
                // Restated inside metadata so any consumer that reads only the
                // metadata blob still sees the proposal-only governance.
                'promotion_allowed' => false,
                'requires_review' => true,
            ],
        ];
    }

    /**
     * @param  mixed  $why
     * @return array<int,string>
     */
    private function reasonList(mixed $why): array
    {
        if (! is_array($why)) {
            return [];
        }
        $reasons = [];
        foreach ($why as $reason) {
            $value = $this->stringOrNull($reason);
            if ($value !== null) {
                $reasons[] = $value;
            }
        }

        return $reasons;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function floatOrZero(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function formatScore(float $score): string
    {
        $rounded = round($score, 4);

        // Render whole numbers without a trailing ".0" for clean summaries.
        return $rounded == (int) $rounded
            ? (string) (int) $rounded
            : rtrim(rtrim(number_format($rounded, 4, '.', ''), '0'), '.');
    }
}
