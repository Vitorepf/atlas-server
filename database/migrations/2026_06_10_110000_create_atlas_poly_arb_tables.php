<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_poly_arb_signals')) {
            Schema::create('atlas_poly_arb_signals', function (Blueprint $table): void {
                $table->id();
                $table->string('session_id', 40)->nullable()->index();
                $table->string('event_slug', 180)->index();
                $table->string('event_title', 300)->nullable();
                $table->string('kind', 30)->index();
                $table->string('execution_class', 40);
                $table->unsignedInteger('n_legs');
                $table->decimal('sum', 10, 6);
                $table->decimal('profit_per_set', 10, 6);
                $table->decimal('sets', 14, 4);
                $table->decimal('profit_usd', 12, 4);
                $table->decimal('cost_usd', 14, 4);
                $table->json('legs');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_poly_arb_scans')) {
            Schema::create('atlas_poly_arb_scans', function (Blueprint $table): void {
                $table->id();
                $table->string('session_id', 40)->nullable()->index();
                $table->unsignedInteger('scanned_events');
                $table->unsignedInteger('eligible_events');
                $table->unsignedInteger('shortlisted');
                $table->unsignedInteger('verified');
                $table->unsignedInteger('signals_found');
                $table->decimal('best_long_sum', 10, 6)->nullable();
                $table->decimal('best_short_sum', 10, 6)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_poly_arb_signals');
        Schema::dropIfExists('atlas_poly_arb_scans');
    }
};
