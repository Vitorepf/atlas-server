<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\CodeIntelligenceSchemaDriftAuditor;
use Tests\TestCase;

/**
 * Pure unit tests: every fact is INJECTED, so no DB connection is required.
 * Covers healthy, not_migrated, schema_drift (stamped + tables missing),
 * partial table set, and required workspace_id column drift.
 */
class CodeIntelligenceSchemaDriftAuditorTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_code_modules',
        'atlas_engineering_code_symbols',
        'atlas_engineering_doc_links',
        'atlas_engineering_code_file_snapshots',
    ];

    private const MIGRATIONS = [
        '2026_05_02_010000_create_atlas_engineering_code_intelligence_tables',
        '2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table',
        '2026_06_08_233000_add_workspace_id_to_code_intelligence_tables',
    ];

    public function test_reports_healthy_when_all_tables_columns_and_migrations_present(): void
    {
        $auditor = new CodeIntelligenceSchemaDriftAuditor();

        $result = $auditor->audit(
            tableFacts: $this->allTablesPresent(),
            columnFacts: $this->allColumnsPresent(),
            migrationFacts: $this->allMigrationsRan(),
        );

        self::assertSame('atlas.engineering.code_intelligence_schema_drift.v1', $result['schema_version']);
        self::assertSame('healthy', $result['status']);
        self::assertTrue($result['passed']);
        self::assertSame([], $result['missing_tables']);
        self::assertSame([], $result['missing_columns']);
        self::assertSame([], $result['blockers']);
        self::assertSame('none', $result['drift_type']);
        self::assertSame(self::TABLES, $result['required_tables']);
        self::assertCount(3, $result['migration_rows']);
        foreach ($result['migration_rows'] as $row) {
            self::assertTrue($row['ran']);
        }
        self::assertArrayNotHasKey('score', $result);
    }

    public function test_reports_not_migrated_when_neither_migrations_nor_tables_exist(): void
    {
        $auditor = new CodeIntelligenceSchemaDriftAuditor();

        $result = $auditor->audit(
            tableFacts: $this->allTablesAbsent(),
            columnFacts: $this->allColumnsAbsent(),
            migrationFacts: $this->noMigrationsRan(),
        );

        self::assertSame('not_migrated', $result['status']);
        self::assertFalse($result['passed']);
        self::assertSame(self::TABLES, $result['missing_tables']);
        self::assertSame('none', $result['drift_type']);
        self::assertSame([], $result['blockers']);
    }

    public function test_reports_schema_drift_when_migrations_stamped_but_tables_missing(): void
    {
        $auditor = new CodeIntelligenceSchemaDriftAuditor();

        $result = $auditor->audit(
            tableFacts: $this->allTablesAbsent(),
            columnFacts: $this->allColumnsAbsent(),
            migrationFacts: $this->allMigrationsRan(),
        );

        self::assertSame('schema_drift', $result['status']);
        self::assertFalse($result['passed']);
        self::assertSame('stamped_but_tables_missing', $result['drift_type']);
        self::assertSame(self::TABLES, $result['missing_tables']);
        foreach (self::TABLES as $table) {
            self::assertContains('missing_table:'.$table, $result['blockers']);
        }
        self::assertStringContainsString('status=schema_drift', $result['proof_summary']);
    }

    public function test_reports_partial_when_some_tables_present_and_migrations_not_all_stamped(): void
    {
        $auditor = new CodeIntelligenceSchemaDriftAuditor();

        $tables = $this->allTablesAbsent();
        $tables['atlas_engineering_code_modules'] = true;
        $tables['atlas_engineering_code_symbols'] = true;

        $columns = $this->allColumnsAbsent();
        $columns['atlas_engineering_code_modules']['workspace_id'] = true;
        $columns['atlas_engineering_code_symbols']['workspace_id'] = true;

        $migrations = $this->noMigrationsRan();
        $migrations['2026_05_02_010000_create_atlas_engineering_code_intelligence_tables'] = true;

        $result = $auditor->audit(
            tableFacts: $tables,
            columnFacts: $columns,
            migrationFacts: $migrations,
        );

        self::assertSame('partial', $result['status']);
        self::assertFalse($result['passed']);
        self::assertSame('partial_state', $result['drift_type']);
        self::assertContains('atlas_engineering_doc_links', $result['missing_tables']);
        self::assertContains('atlas_engineering_code_file_snapshots', $result['missing_tables']);
        self::assertNotContains('atlas_engineering_code_modules', $result['missing_tables']);
        self::assertContains(
            'missing_migration:2026_06_08_233000_add_workspace_id_to_code_intelligence_tables',
            $result['blockers'],
        );
    }

    public function test_reports_drift_when_workspace_id_column_is_missing(): void
    {
        $auditor = new CodeIntelligenceSchemaDriftAuditor();

        $columns = $this->allColumnsPresent();
        $columns['atlas_engineering_doc_links']['workspace_id'] = false;

        $result = $auditor->audit(
            tableFacts: $this->allTablesPresent(),
            columnFacts: $columns,
            migrationFacts: $this->allMigrationsRan(),
        );

        self::assertSame('schema_drift', $result['status']);
        self::assertFalse($result['passed']);
        self::assertSame('stamped_but_columns_missing', $result['drift_type']);
        self::assertSame([], $result['missing_tables']);
        self::assertSame(['atlas_engineering_doc_links.workspace_id'], $result['missing_columns']);
        self::assertContains('missing_column:atlas_engineering_doc_links.workspace_id', $result['blockers']);
    }

    /** @return array<string, bool> */
    private function allTablesPresent(): array
    {
        return array_fill_keys(self::TABLES, true);
    }

    /** @return array<string, bool> */
    private function allTablesAbsent(): array
    {
        return array_fill_keys(self::TABLES, false);
    }

    /** @return array<string, array<string, bool>> */
    private function allColumnsPresent(): array
    {
        $columns = [];
        foreach (self::TABLES as $table) {
            $columns[$table] = ['workspace_id' => true];
        }

        return $columns;
    }

    /** @return array<string, array<string, bool>> */
    private function allColumnsAbsent(): array
    {
        $columns = [];
        foreach (self::TABLES as $table) {
            $columns[$table] = ['workspace_id' => false];
        }

        return $columns;
    }

    /** @return array<string, bool> */
    private function allMigrationsRan(): array
    {
        return array_fill_keys(self::MIGRATIONS, true);
    }

    /** @return array<string, bool> */
    private function noMigrationsRan(): array
    {
        return array_fill_keys(self::MIGRATIONS, false);
    }
}
