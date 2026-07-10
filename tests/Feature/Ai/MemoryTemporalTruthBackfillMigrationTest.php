<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MemoryTemporalTruthBackfillMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_07_09_154500_backfill_atlas_memory_valid_from.php';

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entries');

        parent::tearDown();
    }

    public function test_it_backfills_valid_from_from_recorded_at_idempotently(): void
    {
        Schema::create('atlas_memory_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamps();
        });
        DB::table('atlas_memory_entries')->insert([
            'id' => '11111111-1111-1111-1111-111111111111',
            'recorded_at' => '2026-07-01 12:00:00',
            'valid_from' => null,
            'created_at' => '2026-07-02 12:00:00',
            'updated_at' => '2026-07-02 12:00:00',
        ]);
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up();

        $this->assertSame(
            '2026-07-01 12:00:00',
            DB::table('atlas_memory_entries')->value('valid_from'),
        );
    }
}
