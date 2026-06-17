<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACDE QA5 — TEST-COVERAGE EDGE LEDGER (the broader-regression gate learns CROSS-MODULE coverage).
 *
 * The gate selects which suites to run from a STATIC subtree map (app/Services/Ai/AutonomousEvolution
 * -> tests/Feature/Loop, …) plus the convention sibling. That map is blind to a test in module X that
 * exercises a class in module Y — exactly the cross-test regression the gate exists to catch (a loop
 * engine change once broke a DIFFERENT test). This durable ledger records OBSERVED source_path -> test_path
 * coverage edges (from a real coverage source, never fabricated); AtlasLoopBroaderRegressionGate::
 * selectTestPaths UNIONS the ledger's known covering suites onto the static selection when armed.
 *
 * Safety is MONOTONIC: the union can only ever ADD suites to a gate run, never remove one — a noisy edge
 * costs an extra GREEN suite, never hides a regression. Default OFF => the ledger is never consulted =>
 * byte-identical selection. Idempotent (Schema::hasTable guard) — never hand-stamped. Propose-only
 * invariant untouched: a NEW telemetry table nothing in the never-merge layer depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_test_coverage_edges')) {
            Schema::create('atlas_loop_test_coverage_edges', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('source_path');                       // app/*.php source the test exercises
                $table->string('test_path');                         // tests/*.php suite that covers it
                $table->unsignedInteger('observed_count')->default(1); // how many coverage runs saw this edge
                $table->timestamp('last_observed_at')->nullable();
                $table->timestamps();
                $table->unique(['source_path', 'test_path']);
                $table->index('source_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_test_coverage_edges')) {
            Schema::dropIfExists('atlas_loop_test_coverage_edges');
        }
    }
};
