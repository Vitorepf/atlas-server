<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\BehaviorDelta;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;

/**
 * BEHAVIOR-Δ SNAPSHOTTER — a pure, deterministic capture of a scope's BEHAVIOR SURFACE at a commit: the public
 * API signature of every symbol + who calls it. This is the raw material a later behavior-Δ computer diffs
 * (then the operator wires it into the pétreo AtlasLoopUtilityGradeService — NOT here).
 *
 * FACTS, never a score: {schema, captured_at, symbols:[{fqcn, public_api_signature_hash, caller_fqcns}],
 * api_surface_hash}. Same (repoRoot, scopeRoot) on an unchanged scope ⇒ BYTE-IDENTICAL output: every list is
 * sorted and captured_at is the scope's latest source mtime (a deterministic "as-of", not a wall clock).
 *
 * It performs ZERO writes, runs NO git mutation, calls NO provider/network: it delegates to the read-only,
 * deterministic public API of {@see AtlasLoopScopeComprehensionModelBuilder} (which the operator forbade us to
 * edit) for the symbol inventory + caller edges, and only hashes the result.
 */
final class AtlasLoopBehaviorDeltaSnapshotter
{
    public const SCHEMA = 'atlas.loop.behavior_delta_snapshot.v1';

    /**
     * @return array{schema:string, captured_at:string, symbols:list<array{fqcn:string, public_api_signature_hash:string, caller_fqcns:list<string>}>, api_surface_hash:string}
     */
    public function snapshot(string $repoRoot, string $scopeRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $model = (new AtlasLoopScopeComprehensionModelBuilder)->build($repoRoot, $scopeRoot);

        $symbols = [];
        $maxMtime = 0;
        foreach ($model->inventory as $item) {
            if (! is_array($item)) {
                continue;
            }
            $relPath = (string) ($item['rel_path'] ?? '');
            $fqcn = ltrim((string) ($item['fqcn'] ?? ''), '\\');
            if ($fqcn === '') {
                continue;
            }

            // Public API surface = the sorted public method names hashed (a rename/add/remove flips the hash).
            $publicMethods = array_values(array_filter((array) ($item['public_methods'] ?? []), 'is_string'));
            sort($publicMethods, SORT_STRING);
            $signatureHash = substr(hash('sha256', (string) json_encode($publicMethods, JSON_UNESCAPED_SLASHES)), 0, 40);

            // Callers as fqcns (the measured production caller rel-paths mapped through the inventory; an
            // out-of-scope caller that has no inventoried fqcn keeps its rel-path as a stable identifier).
            $callers = [];
            foreach ((array) ($model->callerPathsFor($relPath) ?? []) as $callerPath) {
                $callerPath = (string) $callerPath;
                $callers[] = ltrim((string) ($model->fqcnForPath($callerPath) ?? $callerPath), '\\');
            }
            $callers = array_values(array_unique($callers));
            sort($callers, SORT_STRING);

            $symbols[] = [
                'fqcn' => $fqcn,
                'public_api_signature_hash' => $signatureHash,
                'caller_fqcns' => $callers,
            ];

            $abs = $repoRoot.'/'.ltrim($relPath, '/');
            if ($relPath !== '' && is_file($abs)) {
                $mtime = @filemtime($abs);
                if ($mtime !== false && $mtime > $maxMtime) {
                    $maxMtime = $mtime;
                }
            }
        }

        // Deterministic order: sort symbols by fqcn so the surface is stable regardless of inventory order.
        usort($symbols, static fn (array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));

        $apiSurfaceHash = substr(hash('sha256', (string) json_encode(
            array_map(static fn (array $s): array => ['fqcn' => $s['fqcn'], 'sig' => $s['public_api_signature_hash']], $symbols),
            JSON_UNESCAPED_SLASHES,
        )), 0, 40);

        return [
            'schema' => self::SCHEMA,
            // Deterministic "as-of": the latest source mtime in the scope (UTC ISO-8601). No wall clock, no git.
            'captured_at' => $maxMtime > 0 ? gmdate('Y-m-d\TH:i:s\Z', $maxMtime) : '1970-01-01T00:00:00Z',
            'symbols' => $symbols,
            'api_surface_hash' => $apiSurfaceHash,
        ];
    }
}
