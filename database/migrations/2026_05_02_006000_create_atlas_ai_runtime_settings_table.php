<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_ai_runtime_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value_json')->default('{}');
            $table->string('updated_by', 120)->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_ai_runtime_settings_updated_at
                BEFORE UPDATE ON atlas_ai_runtime_settings
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_ai_runtime_settings');
    }
};
