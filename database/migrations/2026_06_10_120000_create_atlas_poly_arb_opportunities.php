<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_poly_arb_opportunities')) {
            Schema::create('atlas_poly_arb_opportunities', function (Blueprint $table): void {
                $table->id();
                $table->string('event_slug', 180);
                $table->string('kind', 30);
                $table->string('event_title', 300)->nullable();
                $table->string('execution_class', 40);
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->unsignedInteger('observations')->default(1);
                $table->decimal('last_sum', 10, 6);
                $table->decimal('last_profit_per_set', 10, 6);
                $table->decimal('last_sets', 14, 4);
                $table->decimal('last_profit_usd', 12, 4);
                $table->decimal('max_sets', 14, 4);
                $table->decimal('max_profit_usd', 12, 4);
                $table->timestamps();

                $table->unique(['event_slug', 'kind'], 'uq_poly_arb_opportunity');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_poly_arb_opportunities');
    }
};
