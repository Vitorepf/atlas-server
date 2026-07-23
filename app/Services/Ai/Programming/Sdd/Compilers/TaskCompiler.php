<?php

namespace App\Services\Ai\Programming\Sdd\Compilers;


/**
 * Compiles ordered tasks from a Plan.
 *
 * Task schema mirrors plan-task-and-receipt-contract.md:119-129:
 *   id (code), type, title, allowed_files, depends_on
 *
 * The output is a deterministic, dependency-respecting list of small tasks.
 * One task per ownership group + one final test task.
 */
class TaskCompiler
{
    /**
     * @param  array<string,mixed>  $plan
     * @return list<array<string,mixed>>
     */
    public function compile(array $plan): array
    {
        $ownership = (array) ($plan['hot_file_ownership'] ?? []);
        if ($ownership === []) {
            return $this->fallbackTask($plan);
        }

        $byOwner = [];
        foreach ($ownership as $file => $owner) {
            $byOwner[(string) $owner][] = (string) $file;
        }
        ksort($byOwner);

        $tasks = [];
        $previousId = null;
        $order = 1;
        foreach ($byOwner as $owner => $files) {
            $taskCode = sprintf('T-%02d-%s', $order, strtoupper(substr($owner, 0, 4)));
            $task = [
                'code' => $taskCode,
                'type' => $this->typeForOwner($owner),
                'title' => $this->titleForOwner($owner, count($files)),
                'allowed_files' => array_values($files),
                'forbidden_files' => array_values((array) ($plan['forbidden_files'] ?? [])),
                'depends_on' => $previousId !== null ? [$previousId] : [],
                'order_index' => $order,
            ];
            $tasks[] = $task;
            $previousId = $taskCode;
            $order++;
        }

        $testTask = $this->testTaskFromPlan($plan, $previousId, $order);
        if ($testTask !== null) {
            $tasks[] = $testTask;
        }

        return $tasks;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return list<array<string,mixed>>
     */
    private function fallbackTask(array $plan): array
    {
        return [[
            'code' => 'T-01-MISC',
            'type' => 'implement',
            'title' => 'Implement work as described in the spec',
            'allowed_files' => array_values((array) ($plan['target_files'] ?? [])),
            'forbidden_files' => array_values((array) ($plan['forbidden_files'] ?? [])),
            'depends_on' => [],
            'order_index' => 1,
        ]];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>|null
     */
    private function testTaskFromPlan(array $plan, ?string $dependency, int $order): ?array
    {
        $testPlan = (array) ($plan['test_plan'] ?? []);
        if ($testPlan === []) {
            return null;
        }

        $commands = array_values(array_filter(array_map(
            static fn ($t) => is_array($t) ? ($t['command'] ?? null) : null,
            $testPlan,
        )));

        return [
            'code' => sprintf('T-%02d-TEST', $order),
            'type' => 'test',
            'title' => 'Run validation tests for the change',
            'allowed_files' => [],
            'forbidden_files' => array_values((array) ($plan['forbidden_files'] ?? [])),
            'depends_on' => $dependency !== null ? [$dependency] : [],
            'order_index' => $order,
            'metadata' => ['commands' => $commands],
        ];
    }

    private function typeForOwner(string $owner): string
    {
        return match ($owner) {
            'database' => 'migration',
            'documentation' => 'docs',
            'test' => 'test',
            default => 'implement',
        };
    }

    private function titleForOwner(string $owner, int $fileCount): string
    {
        return sprintf('Edit %d %s file(s)', $fileCount, $owner);
    }
}
