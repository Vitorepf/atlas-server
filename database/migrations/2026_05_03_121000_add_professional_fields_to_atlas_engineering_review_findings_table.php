<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_review_findings', function (Blueprint $table): void {
            $table->decimal('confidence', 5, 3)->nullable()->after('status');
            $table->string('category', 80)->nullable()->after('confidence')->index();
            $table->text('recommendation')->nullable()->after('evidence_json');
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_review_findings', function (Blueprint $table): void {
            $table->dropColumn(['confidence', 'category', 'recommendation']);
        });
    }
};
