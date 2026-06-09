<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP-815 C2 — store file mtime + raw content hash on the snapshot so a warm re-index can
 * skip reading + hashing unchanged files (mtime short-circuit). Additive + idempotent.
 *
 * `file_hash` is the RAW sha256(content); `source_hash` stays the extractor-versioned
 * cache key. Keeping the raw hash lets the short-circuit reconstruct the file symbol's
 * metadata + re-validate the extractor version without re-reading the file.
 */
return new class extends Migration
{
    private const TABLE = 'atlas_engineering_code_file_snapshots';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'mtime')) {
                $table->unsignedBigInteger('mtime')->nullable()->after('file_size');
            }
            if (! Schema::hasColumn(self::TABLE, 'file_hash')) {
                $table->string('file_hash', 64)->nullable()->after('mtime');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['mtime', 'file_hash'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
