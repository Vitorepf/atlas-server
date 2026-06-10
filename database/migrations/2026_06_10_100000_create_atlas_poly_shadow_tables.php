<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_poly_shadow_windows')) {
            Schema::create('atlas_poly_shadow_windows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('window_start')->unique();
                $table->string('market_slug', 120)->nullable();
                $table->decimal('s_start', 16, 6)->nullable();
                $table->unsignedBigInteger('s_start_ts_ms')->nullable();
                $table->decimal('s_end', 16, 6)->nullable();
                $table->unsignedBigInteger('s_end_ts_ms')->nullable();
                // Our Binance-proxy outcome vs the official Chainlink-resolved outcome:
                // the divergence rate IS the measured basis risk.
                $table->string('outcome', 10)->nullable()->index();
                $table->string('market_outcome', 10)->nullable();
                $table->boolean('basis_match')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_poly_shadow_quotes')) {
            Schema::create('atlas_poly_shadow_quotes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('window_start')->index();
                $table->double('captured_elapsed_sec');
                $table->decimal('fv_up', 8, 6);
                $table->decimal('ask_up', 8, 6)->nullable();
                $table->decimal('bid_up', 8, 6)->nullable();
                $table->decimal('ask_down', 8, 6)->nullable();
                $table->decimal('bid_down', 8, 6)->nullable();
                $table->double('sigma_per_second')->nullable();
                $table->string('regime', 20)->nullable();
                $table->string('outcome', 10)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_poly_shadow_trades')) {
            Schema::create('atlas_poly_shadow_trades', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('window_start')->index();
                $table->string('market_slug', 120)->nullable();
                $table->string('side', 10);
                $table->string('leg', 20)->index();
                $table->double('entered_elapsed_sec');
                $table->decimal('fv', 8, 6);
                $table->decimal('ask', 8, 6);
                $table->decimal('fee_per_share', 8, 6)->default(0);
                $table->decimal('edge', 8, 6);
                $table->double('sigma_per_second')->nullable();
                $table->string('regime', 20)->nullable();
                $table->decimal('stake', 12, 2);
                $table->decimal('shares', 14, 4);
                $table->decimal('binance_price_entry', 16, 6)->nullable();
                $table->string('status', 12)->default('open')->index();
                $table->string('outcome', 10)->nullable();
                $table->decimal('pnl', 12, 4)->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->string('session_id', 40)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_poly_shadow_state')) {
            Schema::create('atlas_poly_shadow_state', function (Blueprint $table): void {
                $table->string('key', 80)->primary();
                $table->json('value');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_poly_shadow_trades');
        Schema::dropIfExists('atlas_poly_shadow_quotes');
        Schema::dropIfExists('atlas_poly_shadow_windows');
        Schema::dropIfExists('atlas_poly_shadow_state');
    }
};
