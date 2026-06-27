<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Console\Commands\AtlasTaskSeedGovLanesCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;

/**
 * Translates the output of {@see AtlasLoopOriginationPipeline::produce()}
 * into the EXACT seed-gov-lanes packet spec consumed by
 * {@see AtlasTaskSeedGovLanesCommand}.
 *
 * CRITICAL INVARIANT: allowed_files comes ONLY from target_path + obligation file
 * references — NEVER an invented path. If an obligation carries no file path, it
 * contributes nothing to allowed_files.
 *
 * Pure: no provider, no DB, no mutation. Deterministic.
 */
final class AtlasBrainTaskSpecTranslator
{
    public const SCHEMA_VERSION = 'atlas.brain.task_spec_translator.v1';

    /**
     * @param  array{objective:?string, target_path:?string, obligations:list<array<string,mixed>>, snapshot_id?:string}  $origination
     * @return array{task_packet_id:string, objective:string, allowed_files:list<string>, scope_in:list<string>,
     *               acceptance_criteria:list<string>, evidence_requirements:list<string>,
     *               depends_on:list<string>, wave:int, risk_level:string}
     */
    public function translate(array $origination): array
    {
        $objective = trim((string) ($origination['objective'] ?? ''));
        $targetPath = ltrim(trim((string) ($origination['target_path'] ?? '')), '/');
        $obligations = array_values(array_filter(
            (array) ($origination['obligations'] ?? []),
            static fn ($o): bool => is_array($o),
        ));
        $snapshotId = trim((string) ($origination['snapshot_id'] ?? ''));

        // allowed_files = target_path + every file named in obligations. NEVER invent a path.
        $allowedFiles = [];
        if ($targetPath !== '' && $this->isSafePath($targetPath)) {
            $allowedFiles[$targetPath] = true;
        }
        foreach ($obligations as $obligation) {
            foreach ($this->extractFilePaths($obligation) as $path) {
                if ($this->isSafePath($path)) {
                    $allowedFiles[$path] = true;
                }
            }
        }
        // S4-narrow GRANT TEST PATH: a packet whose target lives under app/ but ships no test-authoring
        // obligation strands the worker — required_evidence:tests_or_gates_result + test-shaped acceptance
        // hit `test_evidence_without_test_in_allowed_files` (the seed-quality BLOCKING deficiency) and the
        // brain refuses its own valid origination. Mirroring `app/X/Y/Foo.php` to `tests/Unit/X/Y/FooTest.php`
        // closes the asymmetry the memory `brain-9_3-campaign` named as the qualidade/constância bug.
        // Skipped when the obligations already supply a tests/ path (no double-grant, no override).
        if ($targetPath !== '' && $this->isSafePath($targetPath) && ! $this->anyTestPath(array_keys($allowedFiles))) {
            $mirror = $this->mirrorTestPathFor($targetPath);
            if ($mirror !== null && $this->isSafePath($mirror)) {
                $allowedFiles[$mirror] = true;
            }
        }
        $allowedFilesList = array_values(array_unique(array_keys($allowedFiles)));
        sort($allowedFilesList, SORT_STRING);

        // Derive acceptance_criteria + evidence_requirements from obligation kinds/assertions.
        [$acceptanceCriteria, $evidenceRequirements] = $this->deriveAcceptanceAndEvidence($obligations, $targetPath);

        // Deterministic task_packet_id = sha1(objective . '|' . snapshotId).
        $taskPacketId = 'brain:'.sha1($objective.'|'.$snapshotId);

        return [
            'task_packet_id' => $taskPacketId,
            'objective' => $objective,
            'allowed_files' => $allowedFilesList,
            'scope_in' => $allowedFilesList,
            'acceptance_criteria' => $acceptanceCriteria,
            'evidence_requirements' => $evidenceRequirements,
            'depends_on' => [],
            'wave' => 1,
            'risk_level' => 'medium',
        ];
    }

    /**
     * Extract file paths from a single obligation tuple. Looks for the common keys
     * an obligation carries a file reference in. Only strings that look like PHP
     * file paths (end with .php or .blade.php) are extracted — never invented.
     *
     * @param  array<string,mixed>  $obligation
     * @return list<string>
     */
    private function extractFilePaths(array $obligation): array
    {
        $paths = [];
        $keys = ['file', 'target_file', 'file_path', 'path', 'target_path', 'target_symbol'];
        foreach ($keys as $key) {
            $value = $obligation[$key] ?? null;
            if (is_string($value) && $this->looksLikeFilePath($value)) {
                $paths[ltrim(trim($value), '/')] = true;
            }
        }

        // Also check nested arrays (some obligations nest file refs).
        foreach ($obligation as $value) {
            if (is_array($value)) {
                foreach ($this->extractFilePaths($value) as $path) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    private function looksLikeFilePath(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && (str_ends_with($value, '.php') || str_ends_with($value, '.blade.php'));
    }

    /**
     * S4-narrow PATH SAFETY: refuse a path with a parent-traversal segment, NUL byte, or a literal ellipsis
     * (the historical bug in brain-9_3-campaign — `"app/.../Foo.php"` slipping through into scope_in and
     * tripping the validator). Defensive at the translator boundary so a malformed obligation can never
     * smuggle a `..` into allowed_files / scope_in. Repeat the check at every assembly site (cheap;
     * fail-closed) rather than trusting any upstream cleaner.
     */
    private function isSafePath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return false;
        }
        // Literal ellipsis (the bug in the campaign memory) — usually a placeholder pasted by the brain or
        // a model that thought "..." meant "rest of path". Drop it before it ever reaches the seed validator.
        if (str_contains($path, '...') || str_contains($path, "\u{2026}")) {
            return false;
        }
        // Any `..` SEGMENT (not just substring — `foo..bar.php` is a legit filename). Split on / and \.
        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirror an `app/X/Y/Foo.php` target to its conventional unit-test path `tests/Unit/X/Y/FooTest.php`.
     * Returns null when the target is not under `app/`, already lives under `tests/`, or doesn't end in
     * `.php` — no invention outside the convention. .blade.php targets are out of scope (views aren't
     * unit-test mirrored).
     */
    private function mirrorTestPathFor(string $targetPath): ?string
    {
        $target = ltrim(trim($targetPath), '/');
        if (! str_starts_with($target, 'app/') || ! str_ends_with($target, '.php') || str_ends_with($target, '.blade.php')) {
            return null;
        }
        $rest = substr($target, strlen('app/'));
        $stem = substr($rest, 0, -strlen('.php'));
        if ($stem === '' || str_ends_with($stem, 'Test')) {
            return null; // nothing to mirror, or target already names a *Test.php sibling.
        }

        return 'tests/Unit/'.$stem.'Test.php';
    }

    /**
     * @param  list<string>  $paths
     */
    private function anyTestPath(array $paths): bool
    {
        foreach ($paths as $p) {
            $norm = ltrim(str_replace('\\', '/', trim($p)), '/');
            if ($norm !== '' && (str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $obligations
     * @return array{0:list<string>, 1:list<string>}
     */
    private function deriveAcceptanceAndEvidence(array $obligations, string $targetPath): array
    {
        $acceptance = [];
        $evidence = [];

        if ($targetPath !== '') {
            $acceptance[] = 'php -l '.$targetPath.' passes (no syntax error)';
            $evidence[] = 'tests_or_gates_result';
        }

        foreach ($obligations as $obligation) {
            $kind = trim((string) ($obligation['kind'] ?? ''));
            $assertionRef = trim((string) ($obligation['assertion_ref'] ?? ''));
            $targetSymbol = trim((string) ($obligation['target_symbol'] ?? ''));

            if ($assertionRef !== '') {
                $acceptance[] = $assertionRef;
                $evidence[] = 'obligation:'.$assertionRef;
            } elseif ($kind !== '' && $targetSymbol !== '') {
                $acceptance[] = $kind.' obligation on '.$targetSymbol.' is satisfied';
                $evidence[] = 'obligation:'.$kind.':'.$targetSymbol;
            }
        }

        $acceptance = array_values(array_unique($acceptance));
        $evidence = array_values(array_unique($evidence));

        if ($acceptance === []) {
            $acceptance[] = 'implementation is complete';
        }
        if ($evidence === []) {
            $evidence[] = 'tests_or_gates_result';
        }

        return [$acceptance, $evidence];
    }
}
