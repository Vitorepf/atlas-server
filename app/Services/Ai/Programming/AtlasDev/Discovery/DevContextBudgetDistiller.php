<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

/**
 * Pure context budgeter: a small model performs worse with more noise, not less evidence — this
 * distiller trims labeled context sections to fit a character budget with a DETERMINISTIC
 * priority order so the sections that actually change what the model can prove correctly
 * (callers, tests, decisions) survive before generic doc prose does.
 *
 * Default priority (acceptance-critical evidence first, highest to lowest):
 *   callers > tests > decisions > risks > recent_outcomes > symbols > owner_docs
 *
 * A caller may override any label's priority via $criticality (higher wins); an unrecognized
 * label defaults to the lowest priority tier so it never displaces named evidence.
 *
 * No I/O, no provider calls, no randomness — identical input always yields identical output.
 */
final class DevContextBudgetDistiller
{
    public const SCHEMA = 'atlas.dev.context_budget_distiller.v1';

    public const REASON_OVER_BUDGET = 'over_budget';

    public const REASON_TRUNCATED = 'truncated';

    /** @var array<string,int> */
    private const DEFAULT_PRIORITY = [
        'callers' => 70,
        'tests' => 60,
        'decisions' => 50,
        'risks' => 40,
        'recent_outcomes' => 30,
        'symbols' => 20,
        'owner_docs' => 10,
    ];

    private const UNKNOWN_LABEL_PRIORITY = 0;

    /**
     * @param  array<string, string>  $contextSections  label => content
     * @param  array<string, float|int>  $criticality  optional per-label priority overrides (higher wins)
     * @return array{
     *   schema: string,
     *   sections: array<string, string>,
     *   included_labels: list<string>,
     *   dropped_report: list<array{label: string, reason: string, chars: int}>,
     *   total_chars: int,
     *   budget_chars: int,
     * }
     */
    public function distill(array $contextSections, int $budgetChars, array $criticality = []): array
    {
        $budgetChars = max(0, $budgetChars);

        $labels = array_keys($contextSections);
        sort($labels, SORT_STRING); // deterministic tiebreak base, independent of caller's array order

        usort($labels, function (string $a, string $b) use ($criticality): int {
            $priorityA = $this->priorityFor($a, $criticality);
            $priorityB = $this->priorityFor($b, $criticality);

            return $priorityB !== $priorityA ? $priorityB <=> $priorityA : strcmp($a, $b);
        });

        $totalChars = 0;
        foreach ($contextSections as $content) {
            $totalChars += strlen((string) $content);
        }

        if ($totalChars <= $budgetChars) {
            // Already fits — pass through untouched, in the original caller-declared shape.
            return [
                'schema' => self::SCHEMA,
                'sections' => $contextSections,
                'included_labels' => array_keys($contextSections),
                'dropped_report' => [],
                'total_chars' => $totalChars,
                'budget_chars' => $budgetChars,
            ];
        }

        $sections = [];
        $includedLabels = [];
        $droppedReport = [];
        $remaining = $budgetChars;

        foreach ($labels as $label) {
            $content = (string) $contextSections[$label];
            $chars = strlen($content);

            if ($remaining <= 0) {
                $droppedReport[] = ['label' => $label, 'reason' => self::REASON_OVER_BUDGET, 'chars' => $chars];

                continue;
            }

            if ($chars <= $remaining) {
                $sections[$label] = $content;
                $includedLabels[] = $label;
                $remaining -= $chars;

                continue;
            }

            // Cut on a line boundary, never mid-entry: a half fact in the prompt is
            // worse than one fact fewer. Falls back to the raw cut when the section
            // is a single line longer than the whole remaining budget.
            $cut = substr($content, 0, $remaining);
            $lastNewline = strrpos($cut, "\n");
            if ($lastNewline !== false && $lastNewline > 0) {
                $cut = substr($cut, 0, $lastNewline);
            }
            $sections[$label] = $cut;
            $includedLabels[] = $label;
            $droppedReport[] = ['label' => $label, 'reason' => self::REASON_TRUNCATED, 'chars' => $chars - strlen($cut)];
            $remaining = 0;
        }

        return [
            'schema' => self::SCHEMA,
            'sections' => $sections,
            'included_labels' => $includedLabels,
            'dropped_report' => $droppedReport,
            'total_chars' => $totalChars,
            'budget_chars' => $budgetChars,
        ];
    }

    /** @param  array<string, float|int>  $criticality */
    private function priorityFor(string $label, array $criticality): float
    {
        if (array_key_exists($label, $criticality)) {
            return (float) $criticality[$label];
        }

        return (float) (self::DEFAULT_PRIORITY[$label] ?? self::UNKNOWN_LABEL_PRIORITY);
    }
}
