<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\PreSpendDestructiveDiffShapeClassifier;
use Tests\TestCase;

final class PreSpendDestructiveDiffShapeClassifierTest extends TestCase
{
    private PreSpendDestructiveDiffShapeClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new PreSpendDestructiveDiffShapeClassifier();
    }

    public function test_large_product_deletion_without_test_update_is_rejected(): void
    {
        $result = $this->classifier->classify([
            'product_deletions' => 120,
            'test_changed' => false,
        ]);

        $this->assertSame('reject_destructive_or_filler', $result['decision']);
        $this->assertContains('large_product_deletion_without_test_update', $result['reasons']);
    }

    public function test_large_test_deletion_is_rejected(): void
    {
        $result = $this->classifier->classify([
            'test_deletions' => 200,
            'test_insertions' => 10,
        ]);

        $this->assertSame('reject_destructive_or_filler', $result['decision']);
        $this->assertContains('large_test_deletion', $result['reasons']);
    }

    public function test_clean_diff_with_test_change_is_accepted(): void
    {
        $result = $this->classifier->classify([
            'product_insertions' => 40,
            'product_deletions' => 5,
            'test_changed' => true,
        ]);

        $this->assertSame('accept', $result['decision']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_required_test_update_missing_is_rejected(): void
    {
        $result = $this->classifier->classify([
            'finding_requires_test_update' => true,
            'test_changed' => false,
        ]);

        $this->assertSame('reject_destructive_or_filler', $result['decision']);
        $this->assertContains('required_test_update_missing', $result['reasons']);
    }

    public function test_empty_stats_is_accepted(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame('accept', $result['decision']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_reason_order_and_input_coercion_match_the_pre_spend_contract(): void
    {
        $result = $this->classifier->classify([
            'product_insertions' => '10',
            'product_deletions' => '120',
            'test_changed' => 0,
            'test_insertions' => '1',
            'test_deletions' => '100',
            'largest_single_file_deletions' => '80',
            'finding_requires_test_update' => 1,
        ]);

        $this->assertSame([
            'required_test_update_missing',
            'large_product_deletion_without_test_update',
            'large_single_file_deletion_without_test_update',
            'deletion_heavy_product_diff_without_test_update',
            'large_test_deletion',
        ], $result['reasons']);
        $this->assertSame([
            'product_insertions' => 10,
            'product_deletions' => 120,
            'test_changed' => false,
            'test_insertions' => 1,
            'test_deletions' => 100,
            'largest_single_file_deletions' => 80,
            'finding_requires_test_update' => true,
        ], $result['inputs']);
    }
}
