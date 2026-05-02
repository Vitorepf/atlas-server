<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_run_operator_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->string('action', 40)->index();
            $table->string('actor', 120)->default('operator');
            $table->string('status_before', 32)->nullable();
            $table->string('decision_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->string('decision_after', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamp('acted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_run_operator_actions');
    }
};
