<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Pure string rules for Self-Construction naming policy.
 *
 * No FS, DI, or I/O — only path/class-name predicates used by
 * {@see \App\Services\Ai\SelfConstruction\NamingPolicy\AtlasSelfConstructionNamingPolicyGate}.
 */
final class NamingPolicyRules
{
    /**
     * @var non-empty-string
     */
    public const TARGET_ROOT = 'app/Services/Ai/SelfConstruction';

    /**
     * Bare, low-signal class-name stems (a generated file whose ENTIRE name is one of these words)
     * — a "slop" name that carries no responsibility information of its own.
     *
     * @var list<string>
     */
    public const VAGUE_NAME_STEMS = [
        'Helper', 'Manager', 'Handler', 'Util', 'Utils', 'Impl', 'Temp', 'Draft',
        'Copy', 'Final', 'Misc', 'Generic', 'New', 'Thing', 'Stuff', 'Data',
    ];

    /**
     * Class-name suffixes that belong to a DIFFERENT architectural layer (HTTP controllers,
     * DB migrations, Eloquent models, middleware) — these must never live under the pure
     * Self-Construction service tree.
     *
     * @var list<string>
     */
    public const BOUNDARY_FORBIDDEN_SUFFIXES = [
        'Controller', 'Migration', 'Model', 'Middleware', 'Kernel', 'ServiceProvider',
    ];

    private function __construct()
    {
    }

    public static function isInScope(string $relativePath): bool
    {
        $normalized = ltrim($relativePath, './');

        return str_starts_with($normalized, self::TARGET_ROOT.'/')
            && str_ends_with($normalized, '.php');
    }

    public static function extractFamily(string $className): string
    {
        // Family = last PascalCase word (e.g. "Service", "Gate", "Policy").
        if (preg_match('/([A-Z][a-z]+)$/', $className, $m) === 1) {
            return $m[1];
        }

        return $className;
    }

    public static function isVagueGeneratedName(string $className): bool
    {
        return in_array($className, self::VAGUE_NAME_STEMS, true);
    }

    public static function hasForbiddenQuarantineName(string $className, string $relativePath): bool
    {
        if (stripos($className, 'quarantine') === false) {
            return false;
        }

        return ! str_contains($relativePath, '/_quarantine/');
    }

    public static function violatesLayerBoundary(string $className): bool
    {
        foreach (self::BOUNDARY_FORBIDDEN_SUFFIXES as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip a trailing version/duplicate marker (V2, 2, Copy, New) so "FooReducer" and
     * "FooReducer2" collapse to the same concept for duplicate detection.
     */
    public static function conceptStem(string $className): string
    {
        return preg_replace('/(V?\d+|Copy|New)$/', '', $className) ?? $className;
    }
}
