<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_poly_arb_opportunities')
            && ! Schema::hasColumn('atlas_poly_arb_opportunities', 'volume_24hr')) {
            Schema::table('atlas_poly_arb_opportunities', function (Blueprint $table): void {
                $table->decimal('volume_24hr', 18, 2)->nullable();
                $table->decimal('liquidity', 18, 2)->nullable();
                // Phantom-liquidity guard: a persistent "opportunity" in a market
                // nobody trades is probably an unfillable stale book, not free money.
                $table->boolean('dead_book')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_poly_arb_opportunities')
            && Schema::hasColumn('atlas_poly_arb_opportunities', 'volume_24hr')) {
            Schema::table('atlas_poly_arb_opportunities', function (Blueprint $table): void {
                $table->dropColumn(['volume_24hr', 'liquidity', 'dead_book']);
            });
        }
    }
};
