<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiMemoryDeltasMissingTableRepairMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php';

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');

        parent::tearDown();
    }

    public function test_it_recreates_the_final_memory_delta_schema_idempotently(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        $before = $this->migrationLedger();
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('ai_memory_deltas'));
        foreach ($this->expectedColumns() as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_memory_deltas', $column),
                "Missing ai_memory_deltas.{$column}",
            );
        }
        $this->assertSame($before, $this->migrationLedger());
    }

    public function test_it_preserves_existing_rows_when_run_repeatedly(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        DB::table('ai_memory_deltas')->insert([
            'id' => '11111111-1111-1111-1111-111111111111',
            'type' => 'process',
            'claim' => 'Preserve this governed proposal.',
            'evidence' => '[]',
            'scope' => 'global',
            'confidence' => 0.8,
            'requires_confirmation' => true,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(1, DB::table('ai_memory_deltas')->count());
        $this->assertSame(
            'Preserve this governed proposal.',
            DB::table('ai_memory_deltas')->value('claim'),
        );
    }

    public function test_it_adds_promotion_columns_to_a_partial_existing_table(): void
    {
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('claim');
            $table->timestamps();
        });

        (require database_path(self::MIGRATION))->up();

        $this->assertTrue(Schema::hasColumn('ai_memory_deltas', 'promoted_memory_entry_id'));
        $this->assertTrue(Schema::hasColumn('ai_memory_deltas', 'promoted_at'));
    }

    /**
     * @return list<string>
     */
    private function expectedColumns(): array
    {
        return [
            'id',
            'source_trace_id',
            'source_session_id',
            'source_workspace',
            'type',
            'claim',
            'evidence',
            'scope',
            'confidence',
            'valid_from',
            'valid_until',
            'use_when',
            'do_not_use_when',
            'requires_confirmation',
            'status',
            'superseded_by',
            'promoted_memory_entry_id',
            'promoted_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function migrationLedger(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    }
}
