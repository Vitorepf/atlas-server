<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure auditor: verifies that allowed_files are neither too broad nor too narrow.
 *
 * Overbroad files: bare directories (trailing /), wildcards (*), extensionless paths.
 * Missing required files: no implementation file (non-test .php in app/) or no test file.
 * Hidden self-targets: files present in both allowed_files and forbidden_files.
 *
 * scope_ok=true only when all three lists are empty.
 */
final class AtlasTaskFabricScopeMinimalityAuditor
{
    public const SCHEMA = 'atlas.task_fabric.scope_minimality_auditor.v1';

    /**
     * @param  array<string,mixed>  $spec  allowed_files, forbidden_files, objective_symbols, acceptance_commands
     * @return array<string,mixed>
     */
    public function audit(array $spec): array
    {
        $allowed = array_values(array_filter(
            array_map('strval', (array) ($spec['allowed_files'] ?? [])),
            static fn (string $f): bool => $f !== '',
        ));
        $forbidden = array_values(array_map('strval', (array) ($spec['forbidden_files'] ?? [])));

        $hiddenSelfTargets = array_values(array_intersect($allowed, $forbidden));

        $overbroadFiles = array_values(array_filter(
            $allowed,
            static fn (string $f): bool => str_ends_with($f, '/')
                || str_contains($f, '*')
                || ! str_contains(basename($f), '.'),
        ));

        $missingRequired = [];

        $hasImpl = array_filter(
            $allowed,
            static fn (string $f): bool => ! str_contains($f, 'Test.php')
                && ! str_contains($f, '/tests/')
                && ! str_ends_with($f, '/'),
        ) !== [];

        $hasTest = array_filter(
            $allowed,
            static fn (string $f): bool => str_contains($f, 'Test.php') || str_contains($f, '/tests/'),
        ) !== [];

        if (! $hasImpl) {
            $missingRequired[] = 'implementation_file';
        }
        if (! $hasTest) {
            $missingRequired[] = 'test_file';
        }

        return [
            'schema_version' => self::SCHEMA,
            'scope_ok' => $hiddenSelfTargets === [] && $overbroadFiles === [] && $missingRequired === [],
            'missing_required_files' => $missingRequired,
            'overbroad_files' => $overbroadFiles,
            'hidden_self_target_flags' => $hiddenSelfTargets,
            'allowed_files_count' => count($allowed),
        ];
    }
}
