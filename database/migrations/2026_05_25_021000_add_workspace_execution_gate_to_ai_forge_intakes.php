<?php

declare(strict_types=1);

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
            if (! Schema::hasColumn('ai_forge_intakes', 'workspace_execution_gate')) {
                $table->json('workspace_execution_gate')->nullable()->after('persistent_context_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_forge_intakes', 'workspace_execution_gate')) {
                $table->dropColumn('workspace_execution_gate');
            }
        });
    }
};
