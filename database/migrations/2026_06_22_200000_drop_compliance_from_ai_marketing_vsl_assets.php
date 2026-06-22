<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }
        if (! Schema::hasColumn('ai_marketing_vsl_assets', 'compliance')) {
            return;
        }

        Schema::table('ai_marketing_vsl_assets', function (Blueprint $table): void {
            $table->dropColumn('compliance');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }
        if (Schema::hasColumn('ai_marketing_vsl_assets', 'compliance')) {
            return;
        }

        Schema::table('ai_marketing_vsl_assets', function (Blueprint $table): void {
            $table->json('compliance')->nullable();
        });
    }
};
