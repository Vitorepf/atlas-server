<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Renders resolved code-graph edges (AP-811/AP-812) as a deterministic Mermaid
 * `flowchart LR` diagram for human inspection.
 *
 * Pure transform — no DB, no IO, no provider, no Python runtime. This is a
 * read-model projection: it never decides anything, it only draws what the
 * resolver already produced ({@see CodeGraphEdgeResolver}). It feeds a surface,
 * it does not feed the brain.
 *
 * Edge styling follows the resolver's confidence contract:
 *  - EXTRACTED edges (a real import/relation) render solid: `-->`.
 *  - everything else (INFERRED / AMBIGUOUS / unknown) renders dashed: `-.->`.
 *
 * Determinism: edges are sorted by (from, to, type) before rendering and node
 * declarations are emitted in first-seen order of the sorted edge list, so the
 * same input always yields byte-identical output. Output is capped at
 * `$maxEdges` with an explicit `%% omitted N for readability` comment so a large
 * graph degrades into a readable subgraph instead of an unreadable wall.
 */
class CodeGraphMermaidExporter
{
    public const SCHEMA = 'atlas.code_graph.mermaid.v1';

    private const HEADER = 'flowchart LR';

    private const ARROW_SOLID = '-->';

    private const ARROW_DASHED = '-.->';

    /**
     * Build a Mermaid `flowchart LR` string from resolved edges.
     *
     * @param  array<int,array<string,mixed>>  $edges  resolver edges:
     *   each = {from_node_id, to_node_id, edge_type?, confidence?, confidence_score?, metadata?}
     * @param  int  $maxEdges  hard cap on rendered edges (>= 1); excess is summarised
     */
    public function toMermaid(array $edges, int $maxEdges = 40): string
    {
        $maxEdges = max(1, $maxEdges);

        // Normalise + drop edges missing either endpoint (never invent nodes).
        $clean = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $from = $this->stringOrNull($edge['from_node_id'] ?? null);
            $to = $this->stringOrNull($edge['to_node_id'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }
            $clean[] = [
                'from' => $from,
                'to' => $to,
                'type' => $this->edgeType($edge['edge_type'] ?? null),
                'extracted' => $this->isExtracted($edge),
            ];
        }

        // Deterministic order: (from, to, type).
        usort(
            $clean,
            static fn (array $a, array $b): int => [$a['from'], $a['to'], $a['type']]
                <=> [$b['from'], $b['to'], $b['type']],
        );

        $total = count($clean);
        $omitted = max(0, $total - $maxEdges);
        $rendered = array_slice($clean, 0, $maxEdges);

        // Node declarations in first-seen order of the sorted, rendered edges.
        /** @var array<string,string> $nodeIds original label => sanitized id */
        $nodeIds = [];
        $nodeLines = [];
        foreach ($rendered as $edge) {
            foreach ([$edge['from'], $edge['to']] as $label) {
                if (isset($nodeIds[$label])) {
                    continue;
                }
                $id = $this->nodeId($label, $nodeIds);
                $nodeIds[$label] = $id;
                $nodeLines[] = '    '.$id.'["'.$this->escapeLabel($label).'"]';
            }
        }

        $edgeLines = [];
        foreach ($rendered as $edge) {
            $fromId = $nodeIds[$edge['from']];
            $toId = $nodeIds[$edge['to']];
            $arrow = $edge['extracted'] ? self::ARROW_SOLID : self::ARROW_DASHED;
            $edgeLines[] = '    '.$fromId.' '.$arrow.'|'.$this->escapeLabel($edge['type']).'| '.$toId;
        }

        $lines = [self::HEADER];
        foreach ($nodeLines as $nodeLine) {
            $lines[] = $nodeLine;
        }
        foreach ($edgeLines as $edgeLine) {
            $lines[] = $edgeLine;
        }
        if ($omitted > 0) {
            $lines[] = '    %% omitted '.$omitted.' for readability';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * EXTRACTED when the explicit confidence label says so. We do NOT promote on
     * score alone: a missing/garbled label stays dashed (honest under-claim).
     *
     * @param  array<string,mixed>  $edge
     */
    private function isExtracted(array $edge): bool
    {
        $confidence = $this->stringOrNull($edge['confidence'] ?? null);
        if ($confidence === null) {
            $meta = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
            $confidence = $this->stringOrNull($meta['confidence'] ?? null);
        }

        return $confidence !== null
            && strtoupper($confidence) === CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED;
    }

    /**
     * Map an arbitrary node label to a Mermaid-safe id (alnum/_ only), keeping
     * it stable and collision-free within one render.
     *
     * @param  array<string,string>  $taken  label => id already assigned
     */
    private function nodeId(string $label, array $taken): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]+/', '_', $label) ?? '';
        $base = trim($base, '_');
        if ($base === '' || ! ctype_alpha($base[0]) && $base[0] !== '_') {
            $base = 'n_'.$base;
        }
        $base = rtrim($base, '_');
        if ($base === '') {
            $base = 'n';
        }

        $assigned = array_flip($taken);
        $candidate = $base;
        $suffix = 2;
        while (isset($assigned[$candidate])) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function escapeLabel(string $value): string
    {
        // Quotes would break the bracketed label / edge text; collapse to safe chars.
        $value = str_replace(['"', '|', "\n", "\r"], ["'", '/', ' ', ' '], $value);

        return trim($value);
    }

    private function edgeType(mixed $value): string
    {
        $type = $this->stringOrNull($value);

        return $type ?? 'depends_on';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
