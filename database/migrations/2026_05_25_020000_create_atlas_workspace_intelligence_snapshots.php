<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_workspace_intelligence_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->index();
            $table->string('workspace_id', 120)->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_passed')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);
            $table->unsignedInteger('artifacts_count')->default(0);
            $table->json('family_status');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['workspace_id', 'captured_at']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_workspace_intelligence_snapshots');
    }
};
