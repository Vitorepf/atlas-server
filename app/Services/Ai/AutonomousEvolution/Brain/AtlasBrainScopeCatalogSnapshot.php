<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * SCOPE CATALOG SNAPSHOT — read-only enumeration of configured scopes with per-scope shape: slug,
 * meta_harness flag, roots count, label. Lets operator see "what scopes does the brain know about?"
 * without grepping config files. Pure + deterministic + read-only. Pétreo.
 */
final class AtlasBrainScopeCatalogSnapshot
{
    public const SCHEMA = 'atlas.brain.scope_catalog_snapshot.v1';

    /**
     * @return array{schema:string, count:int, default_scope:string, scopes:list<array{slug:string, label:string, meta_harness:bool, roots_count:int, docs_roots_count:int}>}
     */
    public function snapshot(): array
    {
        $configured = (array) config('atlas.brain.scopes', []);
        $scopes = [];
        foreach ($configured as $slug => $def) {
            $def = (array) $def;
            $scopes[] = [
                'slug' => (string) $slug,
                'label' => (string) ($def['label'] ?? ''),
                'meta_harness' => (bool) ($def['meta_harness'] ?? false),
                'roots_count' => count((array) ($def['roots'] ?? [])),
                'docs_roots_count' => count((array) ($def['docs_roots'] ?? [])),
            ];
        }
        usort($scopes, static fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

        return [
            'schema' => self::SCHEMA,
            'count' => count($scopes),
            'default_scope' => (string) config('atlas.brain.default_scope', 'loop'),
            'scopes' => $scopes,
        ];
    }
}
