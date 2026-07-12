<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guardião de isolamento do DB de teste.
 *
 * Se este teste falhar, a suite está apontada para um banco REAL — o modo de
 * falha que em 02/07/2026 destruiu tabelas de produção (migration->down() e
 * RefreshDatabase rodando contra o pgsql vivo via env vazado do launcher).
 * Os pins em phpunit.xml usam force="true"; este teste prova que eles venceram.
 */
final class TestDatabaseIsolationGuardTest extends TestCase
{
    public function test_default_connection_is_in_memory_sqlite(): void
    {
        self::assertSame('sqlite', DB::connection()->getDriverName(), 'suite must never run on a live database');
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_phpunit_connection_never_resolves_to_canonical_pgsql_5433(): void
    {
        $connection = config('database.default');
        $database = config('database.connections.'.$connection);

        self::assertIsArray($database);
        self::assertFalse(
            ($database['driver'] ?? null) === 'pgsql' && (int) ($database['port'] ?? 5432) === 5433,
            'phpunit must never resolve RefreshDatabase/migrations to canonical pgsql@5433',
        );
    }
}
