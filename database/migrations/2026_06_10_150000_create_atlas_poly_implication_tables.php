<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_poly_implication_scans')) {
            Schema::create('atlas_poly_implication_scans', function (Blueprint $table): void {
                $table->id();
                $table->string('session_id', 40)->index();
                $table->unsignedInteger('scanned_events');
                $table->unsignedInteger('markets_seen');
                $table->unsignedInteger('pairs');
                $table->unsignedInteger('shortlisted');
                $table->unsignedInteger('verified');
                $table->unsignedInteger('signals_found');
                // Max cached bid(A) - ask(B) over all pairs: > 0 means the cache
                // saw a violation; near 0 says how CLOSE the universe runs to one.
                $table->decimal('best_gap', 10, 6)->nullable();
                $table->json('pairs_by_family')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_poly_implication_signals')) {
            Schema::create('atlas_poly_implication_signals', function (Blueprint $table): void {
                $table->id();
                $table->string('session_id', 40)->index();
                $table->string('family', 40);
                $table->string('implicant_slug', 180);
                $table->string('implied_slug', 180);
                $table->string('implicant_question', 300)->nullable();
                $table->string('implied_question', 300)->nullable();
                $table->string('execution_class', 40);
                $table->decimal('gap', 10, 6);
                $table->decimal('cached_gap', 10, 6)->nullable();
                $table->decimal('shares', 14, 4);
                $table->decimal('profit_usd', 12, 4);
                $table->decimal('cost_usd', 12, 4);
                $table->json('evidence')->nullable();
                $table->json('legs');
                $table->timestamps();

                $table->index(['implicant_slug', 'implied_slug'], 'ix_poly_implication_signal_pair');
            });
        }

        if (! Schema::hasTable('atlas_poly_implication_opportunities')) {
            Schema::create('atlas_poly_implication_opportunities', function (Blueprint $table): void {
                $table->id();
                $table->string('implicant_slug', 180);
                $table->string('implied_slug', 180);
                $table->string('family', 40);
                $table->string('implicant_question', 300)->nullable();
                $table->string('implied_question', 300)->nullable();
                $table->string('execution_class', 40);
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->unsignedInteger('observations')->default(1);
                $table->decimal('last_gap', 10, 6);
                $table->decimal('last_shares', 14, 4);
                $table->decimal('last_profit_usd', 12, 4);
                $table->decimal('max_shares', 14, 4);
                $table->decimal('max_profit_usd', 12, 4);
                $table->decimal('volume_24hr', 18, 2)->nullable();
                $table->decimal('liquidity', 18, 2)->nullable();
                // Phantom-liquidity guard: a persistent "violation" in markets
                // nobody trades is probably an unfillable stale book, not free money.
                $table->boolean('dead_book')->default(false)->index();
                $table->timestamps();

                $table->unique(['implicant_slug', 'implied_slug'], 'uq_poly_implication_opportunity');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_poly_implication_opportunities');
        Schema::dropIfExists('atlas_poly_implication_signals');
        Schema::dropIfExists('atlas_poly_implication_scans');
    }
};
