<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_initiative_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 48);
            $table->string('status', 24)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('scope')->default('{}');
            $table->json('findings')->default('[]');
            $table->json('emitted_inbox_item_ids')->default('[]');
            $table->text('error_message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['kind', 'status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_initiative_runs_updated_at
            BEFORE UPDATE ON atlas_initiative_runs
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_initiative_runs');
    }
};
