<?php

namespace App\Services\Ai\Programming;

class ProgrammingTestImpactAnalyzer
{
    /**
     * @param  array<int,string>  $changedFiles
     * @param  array<string,mixed>  $codeGraph
     * @return array<string,mixed>
     */
    public function analyze(array $changedFiles, array $codeGraph = [], string $risk = 'medium'): array
    {
        $related = array_values(array_unique(array_filter((array) data_get($codeGraph, 'related_tests', []), 'is_string')));
        $selected = $related;
        foreach ($changedFiles as $file) {
            if (! is_string($file)) {
                continue;
            }
            if (str_contains($file, 'tests/')) {
                $selected[] = $file;
            }
        }
        $selected = array_values(array_unique($selected));

        return [
            'schema_version' => 'atlas.programming.test_impact.receipt.v1',
            'risk' => $risk,
            'changed_files' => array_values(array_filter($changedFiles, 'is_string')),
            'selected_tests' => $selected,
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
}
