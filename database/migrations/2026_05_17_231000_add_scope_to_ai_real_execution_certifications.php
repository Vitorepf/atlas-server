<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_real_execution_certifications') && ! Schema::hasColumn('ai_real_execution_certifications', 'scope')) {
            Schema::table('ai_real_execution_certifications', function (Blueprint $table): void {
                $table->string('scope', 40)->default('kernel')->index()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_real_execution_certifications') && Schema::hasColumn('ai_real_execution_certifications', 'scope')) {
            Schema::table('ai_real_execution_certifications', function (Blueprint $table): void {
                $table->dropColumn('scope');
            });
        }
    }
};
