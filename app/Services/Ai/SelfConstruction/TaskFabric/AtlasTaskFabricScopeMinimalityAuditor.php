<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure auditor: verifies that allowed_files are neither too broad nor too narrow.
 *
 * Overbroad files: bare directories (trailing /), wildcards (*), extensionless paths.
 * Missing required files: no implementation file (non-test .php in app/) or no test file.
 * Hidden self-targets: files present in both allowed_files and forbidden_files.
 * Unrelated sibling files an objective_symbols check would flag are exempted when the
 * caller supplies dependency_evidence for that file — a proven dependency, not a guess.
 *
 * scope_ok=true only when all four lists are empty.
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

        // Unrelated-file bloat: when objective_symbols is supplied (opt-in — legacy callers
        // that never pass it keep prior behavior unchanged), an allowed file whose basename
        // matches none of the declared symbols is scope bloat a muscle would have to read
        // through for no reason, without ever removing the implementation/test pair itself.
        $dependencyEvidence = array_values(array_map('strval', (array) ($spec['dependency_evidence'] ?? [])));

        // AC2: required collaborator files — when acceptance or objective references a concrete
        // helper/caller file, allowed_files must include it or report a missing_collaborator_file.
        $requiredCollaborators = array_values(array_map('strval', (array) ($spec['required_collaborator_files'] ?? [])));
        foreach ($requiredCollaborators as $collaborator) {
            if ($collaborator === '') {
                continue;
            }
            if (! in_array($collaborator, $allowed, true)) {
                $missingRequired[] = 'missing_collaborator_file:'.$collaborator;
            }
        }

        $unrelatedFiles = [];
        $objectiveSymbols = array_key_exists('objective_symbols', $spec)
            ? array_values(array_map('strval', (array) $spec['objective_symbols']))
            : null;
        if ($objectiveSymbols !== null && $objectiveSymbols !== []) {
            foreach ($allowed as $file) {
                if (in_array($file, $overbroadFiles, true) || in_array($file, $dependencyEvidence, true)) {
                    continue;
                }
                $base = basename($file, '.php');
                $base = preg_replace('/Test$/', '', $base) ?? $base;
                $matches = false;
                foreach ($objectiveSymbols as $symbol) {
                    if (str_contains($symbol, $base) || str_contains($base, $symbol)) {
                        $matches = true;

                        break;
                    }
                }
                if (! $matches) {
                    $unrelatedFiles[] = $file;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'scope_ok' => $hiddenSelfTargets === [] && $overbroadFiles === [] && $missingRequired === [] && $unrelatedFiles === [],
            'missing_required_files' => $missingRequired,
            'overbroad_files' => $overbroadFiles,
            'overbroad_allowed_files' => $overbroadFiles,
            'hidden_self_target_flags' => $hiddenSelfTargets,
            'unrelated_files' => $unrelatedFiles,
            'allowed_files_count' => count($allowed),
        ];
    }
}
