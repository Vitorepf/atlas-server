<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael;

final class AtlasAaelExecutionPlanProver
{
    public const SCHEMA = 'atlas.aael.plan_prover.v1';

    /**
     * @param  array<string,mixed>  $task
     * @return array{
     *   fact_ids:list<string>,
     *   passed:bool,
     *   reasons:list<string>,
     *   schema_version:string
     * }
     */
    public function prove(array $task, ?string $workspaceRoot = null): array
    {
        $workspace = $workspaceRoot ?? (string) ($task['base_workspace'] ?? getcwd() ?: '');
        $factIds = [
            'fact_acceptance_commands_non_empty',
            'fact_anchor_exists',
            'fact_pathspec_under_scope',
            'fact_frozen_judge_schema_valid',
        ];
        $reasons = [];

        $commands = is_array(data_get($task, 'acceptance.commands')) ? data_get($task, 'acceptance.commands') : [];
        if ($commands === [] || array_filter($commands, static fn (mixed $command): bool => ! is_string($command) || trim($command) === '') !== []) {
            $reasons[] = 'fact_acceptance_commands_empty';
        }

        if (! $this->hasConcreteAnchor($task, $workspace)) {
            $reasons[] = 'fact_anchor_missing';
        }

        if (! $this->pathspecValid($task)) {
            $reasons[] = 'fact_pathspec_invalid';
        }

        if (! $this->frozenJudgeSchemaValid($task)) {
            $reasons[] = 'fact_frozen_judge_schema_invalid';
        }

        return [
            'fact_ids' => $factIds,
            'passed' => $reasons === [],
            'reasons' => $reasons,
            'schema_version' => self::SCHEMA,
        ];
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function hasConcreteAnchor(array $task, string $workspace): bool
    {
        $objective = (string) ($task['objective'] ?? '');
        preg_match_all('/[A-Za-z0-9_\/\\\\.-]+\.(?:php|json)/', $objective, $matches);
        $anchors = $matches[0] ?? [];

        foreach ($anchors as $anchor) {
            $candidate = rtrim($workspace, '/').'/'.ltrim($anchor, '/');
            if (is_file($candidate)) {
                return true;
            }
        }

        if (preg_match('/[A-Z][A-Za-z0-9_\\\\]+(?:\\\\[A-Z][A-Za-z0-9_\\\\]+)+/', $objective, $symbol) === 1) {
            return class_exists(trim($symbol[0], '\\'));
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function pathspecValid(array $task): bool
    {
        $allowed = array_values(array_filter((array) ($task['allowed_files'] ?? []), 'is_string'));
        $forbidden = array_values(array_filter((array) ($task['forbidden_files'] ?? []), 'is_string'));

        foreach ($allowed as $path) {
            if (! str_starts_with($path, 'app/Services/Ai/AutonomousEvolution/')
                && ! str_starts_with($path, 'app/Services/Ai/SelfConstruction/')) {
                return false;
            }

            foreach ($forbidden as $forbiddenPath) {
                if ($forbiddenPath !== '' && str_starts_with($path, $forbiddenPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function frozenJudgeSchemaValid(array $task): bool
    {
        $acceptance = is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [];

        return is_string($acceptance['metric_kind'] ?? null)
            && is_array($acceptance['allowed_globs'] ?? null)
            && is_array($acceptance['frozen_globs'] ?? null);
    }
}
