<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_forge_intakes', 'sdd_spec')) {
                $table->json('sdd_spec')->nullable()->after('non_goals');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_forge_intakes', 'sdd_spec')) {
                $table->dropColumn('sdd_spec');
            }
        });
    }
};
