<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AP-815 B1 (schema backstop) — convert the code-graph world-model JSON payload
 * columns from pgsql `json` to `jsonb`.
 *
 * Root cause of SQLSTATE[42883] "could not identify an equality operator for type
 * json": pgsql's `json` type has NO equality operator, so any whole-row comparison
 * over these tables fails — a UNION's implicit DISTINCT, SELECT DISTINCT, GROUP BY,
 * etc. The traversal hot path (edgesTouching() in AtlasOpenBrainMcpService) reads a
 * node's edges by UNIONing two `SELECT *` over ai_codebase_world_model_edges, and
 * `select *` drags the `metadata` json column into the dedup → crash.
 *
 * edgesTouching() already dodges it at the call site (unionAll + PHP dedup). This
 * migration is the STRUCTURAL fix: `jsonb` HAS `=`/ordering operators, so the entire
 * "json has no = operator" class is closed for these tables — any future UNION /
 * DISTINCT / GROUP BY over them is safe. jsonb is also a strict upgrade for these
 * metadata payloads (binary, indexable); the `array` casts on the models are
 * unchanged (Laravel's json<->array cast is identical for json and jsonb).
 *
 * pgsql-only. On sqlite/other drivers `json` is just TEXT (has `=`, no such bug), so
 * this is a guarded no-op there. Idempotent: only ALTERs columns still typed `json`,
 * so re-runs and partial prior applies are safe. Reversible (jsonb -> json) in down().
 */
return new class extends Migration
{
    /** @var array<string,list<string>> table => json payload columns to convert */
    private const TARGETS = [
        // The column dragged into the failing UNION today:
        'ai_codebase_world_model_edges' => ['metadata'],
        // Closed for symmetry — same `select *` read path, same hazard class:
        'ai_codebase_world_model_nodes' => ['capabilities', 'risks', 'metadata'],
    ];

    public function up(): void
    {
        $this->convertColumns('json', 'jsonb');
    }

    public function down(): void
    {
        $this->convertColumns('jsonb', 'json');
    }

    private function convertColumns(string $from, string $to): void
    {
        // Only pgsql distinguishes json/jsonb and lacks a json `=` operator.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // Idempotent: skip anything not currently the source type.
                if ($this->currentType($table, $column) !== $from) {
                    continue;
                }

                // USING <col>::<to> is always valid: every json value is valid jsonb
                // and vice-versa; NULLs stay NULL. Identifiers are hard-coded
                // constants (no user input) but quoted for correctness.
                DB::statement(sprintf(
                    'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE %s USING "%s"::%s',
                    $table,
                    $column,
                    $to,
                    $column,
                    $to,
                ));
            }
        }
    }

    private function currentType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns '
            .'where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$table, $column],
        );

        return $row->data_type ?? null;
    }
};
