<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * EXTERNAL BRAIN · the SCOPE AUTHORITY. Resolves a scope slug into the concrete block the brain may comprehend
 * and evolve: the comprehension ROOTS (one or more subtrees), the canonical DOC roots, and the meta_harness
 * decision (may the brain evolve the autonomous engine itself, or only non-harness code?).
 *
 * The scope is DATA (config('atlas.brain.scopes')), not hard-code — so the operator defines/extends scopes
 * (autonomous, cortex, maestro, …) without touching the brain commands. config/atlas.php is pétreo, and this
 * resolver is pétreo (FORBIDDEN_SELF_TARGETS): the brain can never widen its own reach nor self-arm meta_harness.
 *
 * Pure: no provider, no DB, no mutation. A function of config + the requested slug.
 */
final class AtlasBrainScopeRegistry
{
    public const SCHEMA_VERSION = 'atlas.brain.scope_registry.v1';

    /** The safe fallback root if config defines no scopes — the brain's own loop substrate. */
    private const FALLBACK_ROOT = 'app/Services/Ai/AutonomousEvolution';

    /**
     * Resolve a scope slug into its definition. An unknown slug falls back to the default scope; a default with
     * no roots falls back to the loop substrate with meta_harness OFF (fail-closed: never auto-arm meta).
     *
     * @return array{slug:string, label:string, roots:list<string>, docs_roots:list<string>, meta_harness:bool}
     */
    public function resolve(string $scope): array
    {
        $scopes = (array) config('atlas.brain.scopes', []);
        $requested = trim($scope);
        $slug = ($requested !== '' && isset($scopes[$requested])) ? $requested : $this->defaultScope();

        $def = is_array($scopes[$slug] ?? null) ? $scopes[$slug] : [];

        $roots = $this->stringList($def['roots'] ?? []);
        $docsRoots = $this->stringList($def['docs_roots'] ?? []);

        return [
            'slug' => $slug,
            'label' => (string) ($def['label'] ?? $slug),
            'roots' => $roots !== [] ? $roots : [self::FALLBACK_ROOT],
            'docs_roots' => $docsRoots,
            'meta_harness' => (bool) ($def['meta_harness'] ?? false),
        ];
    }

    /** The configured default scope slug (the one the brain evolves when none is named). */
    public function defaultScope(): string
    {
        $default = trim((string) config('atlas.brain.default_scope', ''));

        return $default !== '' ? $default : 'autonomous';
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) $value),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
