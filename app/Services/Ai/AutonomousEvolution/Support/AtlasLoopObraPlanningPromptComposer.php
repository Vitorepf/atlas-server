<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Support;

/**
 * ITEM8 — the cohesive prompt/JSON helpers the obra execution adapter feeds to its spec/DAG provider seams.
 *
 * Three methods (specPrompt, dagPrompt, decodeJsonObject) are the ONLY places the adapter turns the goal +
 * prior-gaps into a deterministic JSON-only prompt and parses the provider's response back into an object.
 * Pure / stateless / zero Laravel surface — extracted so the adapter can split cohesive prompt-composition
 * logic out of its public signature without changing ANY caller-visible byte.
 *
 * Invariants (preserved verbatim from the god-class):
 *  - prompts are JSON-only (no prose, no markdown fence) so the decoder can rely on a single shape;
 *  - dagPrompt lists EXISTING files only (create-class nodes are Atlas-emitted, never provider-emitted);
 *  - decodeJsonObject is tolerant of a prose wrap or ```json fence (extracts the outermost {...}) but
 *    still returns null on any non-object payload — fail-OPEN.
 */
class AtlasLoopObraPlanningPromptComposer
{
    /** @param  list<string>  $priorGaps */
    public function specPrompt(string $goal, array $priorGaps): string
    {
        $fix = $priorGaps === [] ? '' : "\n\nThe previous spec had these gaps; FIX them: ".implode(', ', array_slice($priorGaps, 0, 8));

        return 'Produce ONLY a single deterministic JSON object (no prose, no markdown fence) for this engineering goal.'
            ."\nGoal: ".$goal
            ."\nShape: {\"summary\": string, \"acceptance_criteria\": [{\"id\": string, \"description\": string (>=15 chars), \"required\": bool}], "
            .'"suggested_files": [string], "decomposition_hint": string}'
            ."\nAt least one acceptance criterion must be required. suggested_files lists any NEW files the change introduces."
            .$fix;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  list<string>  $priorGaps
     */
    public function dagPrompt(string $goal, array $context, array $priorGaps): string
    {
        $hint = trim((string) ($context['decomposition_hint'] ?? ''));
        $newFiles = implode(', ', array_filter((array) ($context['suggested_files'] ?? []), 'is_string'));
        $fix = $priorGaps === [] ? '' : "\n\nThe previous DAG had these gaps; FIX them: ".implode(', ', array_slice($priorGaps, 0, 8));

        return 'Produce ONLY a single deterministic JSON object (no prose, no markdown fence) decomposing this goal into a node DAG.'
            ."\nGoal: ".$goal
            .($hint !== '' ? "\nDecomposition hint: ".$hint : '')
            .($newFiles !== '' ? "\nNew files to create: ".$newFiles : '')
            ."\nShape: {\"plan_id\": string, \"nodes\": [{\"id\": string, \"target_area\": string (a file path), \"request\": string (concrete, references its file)}]}"
            ."\nDo NOT include create-class nodes for the new files — Atlas emits those. List only the EXISTING files to edit/redirect."
            .$fix;
    }

    /**
     * Decode a provider response into a JSON object. Tolerant of a leading/trailing prose wrap or a single
     * ```json fence (extract the outermost {...}); returns null on anything non-object.
     *
     * @return array<string,mixed>|null
     */
    public function decodeJsonObject(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
