<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Pure contract for sub-threshold destructive test coverage removal signals.
 *
 * Catches gaps below the shipped large_test_deletion gate (deletions>=80
 * and ratio>=2.0 at AtlasMinimaxFirstWorkerService::providerDiffQualityGate).
 */
final class DestructiveTestCoverageRemovalContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.destructive_test_coverage_removal.v1';

    public const CONTRACT_ID = 'destructive_test_coverage_removal';

    public const QUALITY_BAR_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md';

    public const TRIVIAL_PRODUCT_CHANGE_FLOOR = 2;

    public const REASON_WHOLE_TEST_FILE_DELETED_WITH_NO_PRODUCT_CHANGE = 'whole_test_file_deleted_with_no_product_change';

    public const REASON_NET_TEST_LINES_DROPPED_WITHOUT_PRODUCT_GROWTH = 'net_test_lines_dropped_without_product_growth';

    public const REASON_TEST_CUT_MASKED_BY_TRIVIAL_PRODUCT_EDIT = 'test_cut_masked_by_trivial_product_edit';

    private function __construct(
        public readonly int $testInsertions,
        public readonly int $testDeletions,
        public readonly int $productInsertions,
        public readonly int $productDeletions,
        public readonly int $testFilesDeletedCount,
    ) {}

    public static function defaults(): self
    {
        return new self(
            testInsertions: 0,
            testDeletions: 0,
            productInsertions: 0,
            productDeletions: 0,
            testFilesDeletedCount: 0,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            testInsertions: max(0, (int) ($input['test_insertions'] ?? 0)),
            testDeletions: max(0, (int) ($input['test_deletions'] ?? 0)),
            productInsertions: max(0, (int) ($input['product_insertions'] ?? 0)),
            productDeletions: max(0, (int) ($input['product_deletions'] ?? 0)),
            testFilesDeletedCount: max(0, (int) ($input['test_files_deleted_count'] ?? 0)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $productLineDelta = $this->productInsertions + $this->productDeletions;

        $wholeTestFileDeletedWithNoProductChange = $this->testFilesDeletedCount > 0
            && $this->productInsertions === 0
            && $this->productDeletions === 0;

        $netTestLinesDroppedWithoutProductGrowth = $this->testDeletions > $this->testInsertions
            && $this->productInsertions === 0;

        $testCutMaskedByTrivialProductEdit = $this->testDeletions > 0
            && $productLineDelta > 0
            && $productLineDelta <= self::TRIVIAL_PRODUCT_CHANGE_FLOOR;

        $triggerReasons = [];
        if ($wholeTestFileDeletedWithNoProductChange) {
            $triggerReasons[] = self::REASON_WHOLE_TEST_FILE_DELETED_WITH_NO_PRODUCT_CHANGE;
        }
        if ($netTestLinesDroppedWithoutProductGrowth) {
            $triggerReasons[] = self::REASON_NET_TEST_LINES_DROPPED_WITHOUT_PRODUCT_GROWTH;
        }
        if ($testCutMaskedByTrivialProductEdit) {
            $triggerReasons[] = self::REASON_TEST_CUT_MASKED_BY_TRIVIAL_PRODUCT_EDIT;
        }

        $coverageRemoved = $triggerReasons !== [];
        $verdict = $coverageRemoved ? 'coverage_removed' : 'coverage_preserved';

        return [
            'schema_version' => self::SCHEMA,
            'contract_id' => self::CONTRACT_ID,
            'quality_bar_matrix_canonical' => self::QUALITY_BAR_MATRIX_CANONICAL,
            'trivial_product_change_floor' => self::TRIVIAL_PRODUCT_CHANGE_FLOOR,
            'inputs' => [
                'test_insertions' => $this->testInsertions,
                'test_deletions' => $this->testDeletions,
                'product_insertions' => $this->productInsertions,
                'product_deletions' => $this->productDeletions,
                'test_files_deleted_count' => $this->testFilesDeletedCount,
            ],
            'outputs' => [
                'verdict' => $verdict,
                'coverage_removed' => $coverageRemoved,
                'whole_test_file_deleted_with_no_product_change' => $wholeTestFileDeletedWithNoProductChange,
                'net_test_lines_dropped_without_product_growth' => $netTestLinesDroppedWithoutProductGrowth,
                'test_cut_masked_by_trivial_product_edit' => $testCutMaskedByTrivialProductEdit,
                'trigger_reasons' => $triggerReasons,
            ],
        ];
    }
}
