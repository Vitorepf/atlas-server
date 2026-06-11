<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Facades\File;

class ProgrammingTestImpactAnalyzer
{
    /**
     * @param  array<int,string>  $changedFiles
     * @param  array<string,mixed>  $codeGraph
     * @return array<string,mixed>
     */
    public function analyze(array $changedFiles, array $codeGraph = [], string $risk = 'medium'): array
    {
        $related = AiStringListNormalizer::uniqueStrings(array_values(array_filter(
            (array) data_get($codeGraph, 'related_tests', []),
            'is_string',
        )));
        $selected = $related;
        foreach ($changedFiles as $file) {
            if (! is_string($file)) {
                continue;
            }
            if (str_contains($file, 'tests/')) {
                $selected[] = $file;
            }
            foreach ($this->conventionCandidates($file) as $candidate) {
                $selected[] = $candidate;
            }
        }
        $selected = AiStringListNormalizer::uniqueStrings($selected);
        $selectedExisting = array_values(array_filter(
            $selected,
            fn (string $test): bool => File::exists(base_path($test)),
        ));
        $changedProductionFiles = array_values(array_filter(
            $changedFiles,
            fn (mixed $file): bool => is_string($file) && ! str_contains($file, 'tests/'),
        ));

        return [
            'schema_version' => 'atlas.programming.test_impact.receipt.v1',
            'risk' => $risk,
            'changed_files' => array_values(array_filter($changedFiles, 'is_string')),
            'changed_production_file_count' => count($changedProductionFiles),
            'selected_tests' => $selected,
            'selected_existing_tests' => $selectedExisting,
            'recommended_commands' => $this->commands($selected, $risk),
            'selection_reason' => $selected === []
                ? 'no_related_tests_found_use_no_test_reason_or_broader_suite'
                : 'semantic_code_graph_related_tests',
            'minimum_policy' => match ($risk) {
                'critical', 'high' => 'module_suite_plus_quality_scan',
                'medium' => 'unit_plus_related_feature',
                default => 'targeted_unit_or_reason',
            },
            'requires_no_test_reason' => $selected === [],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function conventionCandidates(string $file): array
    {
        if (str_contains($file, 'tests/')) {
            return [];
        }

        $basename = pathinfo($file, PATHINFO_FILENAME);
        if ($basename === '') {
            return [];
        }

        return [
            'tests/Unit/'.$basename.'Test.php',
            'tests/Feature/'.$basename.'Test.php',
        ];
    }

    /**
     * @param  array<int,string>  $selected
     * @return array<int,string>
     */
    private function commands(array $selected, string $risk): array
    {
        if ($selected === []) {
            return in_array($risk, ['critical', 'high'], true)
                ? ['/opt/homebrew/bin/php artisan test', 'npm run test:engineering']
                : [];
        }

        $commands = array_map(
            fn (string $test): string => '/opt/homebrew/bin/php artisan test '.$test,
            array_slice($selected, 0, 8),
        );

        if (in_array($risk, ['critical', 'high'], true)) {
            $commands[] = 'npm run test:engineering';
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }
}
