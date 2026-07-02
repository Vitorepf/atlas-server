<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Support;

use App\Services\Ai\Support\SchemaDriftAuditor;
use PHPUnit\Framework\TestCase;

final class SchemaDriftAuditorTest extends TestCase
{
    public function test_expects_tables_created_in_up_and_not_later_dropped(): void
    {
        $sources = [
            '2026_01_01_000000_create_a' => <<<'SRC'
            public function up(): void { Schema::create('alpha', fn () => null); Schema::create('beta', fn () => null); }
            public function down(): void { Schema::dropIfExists('alpha'); Schema::dropIfExists('beta'); }
            SRC,
            '2026_01_02_000000_drop_beta' => <<<'SRC'
            public function up(): void { Schema::dropIfExists('beta'); }
            public function down(): void { Schema::create('beta', fn () => null); }
            SRC,
        ];

        self::assertSame(['alpha'], SchemaDriftAuditor::expectedTables($sources));
    }

    public function test_ignores_drops_that_only_live_in_down(): void
    {
        $sources = [
            '2026_01_01_000000_create_gamma' => <<<'SRC'
            public function up(): void { Schema::create('gamma', fn () => null); }
            public function down(): void { Schema::drop('gamma'); }
            SRC,
        ];

        self::assertSame(['gamma'], SchemaDriftAuditor::expectedTables($sources));
    }

    public function test_treats_recreated_tables_as_expected(): void
    {
        $sources = [
            '2026_01_01_000000_drop_delta' => "public function up(): void { Schema::dropIfExists('delta'); }",
            '2026_01_02_000000_create_delta' => "public function up(): void { Schema::create('delta', fn () => null); }",
        ];

        self::assertSame(['delta'], SchemaDriftAuditor::expectedTables($sources));
    }
}
