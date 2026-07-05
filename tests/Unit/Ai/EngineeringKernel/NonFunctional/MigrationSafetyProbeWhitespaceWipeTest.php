<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\NonFunctional;

use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;
use PHPUnit\Framework\TestCase;

/**
 * Probes the whitespace-normalisation fix in MigrationSafetyProbe — raw-SQL data
 * wipes with non-single-space whitespace (double space, tab, newline) must be
 * detected as data_wipe, not silently cleared.
 */
final class MigrationSafetyProbeWhitespaceWipeTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_07_05_000000_touch.php';

    public function test_double_space_drop_table_is_unsafe(): void
    {
        $result = MigrationSafetyProbe::probe([
            self::MIGRATION => '<?php DB::statement("DROP  TABLE users");',
        ]);

        $this->assertTrue($result['applies']);
        $this->assertFalse($result['safe'], 'DROP  TABLE (two spaces) must be detected as unsafe');
        $this->assertStringContainsString('data_wipe', $result['reasons'][0]);
    }

    public function test_tab_separated_drop_table_is_unsafe(): void
    {
        $result = MigrationSafetyProbe::probe([
            self::MIGRATION => "<?php DB::statement(\"DROP\tTABLE users\");",
        ]);

        $this->assertTrue($result['applies']);
        $this->assertFalse($result['safe'], 'DROP<TAB>TABLE must be detected as unsafe');
        $this->assertStringContainsString('data_wipe', $result['reasons'][0]);
    }

    public function test_newline_separated_drop_table_is_unsafe(): void
    {
        $result = MigrationSafetyProbe::probe([
            self::MIGRATION => "<?php DB::statement(\"DROP\nTABLE users\");",
        ]);

        $this->assertTrue($result['applies']);
        $this->assertFalse($result['safe'], 'DROP<NL>TABLE must be detected as unsafe');
        $this->assertStringContainsString('data_wipe', $result['reasons'][0]);
    }

    public function test_classic_single_space_drop_table_still_detected(): void
    {
        $result = MigrationSafetyProbe::probe([
            self::MIGRATION => '<?php DB::statement("DROP TABLE users");',
        ]);

        $this->assertTrue($result['applies']);
        $this->assertFalse($result['safe']);
        $this->assertStringContainsString('data_wipe', $result['reasons'][0]);
    }

    public function test_classic_delete_from_still_detected(): void
    {
        $result = MigrationSafetyProbe::probe([
            self::MIGRATION => '<?php DB::statement("DELETE FROM users");',
        ]);

        $this->assertTrue($result['applies']);
        $this->assertFalse($result['safe']);
        $this->assertStringContainsString('data_wipe', $result['reasons'][0]);
    }

    public function test_benign_migration_not_flagged_as_data_wipe(): void
    {
        $result = MigrationSafetyProbe::probe([
            self::MIGRATION => '<?php Schema::table("users", function ($table) { $table->string("email"); });',
        ]);

        $this->assertTrue($result['applies']);
        $this->assertTrue($result['safe'], 'benign additive migration must not be flagged');
        // No data_wipe reason
        $this->assertSame([], $result['reasons']);
    }
}
