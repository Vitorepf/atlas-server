<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')
            || ! Schema::hasColumn('atlas_memory_entries', 'valid_from')) {
            return;
        }

        $fallbacks = [];
        if (Schema::hasColumn('atlas_memory_entries', 'recorded_at')) {
            $fallbacks[] = 'recorded_at';
        }
        if (Schema::hasColumn('atlas_memory_entries', 'created_at')) {
            $fallbacks[] = 'created_at';
        }
        $fallbacks[] = 'CURRENT_TIMESTAMP';

        DB::table('atlas_memory_entries')
            ->whereNull('valid_from')
            ->update(['valid_from' => DB::raw('COALESCE('.implode(', ', $fallbacks).')')]);
    }

    public function down(): void
    {
        // Temporal provenance is data; never erase it in a repair rollback.
    }
};
