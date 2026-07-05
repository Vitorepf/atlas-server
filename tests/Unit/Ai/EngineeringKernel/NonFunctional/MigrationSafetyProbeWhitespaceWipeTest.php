<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\NonFunctional;

use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;
use Tests\TestCase;

/**
 * Proves the data-wipe detector normalizes whitespace before matching SQL
 * keyword needles, so multi-space/tab/newline-separated wipes are caught.
 */
final class MigrationSafetyProbeWhitespaceWipeTest extends TestCase
{
    public function test_multi_space_drop_table_is_unsafe(): void
    {
        // DROP followed by TWO spaces + TABLE — the old raw-stripos code
        // misses this because it needle-matches against "DROP TABLE"
        // (single space). The whitespace-normalized code collapses the
        // double space to a single space and hits.
        $sources = [
            'database/migrations/2026_01_01_000000_test.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP  TABLE users');
    }
    public function down(): void {}
};
PHP
        ];

        $result = MigrationSafetyProbe::probe($sources);

        $this->assertFalse($result['safe']);
        $migration = $result['migrations']['database/migrations/2026_01_01_000000_test.php'];
        $this->assertNotEmpty($migration['data_wipe']);
        $this->assertStringContainsString('DROP TABLE', implode('|', $migration['data_wipe']));
    }

    public function test_tab_separated_drop_table_is_unsafe(): void
    {
        $source = '<?php
return new class extends Migration {
    public function up(): void { DB::statement("DROP'."\t".'TABLE users"); }
    public function down(): void {}
};';
        $sources = [
            'database/migrations/2026_01_02_000000_test.php' => $source,
        ];

        $result = MigrationSafetyProbe::probe($sources);

        $this->assertFalse($result['safe']);
        $migration = $result['migrations']['database/migrations/2026_01_02_000000_test.php'];
        $this->assertNotEmpty($migration['data_wipe']);
    }

    public function test_newline_separated_drop_table_is_unsafe(): void
    {
        $source = "<?php\nreturn new class extends Migration {\n    public function up(): void { DB::statement(\"DROP\nTABLE users\"); }\n    public function down(): void {}\n};";
        $sources = [
            'database/migrations/2026_01_03_000000_test.php' => $source,
        ];

        $result = MigrationSafetyProbe::probe($sources);

        $this->assertFalse($result['safe']);
        $migration = $result['migrations']['database/migrations/2026_01_03_000000_test.php'];
        $this->assertNotEmpty($migration['data_wipe']);
    }

    public function test_single_space_classic_drop_table_still_detected(): void
    {
        // No regression: classic single-space DROP TABLE is still caught.
        $sources = [
            'database/migrations/2026_01_04_000000_test.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP TABLE users');
    }
    public function down(): void {}
};
PHP
        ];

        $result = MigrationSafetyProbe::probe($sources);

        $this->assertFalse($result['safe']);
        $migration = $result['migrations']['database/migrations/2026_01_04_000000_test.php'];
        $this->assertNotEmpty($migration['data_wipe']);
    }

    public function test_single_space_delete_from_still_detected(): void
    {
        $sources = [
            'database/migrations/2026_01_05_000000_test.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DELETE FROM users');
    }
    public function down(): void {}
};
PHP
        ];

        $result = MigrationSafetyProbe::probe($sources);

        $this->assertFalse($result['safe']);
        $migration = $result['migrations']['database/migrations/2026_01_05_000000_test.php'];
        $this->assertNotEmpty($migration['data_wipe']);
    }

    public function test_benign_migration_not_flagged_as_data_wipe(): void
    {
        $sources = [
            'database/migrations/2026_01_06_000000_test.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('new_column')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('new_column');
        });
    }
};
PHP
        ];

        $result = MigrationSafetyProbe::probe($sources);

        $migration = $result['migrations']['database/migrations/2026_01_06_000000_test.php'];
        $this->assertEmpty($migration['data_wipe'], 'benign addColumn must not be flagged as data_wipe');
    }
}
