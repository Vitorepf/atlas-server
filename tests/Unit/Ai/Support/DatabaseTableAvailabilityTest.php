<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Tests\TestCase;

final class DatabaseTableAvailabilityTest extends TestCase
{
    public function test_missing_table_returns_false_without_throwing(): void
    {
        $this->assertFalse(DatabaseTableAvailability::has('atlas_table_that_should_not_exist_for_support_test'));
    }

    public function test_missing_and_all_report_table_sets(): void
    {
        $tables = [
            'atlas_table_that_should_not_exist_for_support_test_a',
            'atlas_table_that_should_not_exist_for_support_test_b',
        ];

        $this->assertSame($tables, DatabaseTableAvailability::missing($tables));
        $this->assertFalse(DatabaseTableAvailability::all($tables));
    }

    public function test_missing_column_returns_false_without_throwing(): void
    {
        $this->assertFalse(DatabaseTableAvailability::hasColumn(
            'atlas_table_that_should_not_exist_for_support_test',
            'missing_column',
        ));
    }
}
