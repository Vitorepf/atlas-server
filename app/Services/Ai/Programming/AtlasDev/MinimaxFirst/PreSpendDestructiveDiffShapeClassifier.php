<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\MinimaxFirst;

final class PreSpendDestructiveDiffShapeClassifier
{
    /**
     * @param  array<string,mixed>  $diffStats
     * @return array{
     *     schema_version:string,
     *     decision:'accept'|'reject_destructive_or_filler',
     *     reasons:list<string>,
     *     inputs:array<string,int|bool>
     * }
     */
    public function classify(array $diffStats): array
    {
        $productInsertions = (int) ($diffStats['product_insertions'] ?? 0);
        $productDeletions = (int) ($diffStats['product_deletions'] ?? 0);
        $testChanged = (bool) ($diffStats['test_changed'] ?? false);
        $testInsertions = (int) ($diffStats['test_insertions'] ?? 0);
        $testDeletions = (int) ($diffStats['test_deletions'] ?? 0);
        $largestSingleFileDeletions = (int) ($diffStats['largest_single_file_deletions'] ?? 0);
        $findingRequiresTestUpdate = (bool) ($diffStats['finding_requires_test_update'] ?? false);

        $reasons = [];

        if ($findingRequiresTestUpdate && ! $testChanged) {
            $reasons[] = 'required_test_update_missing';
        }

        if ($productDeletions > 0 && ! $testChanged && $productDeletions >= 80) {
            $reasons[] = 'large_product_deletion_without_test_update';
        }

        $productLineDelta = $productInsertions + $productDeletions;
        if ($productLineDelta > 0 && ! $testChanged && $productLineDelta >= 220) {
            $reasons[] = 'large_product_diff_without_test_update';
        }

        if (! $testChanged && $largestSingleFileDeletions >= 80) {
            $reasons[] = 'large_single_file_deletion_without_test_update';
        }

        if (! $testChanged && $productDeletions >= 30 && $productInsertions > 0
            && ($productDeletions / max(1, $productInsertions)) >= 3.0) {
            $reasons[] = 'deletion_heavy_product_diff_without_test_update';
        }

        if ($testDeletions >= 80 && ($testDeletions / max(1, $testInsertions)) >= 2.0) {
            $reasons[] = 'large_test_deletion';
        }

        $reasons = array_values(array_unique($reasons));
        $decision = $reasons === [] ? 'accept' : 'reject_destructive_or_filler';

        return [
            'schema_version' => 'atlas.dev.minimax_first.destructive_diff_shape_classification.v1',
            'decision' => $decision,
            'reasons' => $reasons,
            'inputs' => [
                'product_insertions' => $productInsertions,
                'product_deletions' => $productDeletions,
                'test_changed' => $testChanged,
                'test_insertions' => $testInsertions,
                'test_deletions' => $testDeletions,
                'largest_single_file_deletions' => $largestSingleFileDeletions,
                'finding_requires_test_update' => $findingRequiresTestUpdate,
            ],
        ];
    }
}
