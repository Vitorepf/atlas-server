<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael;

final class AtlasAaelExecutionDriftAuditor
{
    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $exploration
     * @return array{
     *   acceptance_commands_skipped:list<string>,
     *   anchor_file_untouched:bool,
     *   drift_detected:bool,
     *   extra_paths_outside_plan:list<string>,
     *   missing_planned_paths:list<string>
     * }
     */
    public function audit(array $task, array $exploration): array
    {
        $plannedPaths = array_values(array_filter((array) ($task['allowed_files'] ?? []), 'is_string'));
        sort($plannedPaths);
        $plannedCommands = array_values(array_filter((array) data_get($task, 'acceptance.commands', []), 'is_string'));
        sort($plannedCommands);

        $actualPaths = array_values(array_filter((array) ($exploration['diff_paths'] ?? []), 'is_string'));
        sort($actualPaths);
        $actualCommands = array_values(array_filter((array) ($exploration['commands_exercised'] ?? []), 'is_string'));
        sort($actualCommands);

        $extraPaths = array_values(array_diff($actualPaths, $plannedPaths));
        $missingPaths = array_values(array_diff($plannedPaths, $actualPaths));
        $skippedCommands = array_values(array_diff($plannedCommands, $actualCommands));
        $anchorFile = $this->anchorFile((string) ($task['objective'] ?? ''));
        $anchorUntouched = $anchorFile !== null && ! in_array($anchorFile, $actualPaths, true);

        return [
            'acceptance_commands_skipped' => $skippedCommands,
            'anchor_file_untouched' => $anchorUntouched,
            'drift_detected' => $extraPaths !== [] || $missingPaths !== [] || $skippedCommands !== [] || $anchorUntouched,
            'extra_paths_outside_plan' => $extraPaths,
            'missing_planned_paths' => $missingPaths,
        ];
    }

    private function anchorFile(string $objective): ?string
    {
        if (preg_match('/[A-Za-z0-9_\/\\\\.-]+\.(?:php|json)/', $objective, $match) === 1) {
            return $match[0];
        }

        return null;
    }
}
