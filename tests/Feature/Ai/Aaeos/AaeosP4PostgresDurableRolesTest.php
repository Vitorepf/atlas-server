<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosP4RealOperationGauntlet;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P4: provisioned durable PostgreSQL producer/verifier least-privilege proof.
 * Requires ATLAS_ALLOW_LIVE_DB_TESTS=1 and ATLAS_P4_PG_PRODUCER_URL + VERIFIER_URL
 * (or component envs) pointing at an atlas_p4_* database.
 */
final class AaeosP4PostgresDurableRolesTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertP4Env();
        $this->originalConnection = (string) config('database.default');
        $this->prepareDatabase();
    }

    protected function tearDown(): void
    {
        DB::purge('atlas_p4_pg_producer');
        DB::purge('atlas_p4_pg_verifier');
        DB::setDefaultConnection('atlas_p4_pg_setup');
        try {
            DB::connection('atlas_p4_pg_setup')->unprepared('DROP TABLE IF EXISTS atlas_ledger_events CASCADE');
        } catch (\Throwable) {
        }
        config(['database.default' => $this->originalConnection]);
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('atlas_p4_pg_setup');
        parent::tearDown();
    }

    public function test_p4_producer_and_verifier_are_distinct_least_privilege_identities(): void
    {
        $preflight = AaeosP4RealOperationGauntlet::preflight([
            'ATLAS_P4_PG_PRODUCER_URL' => (string) getenv('ATLAS_P4_PG_PRODUCER_URL'),
            'ATLAS_P4_PG_VERIFIER_URL' => (string) getenv('ATLAS_P4_PG_VERIFIER_URL'),
        ]);
        self::assertTrue($preflight['durable_pg_ready'], json_encode($preflight['blockers']));

        $producer = DB::connection('atlas_p4_pg_producer');
        $identity = (array) $producer->selectOne(<<<'SQL'
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

        self::assertSame('atlas_p4_producer', $identity['session_user']);
        self::assertSame('atlas_p4_producer', $identity['current_user']);
        self::assertSame('atlas_p4_producer', $identity['application_name']);
        self::assertTrue($identity['may_select']);
        self::assertTrue($identity['may_insert']);
        self::assertFalse($identity['may_update']);
        self::assertFalse($identity['may_delete']);
        self::assertFalse($identity['rolsuper']);
        self::assertFalse($identity['rolbypassrls']);
        self::assertFalse($identity['owns_ledger']);

        config(['database.default' => 'atlas_p4_pg_producer']);
        DB::setDefaultConnection('atlas_p4_pg_producer');
        $event = app(AtlasEvidenceLedger::class)->record(LedgerEventType::AaeosCycleRecorded, [
            'p4' => true,
            'mode' => 'dev',
        ], [
            'tenant_id' => 'tenant-p4',
            'operator_id' => 'operator-p4',
            'envelope_id' => 'p4-envelope',
            'correlation_id' => 'p4-journey',
            'scope_type' => 'journey',
            'scope_id' => 'p4-journey',
        ]);
        self::assertNotNull($event);
        self::assertTrue(app(AtlasEvidenceLedger::class)->eventIntegrityValid($event));

        $this->assertQueryRefused(fn () => $producer->update(
            "UPDATE atlas_ledger_events SET emitter_version = 'tampered' WHERE event_id = ?",
            [$event->event_id],
        ));
        $this->assertQueryRefused(fn () => $producer->delete(
            'DELETE FROM atlas_ledger_events WHERE event_id = ?',
            [$event->event_id],
        ));

        $verifier = DB::connection('atlas_p4_pg_verifier');
        $v = (array) $verifier->selectOne(
            "SELECT session_user, current_user, current_setting('application_name') AS application_name",
        );
        self::assertSame('atlas_p4_verifier', $v['session_user']);
        self::assertSame('atlas_p4_verifier', $v['current_user']);
        self::assertSame('atlas_p4_verifier', $v['application_name']);
        self::assertSame(1, (int) $verifier->scalar('SELECT count(*) FROM atlas_ledger_events'));
        $this->assertQueryRefused(fn () => $verifier->insert(
            "INSERT INTO atlas_ledger_events (event_id) VALUES ('forbidden')",
        ));
    }

    private function assertP4Env(): void
    {
        self::assertSame('1', getenv('ATLAS_ALLOW_LIVE_DB_TESTS') ?: null);
        $producer = (string) (getenv('ATLAS_P4_PG_PRODUCER_URL') ?: '');
        $verifier = (string) (getenv('ATLAS_P4_PG_VERIFIER_URL') ?: '');
        self::assertNotSame('', $producer, 'ATLAS_P4_PG_PRODUCER_URL required');
        self::assertNotSame('', $verifier, 'ATLAS_P4_PG_VERIFIER_URL required');
        self::assertNotSame($producer, $verifier);
        self::assertStringContainsString('atlas_p4', $producer);
        self::assertStringContainsString('atlas_p4', $verifier);
    }

    private function prepareDatabase(): void
    {
        foreach (['atlas_p4_pg_setup', 'atlas_p4_pg_producer', 'atlas_p4_pg_verifier'] as $c) {
            DB::purge($c);
        }
        config(['database.default' => 'atlas_p4_pg_setup']);
        DB::setDefaultConnection('atlas_p4_pg_setup');
        $setup = DB::connection('atlas_p4_pg_setup');
        $setup->unprepared('DROP TABLE IF EXISTS atlas_ledger_events CASCADE');
        $setup->unprepared(<<<'SQL'
            DO $block$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_p4_producer') THEN
                    CREATE ROLE atlas_p4_producer LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_p4_verifier') THEN
                    CREATE ROLE atlas_p4_verifier LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
                END IF;
            END;
            $block$;
            CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW; END; $fn$;
        SQL);
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
        $setup->unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA public TO atlas_p4_producer, atlas_p4_verifier;
            REVOKE ALL ON TABLE atlas_ledger_events FROM PUBLIC;
            GRANT SELECT, INSERT ON TABLE atlas_ledger_events TO atlas_p4_producer;
            REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE atlas_ledger_events FROM atlas_p4_producer;
            GRANT SELECT ON TABLE atlas_ledger_events TO atlas_p4_verifier;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE atlas_ledger_events FROM atlas_p4_verifier;
        SQL);
        DB::purge('atlas_p4_pg_producer');
        DB::purge('atlas_p4_pg_verifier');
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
