<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3 — registro durável de mission deliveries disparadas via HTTP/Job, para
 * polling do resultado (a cadeia CLI atlas:mission:deliver já era completa;
 * isto é o fio request→exec→cert acessível por API). Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_mission_deliveries')) {
            return;
        }

        Schema::create('atlas_mission_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.mission_delivery_record.v1');
            $table->text('request');
            $table->string('status', 40)->default('queued')->index(); // queued|running|delivered|blocked|failed
            $table->string('requested_via', 40)->default('http');
            $table->string('operator_id', 120)->nullable();
            $table->string('mission_id', 120)->nullable()->index();
            $table->string('branch', 500)->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_mission_deliveries_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_mission_deliveries');
    }
};
