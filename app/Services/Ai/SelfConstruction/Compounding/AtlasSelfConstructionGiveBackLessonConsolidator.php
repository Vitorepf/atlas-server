<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Compounding;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostImplementationLessonExtractor;

/**
 * Pure consolidator: folds the PostImplementationLessonExtractor's grouped
 * lesson output (entries carrying a 'lesson' string code) into a deterministic
 * list of {class: string, repeat_count: int} where repeat_count equals the
 * number of entries sharing that lesson code across ALL categories.
 *
 * Ordering: repeat_count desc, then class asc.
 */
final class AtlasSelfConstructionGiveBackLessonConsolidator
{
    /**
     * @param  array<string, mixed>  $extractorOutput  the full extract() return
     * @return list<array{class: string, repeat_count: int}>
     */
    public function consolidate(array $extractorOutput): array
    {
        // Get all lessons across all categories from the extractor output.
        $allLessons = $this->flattenLessons($extractorOutput);

        if ($allLessons === []) {
            return [];
        }

        // Count occurrences of each lesson code.
        $counts = [];
        foreach ($allLessons as $entry) {
            $code = $entry['lesson'] ?? '';
            if ($code === '') {
                continue;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        // Build result rows.
        $result = [];
        foreach ($counts as $class => $repeatCount) {
            $result[] = ['class' => $class, 'repeat_count' => $repeatCount];
        }

        // Sort: repeat_count desc, then class asc.
        usort($result, static function (array $a, array $b): int {
            $c = $b['repeat_count'] <=> $a['repeat_count'];
            if ($c !== 0) {
                return $c;
            }

            return strcmp($a['class'], $b['class']);
        });

        return $result;
    }

    /**
     * Accept either:
     *   - the full extractor output array with 'lessons' key (categorized groups)
     *   - or a list of outcome arrays (raw GiveBack outcome rows).
     *
     * When the input is a raw outcome list, the extractor is first run to
     * derive lessons before consolidating.
     *
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function flattenLessons(array $input): array
    {
        if (isset($input['lessons']) && is_array($input['lessons'])) {
            // Input is extractor output with categorized lessons.
            $all = [];
            foreach ($input['lessons'] as $categoryLessons) {
                if (! is_array($categoryLessons)) {
                    continue;
                }
                foreach ($categoryLessons as $entry) {
                    if (is_array($entry)) {
                        $all[] = $entry;
                    }
                }
            }

            return $all;
        }

        // Check if input looks like raw outcomes (list of task outcomes).
        $outcomes = $input['outcomes'] ?? $input;
        if (is_array($outcomes) && isset($outcomes[0]['task_id'])) {
            $extractor = new AtlasExternalBrainPostImplementationLessonExtractor;
            $extracted = $extractor->extract(['outcomes' => $outcomes]);

            return $this->flattenLessons($extracted);
        }

        return [];
    }
}
