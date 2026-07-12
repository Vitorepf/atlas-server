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

        if (! Schema::hasColumn('ai_forge_intakes', 'commissioning_hash')) {
            Schema::table('ai_forge_intakes', function (Blueprint $table): void {
                $table->string('commissioning_hash', 64)->nullable();
            });
        }

        $indexes = collect(Schema::getIndexes('ai_forge_intakes'));
        if (! $indexes->contains(fn (array $index): bool => $index['name'] === 'uniq_ai_forge_intakes_commissioning_hash')) {
            Schema::table('ai_forge_intakes', function (Blueprint $table): void {
                $table->unique('commissioning_hash', 'uniq_ai_forge_intakes_commissioning_hash');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('ai_forge_intakes'));
        if ($indexes->contains(fn (array $index): bool => $index['name'] === 'uniq_ai_forge_intakes_commissioning_hash')) {
            Schema::table('ai_forge_intakes', function (Blueprint $table): void {
                $table->dropUnique('uniq_ai_forge_intakes_commissioning_hash');
            });
        }

        if (Schema::hasColumn('ai_forge_intakes', 'commissioning_hash')) {
            Schema::table('ai_forge_intakes', function (Blueprint $table): void {
                $table->dropColumn('commissioning_hash');
            });
        }
    }
};
