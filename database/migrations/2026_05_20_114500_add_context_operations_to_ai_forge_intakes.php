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
            if (! Schema::hasColumn('ai_forge_intakes', 'context_operations')) {
                $table->json('context_operations')->nullable()->after('rich_input_schema_version');
            }
            if (! Schema::hasColumn('ai_forge_intakes', 'context_operations_hash')) {
                $table->string('context_operations_hash', 64)->nullable()->index()->after('context_operations');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_forge_intakes', 'context_operations_hash')) {
                $table->dropColumn('context_operations_hash');
            }
            if (Schema::hasColumn('ai_forge_intakes', 'context_operations')) {
                $table->dropColumn('context_operations');
            }
        });
    }
};
