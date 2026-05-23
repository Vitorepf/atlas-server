<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_workspace_artifact_timeline_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('artifact_hash', 64)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('event_type', 80)->index();
            $table->string('event_status', 40)->index();
            $table->string('route_target', 80)->nullable()->index();
            $table->string('consumer', 120)->nullable()->index();
            $table->json('payload');
            $table->string('event_hash', 64)->unique();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['workspace_id', 'artifact_hash', 'occurred_at']);
            $table->index(['workspace_id', 'event_type']);
        });

        Schema::create('atlas_workspace_artifact_retirement_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('artifact_hash', 64)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('reason', 200);
            $table->string('status', 40)->index();
            $table->boolean('replacement_required')->default(false)->index();
            $table->json('payload');
            $table->string('proposal_hash', 64)->unique();
            $table->timestamp('proposed_at')->index();
            $table->timestamps();

            $table->index(['workspace_id', 'artifact_hash']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_workspace_artifact_retirement_proposals');
        Schema::dropIfExists('atlas_workspace_artifact_timeline_events');
    }
};
