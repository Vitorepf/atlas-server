<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_surface_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->index();
            $table->uuid('session_id')->index();
            $table->string('from_surface', 80);
            $table->string('to_surface', 80);
            $table->string('status', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_surface_handoffs');
    }
};
