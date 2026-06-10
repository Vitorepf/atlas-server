<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-side + merge extension of the Polymarket executor tables.
 *
 * Purely ADDITIVE (nullable / defaulted columns) so the proven long-side path
 * keeps writing exactly the same rows it does today. The short executor mints a
 * full set on-chain ($1/set + gas), sells the sellable legs on the CLOB, and
 * keeps any unsold leg as a freeroll; the long executor may now realize early
 * by merging the held set back to $1 instead of carrying to resolution. These
 * columns record the new on-chain transactions and the sell/freeroll split.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_poly_exec_baskets')) {
            Schema::table('atlas_poly_exec_baskets', function (Blueprint $table): void {
                if (! Schema::hasColumn('atlas_poly_exec_baskets', 'mint_tx_hash')) {
                    $table->string('mint_tx_hash', 120)->nullable();   // short: on-chain splitPosition tx
                }
                if (! Schema::hasColumn('atlas_poly_exec_baskets', 'merge_tx_hash')) {
                    $table->string('merge_tx_hash', 120)->nullable();  // long-realize OR short-unwind mergePositions tx
                }
                if (! Schema::hasColumn('atlas_poly_exec_baskets', 'legs_sold')) {
                    $table->unsignedSmallInteger('legs_sold')->default(0);       // short: legs sold on the CLOB
                }
                if (! Schema::hasColumn('atlas_poly_exec_baskets', 'legs_freeroll')) {
                    $table->unsignedSmallInteger('legs_freeroll')->default(0);   // short: unsold legs held to resolution
                }
                if (! Schema::hasColumn('atlas_poly_exec_baskets', 'cash_in_usd')) {
                    // Cash banked NOW (short sell proceeds, or long merge proceeds). Distinct from
                    // realized_pnl_usd (net) and realized_cost_usd (capital out, incl. mint).
                    $table->decimal('cash_in_usd', 12, 4)->default(0);
                }
                if (! Schema::hasColumn('atlas_poly_exec_baskets', 'realize_method')) {
                    // hold | merge | sold | sold_partial — how the basket banked its result.
                    $table->string('realize_method', 16)->nullable();
                }
            });
        }

        if (Schema::hasTable('atlas_poly_exec_legs')) {
            Schema::table('atlas_poly_exec_legs', function (Blueprint $table): void {
                if (! Schema::hasColumn('atlas_poly_exec_legs', 'side')) {
                    $table->string('side', 4)->default('buy');   // buy (long) | sell (short)
                }
                if (! Schema::hasColumn('atlas_poly_exec_legs', 'sold_size')) {
                    $table->decimal('sold_size', 14, 4)->default(0);
                }
                if (! Schema::hasColumn('atlas_poly_exec_legs', 'sold_proceeds_usd')) {
                    $table->decimal('sold_proceeds_usd', 12, 4)->default(0);
                }
                if (! Schema::hasColumn('atlas_poly_exec_legs', 'sell_order_id')) {
                    $table->string('sell_order_id', 120)->nullable();
                }
                if (! Schema::hasColumn('atlas_poly_exec_legs', 'freeroll')) {
                    $table->boolean('freeroll')->default(false); // unsold short leg held to resolution
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_poly_exec_baskets')) {
            Schema::table('atlas_poly_exec_baskets', function (Blueprint $table): void {
                foreach (['mint_tx_hash', 'merge_tx_hash', 'legs_sold', 'legs_freeroll', 'cash_in_usd', 'realize_method'] as $c) {
                    if (Schema::hasColumn('atlas_poly_exec_baskets', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('atlas_poly_exec_legs')) {
            Schema::table('atlas_poly_exec_legs', function (Blueprint $table): void {
                foreach (['side', 'sold_size', 'sold_proceeds_usd', 'sell_order_id', 'freeroll'] as $c) {
                    if (Schema::hasColumn('atlas_poly_exec_legs', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
