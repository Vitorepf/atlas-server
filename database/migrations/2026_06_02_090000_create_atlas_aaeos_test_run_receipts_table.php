<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B3 / criterion C2 — GREEN-RUN RECEIPTS for the AAEOS capability truth ledger.
 *
 * A row here is PROOF that the named test of a capability ACTUALLY RAN and what the
 * outcome was. It is the difference between "a *Test* symbol exists in the index"
 * (existence-only, what the resolver checks) and "that test ran GREEN for this
 * capability against this code" (what `verified` must now mean).
 *
 * Written ONLY by the opt-in `atlas:aaeos:verify-tests` command (the expensive step
 * that spawns a real PHPUnit process) — NEVER by a maturity READ. The truth service
 * READS this table to decide whether a capability may reach the `verified` tier.
 * Degrade-safe by construction: when this table is absent, no capability is
 * verified-by-existence (the truth service fails toward `partial`).
 *
 * Idempotency: keyed by (capability_id, test_ref) — a re-run UPDATES the same row
 * (latest outcome + commit_stamp + ran_at wins). commit_stamp records WHICH code the
 * run proved, so a stale green can be told apart from a fresh one in audit.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_aaeos_test_run_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('capability_id', 300)->index();
            // The test the capability named (class or class::method as declared in evidence_refs).
            $table->string('test_ref', 400)->index();
            // The exact --filter argument the run used (class or method short name).
            $table->string('filter', 400);
            // The honest outcome: true ONLY when the process exited 0 AND >=1 test ran.
            $table->boolean('passed')->default(false)->index();
            // How many tests the filtered run actually executed (0 => "No tests executed", never green).
            $table->unsignedInteger('tests_run')->default(0);
            $table->integer('exit_code')->nullable();
            // git HEAD short hash at run time — WHICH code this run proved (audit only;
            // the WRONG granularity to gate verified — see the content hashes below).
            $table->string('commit_stamp', 64)->nullable()->index();
            // B3 freshness (criterion C2) — bind the green run to the CONTENT it proved so
            // verified DECAYS when the code or test changes (keyed on the capability's OWN
            // files, NOT global HEAD). test_file_hash = sha256 of the resolved test class
            // file; impl_files_hash = sha256 over the resolved implementation file(s).
            // Nullable: a receipt that predates freshness carries NULL (grandfathered, no
            // claim) until atlas:aaeos:verify-tests re-runs and stamps fresh hashes.
            $table->string('test_file_hash', 64)->nullable();
            $table->string('impl_files_hash', 64)->nullable();
            // Tail of the runner output, for audit (never the whole log).
            $table->text('output_tail')->nullable();
            $table->string('runner', 120)->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('ran_at')->nullable()->index();
            $table->timestamps();

            // One receipt per (capability, test ref): re-running updates in place.
            $table->unique(['capability_id', 'test_ref'], 'uniq_atlas_aaeos_test_run_receipt');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_aaeos_test_run_receipts_updated_at
                BEFORE UPDATE ON atlas_aaeos_test_run_receipts
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_aaeos_test_run_receipts');
    }
};
