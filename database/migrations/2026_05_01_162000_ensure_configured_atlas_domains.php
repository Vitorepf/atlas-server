<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_domains')) {
            return;
        }

        foreach (config('atlas.domains.defaults', []) as $domain) {
            DB::table('atlas_domains')->updateOrInsert(
                ['slug' => $domain['slug']],
                [
                    'label' => $domain['label'],
                    'description' => $domain['description'] ?? null,
                    'color_light' => $domain['color_light'],
                    'color_dark' => $domain['color_dark'],
                    'default_sensitivity' => $domain['default_sensitivity'],
                    'external_ai_policy' => $domain['external_ai_policy'] ?? 'allow',
                    'active' => (bool) ($domain['active'] ?? true),
                    'sort_order' => (int) ($domain['sort_order'] ?? 100),
                    'metadata' => json_encode($domain['metadata'] ?? [], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        //
    }
};
