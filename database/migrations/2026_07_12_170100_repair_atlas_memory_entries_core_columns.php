<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some restored PostgreSQL databases contain the original minimal memory
     * table while the corresponding historical migrations are already marked
     * as ran. Add the canonical columns idempotently instead of requiring a
     * destructive rebuild or a manual database repair.
     */
    public function up(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        $columns = Schema::getColumnListing('atlas_memory_entries');
        $add = static function (string $column, callable $definition) use (&$columns): void {
            if (in_array($column, $columns, true)) {
                return;
            }

            Schema::table('atlas_memory_entries', $definition);
            $columns[] = $column;
        };

        $add('project_id', static fn (Blueprint $table): mixed => $table->uuid('project_id')->nullable()->index());
        $add('task_id', static fn (Blueprint $table): mixed => $table->uuid('task_id')->nullable()->index());
        $add('engineering_run_id', static fn (Blueprint $table): mixed => $table->uuid('engineering_run_id')->nullable()->index());
        $add('trace_id', static fn (Blueprint $table): mixed => $table->uuid('trace_id')->nullable()->index());
        $add('session_id', static fn (Blueprint $table): mixed => $table->uuid('session_id')->nullable()->index());
        $add('user_id', static fn (Blueprint $table): mixed => $table->string('user_id', 120)->nullable()->index());
        $add('summary', static fn (Blueprint $table): mixed => $table->text('summary')->nullable());
        $add('importance', static fn (Blueprint $table): mixed => $table->unsignedTinyInteger('importance')->default(3));
        $add('priority', static fn (Blueprint $table): mixed => $table->unsignedSmallInteger('priority')->default(50));
        $add('confidence', static fn (Blueprint $table): mixed => $table->decimal('confidence', 5, 3)->nullable());
        $add('source_type', static fn (Blueprint $table): mixed => $table->string('source_type', 80)->default('manual')->index());
        $add('source_id', static fn (Blueprint $table): mixed => $table->string('source_id', 120)->nullable()->index());
        $add('source_label', static fn (Blueprint $table): mixed => $table->string('source_label', 180)->nullable());
        $add('last_used_at', static fn (Blueprint $table): mixed => $table->timestamp('last_used_at')->nullable());
    }

    public function down(): void
    {
        // This is a repair migration. Existing data must never be destroyed
        // by rolling it back; the next migration run remains idempotent.
    }
};
