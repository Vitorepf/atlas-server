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
 *               forbidden_files?:list<string>}
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

        $findings = [];

        $hasTest = array_filter($allowed, static fn (string $f): bool => str_contains($f, 'Test.php') || str_contains($f, '/tests/')) !== [];
        $hasImpl = array_filter($allowed, static fn (string $f): bool => ! str_contains($f, 'Test.php') && ! str_contains($f, '/tests/')) !== [];

        if ($allowed !== [] && $hasTest && ! $hasImpl) {
            $findings[] = 'missing_implementation_scope';
        }
        if ($allowed !== [] && $hasImpl && ! $hasTest) {
            $findings[] = 'missing_test_scope';
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
        ];
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

        $knownCollaborators = array_values(array_map('strval', (array) ($task['known_collaborators'] ?? [])));
        foreach ($knownCollaborators as $collaborator) {
            if ($collaborator !== '' && ! in_array($collaborator, $allowed, true)) {
                $missing[] = $collaborator;
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
