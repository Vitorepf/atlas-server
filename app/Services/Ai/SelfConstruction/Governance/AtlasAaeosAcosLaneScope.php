<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

/**
 * AAEOS+ACOS elite simplification lane — path allowlist + scope inference.
 *
 * Pure: no I/O. Used by governance overrides and the simplify-cycle planner so
 * refactor_proof enforce applies only when the task mutates AAEOS/ACOS roots.
 */
final class AtlasAaeosAcosLaneScope
{
    public const SLUG = 'aaeos_acos';

    public const SCHEMA = 'atlas.aaeos_acos.lane_scope.v1';

    /** @var list<string> */
    public const CODE_ROOTS = [
        'app/Services/Ai/Aaeos',
        'app/Services/Ai/AgenticEngineeringOs',
        'app/Services/Ai/AcosMax',
        'app/Services/Ai/Cognition',
    ];

    /** @var list<string> */
    public const DOCS_ROOTS = [
        'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md',
        'docs/engineering-knowledge-base/atlas-cognition-operating-system.md',
        'docs/engineering-knowledge-base/atlas-acos-areas-map.md',
        'docs/engineering-knowledge-base/atlas-aaeos-acos-elite-simplify-lane.md',
    ];

    /**
     * Infer lane slug when every production (non-test) allowed file lives under
     * AAEOS/ACOS code roots. Empty production set ⇒ not this lane.
     *
     * @param  list<string>|array<int,string>  $allowedFiles
     */
    public static function inferFromAllowedFiles(array $allowedFiles): ?string
    {
        $production = [];
        foreach ($allowedFiles as $file) {
            $path = str_replace('\\', '/', trim((string) $file));
            if ($path === '') {
                continue;
            }
            if (str_starts_with($path, 'tests/') || str_contains($path, '/tests/') || str_ends_with($path, 'Test.php')) {
                continue;
            }
            $production[] = $path;
        }

        if ($production === []) {
            return null;
        }

        foreach ($production as $path) {
            if (! self::pathInCodeRoots($path)) {
                return null;
            }
        }

        return self::SLUG;
    }

    public static function pathInCodeRoots(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        foreach (self::CODE_ROOTS as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{slug:string, schema_version:string, code_roots:list<string>, docs_roots:list<string>}
     */
    public static function definition(): array
    {
        return [
            'slug' => self::SLUG,
            'schema_version' => self::SCHEMA,
            'code_roots' => self::CODE_ROOTS,
            'docs_roots' => self::DOCS_ROOTS,
        ];
    }
}
