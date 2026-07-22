<?php

namespace App\Services\Ai\Cognitive\WorkedExample;

class ProcessFadingScheduler
{
    /**
     * @param  array<string,mixed>  $fadingLevels
     * @return array<string,mixed>
     */
    public function schedule(int $dreyfusStage, array $fadingLevels = []): array
    {
        $stage = max(1, min(5, $dreyfusStage));
        $visibleSteps = $this->visibleSteps($stage, $fadingLevels);

        return [
            'schema_version' => 'atlas.cognitive.worked_example_fading.v1',
            'fading_level_resolved' => $stage,
            'dreyfus_stage' => $stage,
            'visible_steps' => $visibleSteps,
            'hidden_steps' => $this->hiddenSteps($visibleSteps),
            'mode' => match ($stage) {
                1 => 'full_solution',
                2 => 'partial_fading',
                3 => 'sparse_fading',
                4 => 'problem_only',
                default => 'peer_challenge',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $fadingLevels
     * @return array<int,int>
     */
    private function visibleSteps(int $stage, array $fadingLevels): array
    {
        $configured = $fadingLevels[(string) $stage] ?? $fadingLevels[$stage] ?? null;

        if (is_array($configured)) {
            return $this->normalizeSteps($configured);
        }

        return match ($stage) {
            1 => [1, 2, 3, 4, 5],
            2 => [1, 3, 5],
            3 => [1, 5],
            default => [],
        };
    }

    /**
     * @param  array<int,mixed>  $steps
     * @return array<int,int>
     */
    private function normalizeSteps(array $steps): array
    {
        return collect($steps)
            ->filter(fn (mixed $step): bool => is_numeric($step))
            ->map(fn (mixed $step): int => (int) $step)
            ->filter(fn (int $step): bool => $step > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,int>  $visibleSteps
     * @return array<int,int>
     */
    private function hiddenSteps(array $visibleSteps): array
    {
        return collect([1, 2, 3, 4, 5])
            ->reject(fn (int $step): bool => in_array($step, $visibleSteps, true))
            ->values()
            ->all();
    }
}
