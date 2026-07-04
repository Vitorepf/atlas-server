<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure probe. Detects when a task's allowed_files set is NOT closed over the files needed to make
 * the requested behavior real — catching test-only scopes, implementation-only scopes, missing
 * caller/collaborator files, and forbidden real-fix paths BEFORE a muscle wastes tokens building a
 * fake leaf implementation that can never wire into the actual behavior.
 *
 * Input shape: {objective?:string, allowed_files?:list<string>, known_collaborators?:list<string>,
 *               forbidden_files?:list<string>, acceptance_requires_test?:bool}
 *
 * acceptance_requires_test defaults to true (existing callers keep flagging
 * missing_test_scope for impl-only scopes); set it to false when the acceptance
 * criteria genuinely need no runnable test (e.g. a pure config/data change).
 *
 * recommended_closure: the smallest impl+test file pair that would close a
 * test-only or impl-only scope — never a broad directory. Empty when the scope
 * is already closed or the gap is a caller/collaborator path, not a single-file
 * impl/test mirror.
 *
 * Pure — no I/O, no provider calls, no enqueue.
 */
final class AtlasTaskFabricAllowedFilesClosureProbe
{
    public const SCHEMA = 'atlas.task_fabric.allowed_files_closure_probe.v1';

    public const ACTION_PROCEED = 'proceed';

    public const ACTION_RESCOPE_ALLOWED_FILES = 'rescope_allowed_files';

    public const ACTION_GIVE_BACK_OR_RESPEC = 'give_back_or_respec';

    /**
     * @param  array<string,mixed>  $task
     * @return array{schema:string, closed:bool, findings:list<string>, missing_files:list<string>, recommended_action:string}
     */
    public function probe(array $task): array
    {
        $allowed = array_values(array_map('strval', (array) ($task['allowed_files'] ?? [])));
        $objective = (string) ($task['objective'] ?? '');
        $forbidden = array_values(array_map('strval', (array) ($task['forbidden_files'] ?? [])));
        $acceptanceRequiresTest = (bool) ($task['acceptance_requires_test'] ?? true);

        $findings = [];

        $testFiles = array_values(array_filter($allowed, static fn (string $f): bool => str_contains($f, 'Test.php') || str_contains($f, '/tests/')));
        $implFiles = array_values(array_filter($allowed, static fn (string $f): bool => ! str_contains($f, 'Test.php') && ! str_contains($f, '/tests/')));
        $hasTest = $testFiles !== [];
        $hasImpl = $implFiles !== [];

        $recommendedClosure = [];

        if ($allowed !== [] && $hasTest && ! $hasImpl) {
            $findings[] = 'missing_implementation_scope';
            $recommendedClosure = [$this->deriveImplPath($testFiles[0]), $testFiles[0]];
        }
        if ($allowed !== [] && $hasImpl && ! $hasTest && $acceptanceRequiresTest) {
            $findings[] = 'missing_test_scope';
            $recommendedClosure = [$implFiles[0], $this->deriveTestPath($implFiles[0])];
        }

        $missingFiles = $this->missingCollaborators($task, $objective, $allowed);
        if ($missingFiles !== []) {
            $findings[] = 'missing_behavior_path';
        }

        $forbiddenHit = array_values(array_intersect(array_merge($missingFiles, $allowed), $forbidden));
        $recommendedAction = self::ACTION_PROCEED;
        if ($forbiddenHit !== []) {
            $findings[] = 'forbidden_real_fix_path';
            $recommendedAction = self::ACTION_GIVE_BACK_OR_RESPEC;
        } elseif ($findings !== []) {
            $recommendedAction = self::ACTION_RESCOPE_ALLOWED_FILES;
        }

        $findings = array_values(array_unique($findings));
        sort($findings, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'closed' => $findings === [],
            'findings' => $findings,
            'missing_files' => $missingFiles,
            'recommended_action' => $recommendedAction,
            'recommended_closure' => $recommendedClosure,
        ];
    }

    private function deriveTestPath(string $implPath): string
    {
        $test = preg_replace('#^app/#', 'tests/Unit/', $implPath) ?? $implPath;
        $test = preg_replace('/\.php$/', 'Test.php', $test) ?? $test;

        return $test;
    }

    private function deriveImplPath(string $testPath): string
    {
        $impl = preg_replace('#^tests/Unit/#', 'app/', $testPath) ?? $testPath;
        $impl = preg_replace('/Test\.php$/', '.php', $impl) ?? $impl;

        return $impl;
    }

    /**
     * Caller/collaborator files the objective implies are needed but are absent from allowed_files —
     * from an explicit known_collaborators hint and from any bare `.php` path named in the objective text.
     *
     * @param  array<string,mixed>  $task
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function missingCollaborators(array $task, string $objective, array $allowed): array
    {
        $missing = [];

        $knownCollaborators = (array) ($task['known_collaborators'] ?? []);
        foreach ($knownCollaborators as $collaborator) {
            // Support both flat string paths and transitive_caller arrays.
            if (is_array($collaborator)) {
                $transitiveCallers = array_values(array_map('strval', (array) ($collaborator['transitive_callers'] ?? [])));
                foreach ($transitiveCallers as $caller) {
                    if ($caller !== '' && ! in_array($caller, $allowed, true) && ! in_array($caller, $missing, true)) {
                        $missing[] = $caller;
                    }
                }
            } else {
                $path = (string) $collaborator;
                if ($path !== '' && ! in_array($path, $allowed, true) && ! in_array($path, $missing, true)) {
                    $missing[] = $path;
                }
            }
        }

        if (preg_match_all('#[A-Za-z0-9_\-/]+\.php#', $objective, $matches) !== false) {
            foreach ($matches[0] as $path) {
                if (! in_array($path, $allowed, true) && ! in_array($path, $missing, true)) {
                    $missing[] = $path;
                }
            }
        }

        sort($missing, SORT_STRING);

        return $missing;
    }
}
