<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3 / criterion C2 (FRESHNESS) — bind a GREEN-RUN RECEIPT to the CONTENT of the
 * code+test it proved, so "verified" decays the moment that content changes.
 *
 * `commit_stamp` (in the base table) records WHICH git HEAD a run proved — useful for
 * audit, but the WRONG granularity to gate `verified`: every unrelated commit would
 * move HEAD and wrongly invalidate every receipt. These two CONTENT hashes are the
 * correct granularity — keyed on the capability's OWN files only:
 *
 *   - test_file_hash  : sha256 of the resolved test class FILE's content.
 *   - impl_files_hash : sha256 over the content of the implementation file(s) the
 *                       capability's {kind: symbol} evidence_refs resolve to (the
 *                       symbol file_path(s) from the Code Intelligence index,
 *                       combined deterministically).
 *
 * On a maturity READ the truth service recomputes these from the live files (cheap —
 * hashing a few files, never running tests) and a receipt grants `verified` only when
 * BOTH stored hashes still equal the current ones. A stale hash (code or test edited
 * since the run) degrades the capability away from verified until re-verified.
 *
 * Nullable + additive: receipts written BEFORE this migration carry NULL hashes and are
 * grandfathered (a NULL stored hash makes no freshness CLAIM, so it is not treated as a
 * tamper); the freshness DROP is enforced for any receipt that DOES carry a hash. Once
 * `atlas:aaeos:verify-tests` re-runs, every fresh receipt carries both hashes.
 *
 * Idempotent / hasColumn-guarded: on a FRESH install the base receipts migration already
 * creates these two columns, so this migration no-ops. It exists for an ALREADY-migrated
 * database (the real runtime) where the base table predates the freshness columns.
 *
 * @see app/Services/Ai/Aaeos/AtlasAaeosTestExecutionService.php
 * @see app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            return;
        }

        Schema::table('atlas_aaeos_test_run_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_aaeos_test_run_receipts', 'test_file_hash')) {
                // sha256 hex of the resolved test class file content at run time.
                $table->string('test_file_hash', 64)->nullable()->after('commit_stamp');
            }
            if (! Schema::hasColumn('atlas_aaeos_test_run_receipts', 'impl_files_hash')) {
                // sha256 hex over the resolved implementation file(s) content at run time.
                $table->string('impl_files_hash', 64)->nullable()->after('test_file_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            return;
        }

        Schema::table('atlas_aaeos_test_run_receipts', function (Blueprint $table): void {
            foreach (['test_file_hash', 'impl_files_hash'] as $column) {
                if (Schema::hasColumn('atlas_aaeos_test_run_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
