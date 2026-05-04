<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
                $table->uuid('superseded_by_id')->nullable()->after('archived_at');
                $table->foreign('superseded_by_id')
                    ->references('id')->on('atlas_memory_entries')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
                $table->dropForeign(['superseded_by_id']);
                $table->dropColumn('superseded_by_id');
            }
        });
    }
};
