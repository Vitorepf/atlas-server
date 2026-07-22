<?php

namespace App\Services\Ai\Cognitive\WorkedExample;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class WorkedExampleRenderer
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @param  array<string,mixed>  $example
     * @param  array<string,mixed>  $schedule
     * @return array<string,mixed>
     */
    public function render(array $example, array $schedule): array
    {
        return $this->slo->measure('cognitive.worked_example.render', function () use ($example, $schedule): array {
            $visible = (array) ($schedule['visible_steps'] ?? []);
            $steps = collect((array) ($example['solution_full'] ?? []))
                ->map(function (mixed $step, int $index) use ($visible): array {
                    $step = is_array($step) ? $step : ['action' => (string) $step];
                    $number = (int) ($step['step'] ?? ($index + 1));
                    $isVisible = in_array($number, $visible, true);

                    return [
                        'step' => $number,
                        'visible' => $isVisible,
                        'action' => $isVisible ? (string) ($step['action'] ?? '') : null,
                        'reasoning' => $isVisible ? (string) ($step['reasoning'] ?? '') : null,
                        'why_works' => $isVisible ? (string) ($step['why_works'] ?? '') : null,
                        'prompt' => $isVisible ? null : 'operator_fills_this_step',
                    ];
                })
                ->values()
                ->all();

            return [
                'schema_version' => 'atlas.cognitive.worked_example_render.v1',
                'status' => 'rendered',
                'worked_example_id' => (int) ($example['id'] ?? 0),
                'title' => (string) ($example['title'] ?? ''),
                'problem_context' => (string) ($example['problem_context'] ?? ''),
                'source' => (string) ($example['source'] ?? ''),
                'fading' => $schedule,
                'steps' => $steps,
            ];
        }, [
            'domain' => (string) ($example['domain'] ?? 'learning'),
            'worked_example_id' => (string) ($example['id'] ?? '0'),
        ]);
    }
}
