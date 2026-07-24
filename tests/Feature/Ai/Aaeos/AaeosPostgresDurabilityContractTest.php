<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AaeosPostgresDurabilityContractTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertLivePostgresContractEnvironment();
        $this->originalConnection = (string) config('database.default');
        $this->prepareDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanupDatabase();
        parent::tearDown();
    }

    public function test_runtime_and_verifier_roles_are_real_least_privilege_postgres_identities(): void
    {
        $runtime = DB::connection('atlas_p2_pg_runtime');
        $identity = (array) $runtime->selectOne(<<<'SQL'
            SELECT session_user, current_user,
                   current_setting('application_name') AS application_name,
                   has_table_privilege(current_user, 'atlas_ledger_events', 'SELECT') AS may_select,
                   has_table_privilege(current_user, 'atlas_ledger_events', 'INSERT') AS may_insert,
                   has_table_privilege(current_user, 'atlas_ledger_events', 'UPDATE') AS may_update,
                   has_table_privilege(current_user, 'atlas_ledger_events', 'DELETE') AS may_delete,
                   r.rolsuper, r.rolbypassrls,
                   pg_get_userbyid(c.relowner) = current_user AS owns_ledger
            FROM pg_roles r
            CROSS JOIN pg_class c
            WHERE r.rolname = current_user AND c.oid = 'atlas_ledger_events'::regclass
        SQL);

        self::assertSame('atlas_p2a1_runtime', $identity['session_user']);
        self::assertSame('atlas_p2a1_runtime', $identity['current_user']);
        self::assertSame('atlas_p2a1_runtime', $identity['application_name']);
        self::assertTrue($identity['may_select']);
        self::assertTrue($identity['may_insert']);
        self::assertFalse($identity['may_update']);
        self::assertFalse($identity['may_delete']);
        self::assertFalse($identity['rolsuper']);
        self::assertFalse($identity['rolbypassrls']);
        self::assertFalse($identity['owns_ledger']);

        config(['database.default' => 'atlas_p2_pg_runtime']);
        DB::setDefaultConnection('atlas_p2_pg_runtime');
        $event = app(AtlasEvidenceLedger::class)->record(LedgerEventType::AaeosCycleRecorded, ['pg' => true], [
            'tenant_id' => 'tenant-pg',
            'operator_id' => 'operator-pg',
            'envelope_id' => 'pg-envelope',
            'correlation_id' => 'pg-journey',
            'scope_type' => 'journey',
            'scope_id' => 'pg-journey',
        ]);
        self::assertNotNull($event);
        self::assertSame('verified', app(AtlasEvidenceLedger::class)->eventIntegrityStatus($event));

        $this->assertQueryRefused(fn () => $runtime->update(
            "UPDATE atlas_ledger_events SET emitter_version = 'tampered' WHERE event_id = ?",
            [$event->event_id],
        ));
        $this->assertQueryRefused(fn () => $runtime->delete(
            'DELETE FROM atlas_ledger_events WHERE event_id = ?',
            [$event->event_id],
        ));
        $this->assertQueryRefused(fn () => $runtime->statement('SET ROLE postgres'));

        $verifier = DB::connection('atlas_p2_pg_verifier');
        $verifierIdentity = (array) $verifier->selectOne(
            "SELECT session_user, current_user, current_setting('application_name') AS application_name",
        );
        self::assertSame('atlas_p2a1_verifier', $verifierIdentity['session_user']);
        self::assertSame('atlas_p2a1_verifier', $verifierIdentity['current_user']);
        self::assertSame('atlas_p2a1_verifier', $verifierIdentity['application_name']);
        self::assertSame(1, (int) $verifier->scalar('SELECT count(*) FROM atlas_ledger_events'));
        $this->assertQueryRefused(fn () => $verifier->insert(
            "INSERT INTO atlas_ledger_events (event_id) VALUES ('forbidden')",
        ));
    }

    private function assertLivePostgresContractEnvironment(): void
    {
        self::assertSame('1', getenv('ATLAS_ALLOW_LIVE_DB_TESTS') ?: null, 'ATLAS_ALLOW_LIVE_DB_TESTS=1 is required.');
        foreach (['ATLAS_TEST_PG_HOST', 'ATLAS_TEST_PG_PORT', 'ATLAS_TEST_PG_DATABASE', 'ATLAS_TEST_PG_USERNAME', 'ATLAS_TEST_PG_PASSWORD'] as $name) {
            self::assertNotFalse(getenv($name), "{$name} must be explicitly present; this contract never skips.");
        }
        self::assertStringStartsWith('atlas_test_', (string) getenv('ATLAS_TEST_PG_DATABASE'));
    }

    private function prepareDatabase(): void
    {
        foreach (['atlas_p2_pg_setup', 'atlas_p2_pg_runtime', 'atlas_p2_pg_verifier'] as $connection) {
            DB::purge($connection);
        }
        config(['database.default' => 'atlas_p2_pg_setup']);
        DB::setDefaultConnection('atlas_p2_pg_setup');
        $setup = DB::connection('atlas_p2_pg_setup');
        $setup->unprepared('DROP TABLE IF EXISTS atlas_ledger_events CASCADE');
        $setup->unprepared(<<<'SQL'
            DO $block$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_p2a1_runtime') THEN
                    CREATE ROLE atlas_p2a1_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_p2a1_verifier') THEN
                    CREATE ROLE atlas_p2a1_verifier LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
                END IF;
            END;
            $block$;
            CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW; END; $fn$;
        SQL);
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
        // Least-privilege grants: setup owns the table; runtime may append/select only;
        // verifier is SELECT-only. No ownership transfer, no UPDATE/DELETE.
        $setup->unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA public TO atlas_p2a1_runtime, atlas_p2a1_verifier;
            REVOKE ALL ON TABLE atlas_ledger_events FROM PUBLIC;
            GRANT SELECT, INSERT ON TABLE atlas_ledger_events TO atlas_p2a1_runtime;
            REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE atlas_ledger_events FROM atlas_p2a1_runtime;
            GRANT SELECT ON TABLE atlas_ledger_events TO atlas_p2a1_verifier;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE atlas_ledger_events FROM atlas_p2a1_verifier;
        SQL);
        DB::purge('atlas_p2_pg_runtime');
        DB::purge('atlas_p2_pg_verifier');
    }

    private function cleanupDatabase(): void
    {
        DB::purge('atlas_p2_pg_runtime');
        DB::purge('atlas_p2_pg_verifier');
        DB::setDefaultConnection('atlas_p2_pg_setup');
        DB::connection('atlas_p2_pg_setup')->unprepared('DROP TABLE IF EXISTS atlas_ledger_events CASCADE');
        config(['database.default' => $this->originalConnection]);
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('atlas_p2_pg_setup');
    }

    private function assertQueryRefused(callable $query): void
    {
        try {
            $query();
            self::fail('PostgreSQL role unexpectedly performed a forbidden operation.');
        } catch (QueryException $exception) {
            self::assertContains($exception->getCode(), ['42501', '55000']);
        }
    }
}
