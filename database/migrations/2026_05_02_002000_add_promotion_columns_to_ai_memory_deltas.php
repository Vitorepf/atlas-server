<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_memory_deltas', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_memory_deltas', 'promoted_memory_entry_id')) {
                $table->uuid('promoted_memory_entry_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_memory_deltas', 'promoted_at')) {
                $table->timestamp('promoted_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_memory_deltas', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_memory_deltas', 'promoted_at')) {
                $table->dropColumn('promoted_at');
            }

            if (Schema::hasColumn('ai_memory_deltas', 'promoted_memory_entry_id')) {
                $table->dropColumn('promoted_memory_entry_id');
            }
        });
    }
};
