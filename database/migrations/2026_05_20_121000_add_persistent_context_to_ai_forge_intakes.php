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
            if (! Schema::hasColumn('ai_forge_intakes', 'persistent_context')) {
                $table->json('persistent_context')->nullable()->after('context_operations_hash');
            }
            if (! Schema::hasColumn('ai_forge_intakes', 'persistent_context_hash')) {
                $table->string('persistent_context_hash', 64)->nullable()->index()->after('persistent_context');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_forge_intakes', 'persistent_context_hash')) {
                $table->dropColumn('persistent_context_hash');
            }
            if (Schema::hasColumn('ai_forge_intakes', 'persistent_context')) {
                $table->dropColumn('persistent_context');
            }
        });
    }
};
