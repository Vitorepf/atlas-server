<?php

namespace App\Services\Engineering;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Facts-only auditor that distinguishes:
 *   - healthy           : required tables present (with required columns) and migrations stamped.
 *   - not_migrated      : required migrations NOT stamped AND required tables absent.
 *   - schema_drift      : required migrations stamped as ran BUT some required tables/columns absent.
 *   - partial           : some required tables present, some absent, and migrations not (all) stamped.
 *
 * The auditor returns a deterministic envelope and emits NO scalar score.
 * Inject {@see $tableFacts} and {@see $migrationFacts} to keep unit tests DB-free.
 */
class CodeIntelligenceSchemaDriftAuditor
{
    public const SCHEMA_VERSION = 'atlas.engineering.code_intelligence_schema_drift.v1';

    /** Required Code Intelligence tables (covered by the three migrations listed in scope_in). */
    private const REQUIRED_TABLES = [
        'atlas_engineering_code_modules',
        'atlas_engineering_code_symbols',
        'atlas_engineering_doc_links',
        'atlas_engineering_code_file_snapshots',
    ];

    /** Required columns per table — every Code Intelligence table must carry workspace_id (AP-815/0608). */
    private const REQUIRED_COLUMNS = [
        'atlas_engineering_code_modules' => ['workspace_id'],
        'atlas_engineering_code_symbols' => ['workspace_id'],
        'atlas_engineering_doc_links' => ['workspace_id'],
        'atlas_engineering_code_file_snapshots' => ['workspace_id'],
    ];

    /** Required migration filenames (without .php) that stamp the schema. */
    private const REQUIRED_MIGRATIONS = [
        '2026_05_02_010000_create_atlas_engineering_code_intelligence_tables',
        '2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table',
        '2026_06_08_233000_add_workspace_id_to_code_intelligence_tables',
    ];

    /**
     * @param  array<string, bool>|null  $tableFacts          map of table => exists (when null: probe Schema::hasTable)
     * @param  array<string, array<string, bool>>|null  $columnFacts  map of table => column => exists
     * @param  array<string, bool>|null  $migrationFacts      map of migration_name => ran (when null: probe migrations table)
     */
    public function audit(
        ?array $tableFacts = null,
        ?array $columnFacts = null,
        ?array $migrationFacts = null,
    ): array {
        $tables = $this->resolveTableFacts($tableFacts);
        $columns = $this->resolveColumnFacts($columnFacts, $tables);
        $migrations = $this->resolveMigrationFacts($migrationFacts);

        $missingTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (! ($tables[$table] ?? false)) {
                $missingTables[] = $table;
            }
        }

        $missingColumns = [];
        foreach (self::REQUIRED_COLUMNS as $table => $required) {
            if (! ($tables[$table] ?? false)) {
                continue;
            }
            foreach ($required as $column) {
                if (! ($columns[$table][$column] ?? false)) {
                    $missingColumns[] = $table.'.'.$column;
                }
            }
        }

        $missingMigrations = [];
        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            if (! ($migrations[$migration] ?? false)) {
                $missingMigrations[] = $migration;
            }
        }

        $allTablesPresent = $missingTables === [];
        $allColumnsPresent = $missingColumns === [];
        $allMigrationsRan = $missingMigrations === [];
        $allTablesAbsent = count($missingTables) === count(self::REQUIRED_TABLES);

        [$status, $driftType] = $this->classify(
            allTablesPresent: $allTablesPresent,
            allColumnsPresent: $allColumnsPresent,
            allMigrationsRan: $allMigrationsRan,
            allTablesAbsent: $allTablesAbsent,
        );

        $blockers = $this->blockers($status, $missingTables, $missingColumns, $missingMigrations);
        $passed = $status === 'healthy';

        return [
            'schema' => self::SCHEMA_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'passed' => $passed,
            'required_tables' => self::REQUIRED_TABLES,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'required_migrations' => self::REQUIRED_MIGRATIONS,
            'migration_rows' => $this->migrationRows($migrations),
            'drift_type' => $driftType,
            'blockers' => $blockers,
            'proof_summary' => $this->proofSummary(
                $status,
                $driftType,
                $missingTables,
                $missingColumns,
                $missingMigrations,
            ),
        ];
    }

    /**
     * @param  array<string, bool>|null  $tableFacts
     * @return array<string, bool>
     */
    private function resolveTableFacts(?array $tableFacts): array
    {
        $facts = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if ($tableFacts !== null) {
                $facts[$table] = (bool) ($tableFacts[$table] ?? false);
                continue;
            }
            try {
                $facts[$table] = Schema::hasTable($table);
            } catch (Throwable) {
                $facts[$table] = false;
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, array<string, bool>>|null  $columnFacts
     * @param  array<string, bool>  $tables
     * @return array<string, array<string, bool>>
     */
    private function resolveColumnFacts(?array $columnFacts, array $tables): array
    {
        $facts = [];
        foreach (self::REQUIRED_COLUMNS as $table => $required) {
            foreach ($required as $column) {
                if ($columnFacts !== null) {
                    $facts[$table][$column] = (bool) ($columnFacts[$table][$column] ?? false);
                    continue;
                }
                if (! ($tables[$table] ?? false)) {
                    $facts[$table][$column] = false;
                    continue;
                }
                try {
                    $facts[$table][$column] = Schema::hasColumn($table, $column);
                } catch (Throwable) {
                    $facts[$table][$column] = false;
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, bool>|null  $migrationFacts
     * @return array<string, bool>
     */
    private function resolveMigrationFacts(?array $migrationFacts): array
    {
        $facts = [];
        if ($migrationFacts !== null) {
            foreach (self::REQUIRED_MIGRATIONS as $migration) {
                $facts[$migration] = (bool) ($migrationFacts[$migration] ?? false);
            }

            return $facts;
        }

        $rows = [];
        try {
            if (Schema::hasTable('migrations')) {
                $rows = DB::table('migrations')
                    ->whereIn('migration', self::REQUIRED_MIGRATIONS)
                    ->pluck('migration')
                    ->all();
            }
        } catch (Throwable) {
            $rows = [];
        }

        $ranSet = array_flip($rows);
        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            $facts[$migration] = isset($ranSet[$migration]);
        }

        return $facts;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function classify(
        bool $allTablesPresent,
        bool $allColumnsPresent,
        bool $allMigrationsRan,
        bool $allTablesAbsent,
    ): array {
        if ($allTablesPresent && $allColumnsPresent && $allMigrationsRan) {
            return ['healthy', 'none'];
        }

        if (! $allMigrationsRan && $allTablesAbsent) {
            return ['not_migrated', 'none'];
        }

        if ($allMigrationsRan && ! $allTablesPresent && $allTablesAbsent) {
            return ['schema_drift', 'stamped_but_tables_missing'];
        }

        if ($allMigrationsRan && ! $allTablesPresent) {
            return ['schema_drift', 'stamped_but_tables_partial'];
        }

        if ($allMigrationsRan && $allTablesPresent && ! $allColumnsPresent) {
            return ['schema_drift', 'stamped_but_columns_missing'];
        }

        return ['partial', 'partial_state'];
    }

    /**
     * @param  array<int, string>  $missingTables
     * @param  array<int, string>  $missingColumns
     * @param  array<int, string>  $missingMigrations
     * @return array<int, string>
     */
    private function blockers(
        string $status,
        array $missingTables,
        array $missingColumns,
        array $missingMigrations,
    ): array {
        if ($status === 'healthy' || $status === 'not_migrated') {
            return [];
        }

        $blockers = [];
        foreach ($missingTables as $table) {
            $blockers[] = 'missing_table:'.$table;
        }
        foreach ($missingColumns as $column) {
            $blockers[] = 'missing_column:'.$column;
        }
        foreach ($missingMigrations as $migration) {
            $blockers[] = 'missing_migration:'.$migration;
        }

        return $blockers;
    }

    /**
     * @param  array<string, bool>  $migrations
     * @return array<int, array{migration:string,ran:bool}>
     */
    private function migrationRows(array $migrations): array
    {
        $rows = [];
        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            $rows[] = [
                'migration' => $migration,
                'ran' => (bool) ($migrations[$migration] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $missingTables
     * @param  array<int, string>  $missingColumns
     * @param  array<int, string>  $missingMigrations
     */
    private function proofSummary(
        string $status,
        string $driftType,
        array $missingTables,
        array $missingColumns,
        array $missingMigrations,
    ): string {
        return sprintf(
            'status=%s drift=%s missing_tables=%d missing_columns=%d missing_migrations=%d',
            $status,
            $driftType,
            count($missingTables),
            count($missingColumns),
            count($missingMigrations),
        );
    }
}
