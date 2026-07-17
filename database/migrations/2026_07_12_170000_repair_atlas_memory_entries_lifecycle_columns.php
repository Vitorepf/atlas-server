<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair drifted installations where the memory table predates the
     * lifecycle columns declared by AtlasMemoryEntry and its active scope.
     * The guards make this safe for both fresh and partially restored DBs.
     */
    public function up(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        if (! Schema::hasColumn('atlas_memory_entries', 'archived_at')) {
            Schema::table('atlas_memory_entries', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')
            || ! Schema::hasColumn('atlas_memory_entries', 'archived_at')) {
            return;
        }

        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
