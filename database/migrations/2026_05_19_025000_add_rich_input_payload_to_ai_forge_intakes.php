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
            if (! Schema::hasColumn('ai_forge_intakes', 'rich_input_payload')) {
                $table->json('rich_input_payload')->nullable()->after('context_pack_hash');
            }

            if (! Schema::hasColumn('ai_forge_intakes', 'rich_input_schema_version')) {
                $table->string('rich_input_schema_version', 120)->nullable()->after('rich_input_payload');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_forge_intakes', 'rich_input_schema_version')) {
                $table->dropColumn('rich_input_schema_version');
            }

            if (Schema::hasColumn('ai_forge_intakes', 'rich_input_payload')) {
                $table->dropColumn('rich_input_payload');
            }
        });
    }
};
