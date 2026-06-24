<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Cortex;

/**
 * The PUBLIC, PORTABLE interface of Atlas Cortex v+infinity. A consumer holding a {@see self} can ask any
 * Cortex backend to comprehend a repository and receive a canonical FACTS array — without depending on any
 * Atlas-internal class, Laravel container type, Eloquent model, or storage path.
 *
 * The contract has exactly two methods:
 *   - {@see comprehend()} : given a repo root + a free-form config array, return canonical FACTS
 *                           (inventory, orphans, clones, forbidden hits, doc-stated gaps, per-unit
 *                           level_vector, named transitions). NEVER a score (pétreo invariant).
 *   - {@see contractSchemaId()} : returns the canonical schema id (currently the placeholder
 *                                 `atlas.cortex.facts.v1`) that downstream schemas anchor on.
 *
 * Parameter + return types are deliberately limited to PHP scalar/array primitives so this interface stays
 * portable across backends. No Laravel facade type, no Eloquent type, no Atlas-internal type leaks through.
 */
interface AtlasCortexUniversalContract
{
    /**
     * @param  array<string,mixed>  $config  free-form backend config (e.g. scope_root, axis, opts)
     * @return array<string,mixed>           canonical FACTS payload
     */
    public function comprehend(string $repoRoot, array $config): array;

    /**
     * @return string  the canonical schema id of the returned facts (e.g. "atlas.cortex.facts.v1")
     */
    public function contractSchemaId(): string;
}
