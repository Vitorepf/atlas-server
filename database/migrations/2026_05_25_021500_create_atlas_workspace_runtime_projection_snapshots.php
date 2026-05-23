<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_workspace_runtime_projection_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('family', 20)->index();
            $table->string('schema_version', 120)->index();
            $table->string('runtime_hash', 64)->index();
            $table->string('projection_hash', 64)->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->unique(['runtime_hash', 'family']);
            $table->index(['workspace_id', 'family', 'captured_at']);
            $table->index(['workspace_id', 'family', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_workspace_runtime_projection_snapshots');
    }
};
