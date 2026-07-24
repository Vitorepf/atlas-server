<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AaeosPostgresRestoreIdentityTest extends TestCase
{
    private string $originalConnection;

    private string $restoreDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('1', getenv('ATLAS_ALLOW_LIVE_DB_TESTS') ?: null, 'Live PostgreSQL is mandatory.');
        foreach (['ATLAS_TEST_PG_HOST', 'ATLAS_TEST_PG_PORT', 'ATLAS_TEST_PG_DATABASE', 'ATLAS_TEST_PG_USERNAME', 'ATLAS_TEST_PG_PASSWORD'] as $name) {
            self::assertNotFalse(getenv($name), "{$name} must be explicitly present; this contract never skips.");
        }
        self::assertStringStartsWith('atlas_test_', (string) getenv('ATLAS_TEST_PG_DATABASE'));
        $this->originalConnection = (string) config('database.default');
        $this->restoreDatabase = (string) getenv('ATLAS_TEST_PG_DATABASE').'_restore';
        $this->prepareSource();
    }

    protected function tearDown(): void
    {
        foreach (['atlas_p2_pg_runtime', 'atlas_p2_pg_verifier', 'atlas_p2_pg_restore'] as $connection) {
            DB::purge($connection);
        }
        config(['database.default' => 'atlas_p2_pg_setup']);
        DB::setDefaultConnection('atlas_p2_pg_setup');
        DB::connection('atlas_p2_pg_setup')->unprepared('DROP TABLE IF EXISTS atlas_ledger_events CASCADE');
        $this->runPgTool(['dropdb', '--if-exists', '-U', (string) getenv('ATLAS_TEST_PG_USERNAME'), $this->restoreDatabase]);
        $this->runPgTool(['rm', '-f', '/tmp/atlas-p2a1-restore.dump']);
        config(['database.default' => $this->originalConnection]);
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('atlas_p2_pg_setup');
        parent::tearDown();
    }

    public function test_dump_restore_keeps_hash_identity_and_read_only_verifier_role(): void
    {
        config(['database.default' => 'atlas_p2_pg_runtime']);
        DB::setDefaultConnection('atlas_p2_pg_runtime');
        $ledger = app(AtlasEvidenceLedger::class);
        $first = $ledger->record(LedgerEventType::AaeosCycleRecorded, ['step' => 1], $this->context(1));
        $second = $ledger->record(LedgerEventType::AaeosCycleRecorded, ['step' => 2], $this->context(2));
        self::assertNotNull($first);
        self::assertNotNull($second);
        $replay = app(AtlasLedgerReplayService::class);
        $cutoff = $replay->authenticatedCutoffForTenantChain('tenant-restore', (string) $first->chain_key_hash);
        self::assertNotNull($cutoff);
        $manifest = $replay->journeyManifestForTenantChain('tenant-restore', (string) $first->chain_key_hash, $cutoff);

        DB::purge('atlas_p2_pg_runtime');
        DB::purge('atlas_p2_pg_verifier');
        $setupUser = (string) getenv('ATLAS_TEST_PG_USERNAME');
        $sourceDatabase = (string) getenv('ATLAS_TEST_PG_DATABASE');
        $this->runPgTool(['pg_dump', '-U', $setupUser, '-d', $sourceDatabase, '-Fc', '-f', '/tmp/atlas-p2a1-restore.dump']);
        $this->runPgTool(['dropdb', '--if-exists', '-U', $setupUser, $this->restoreDatabase]);
        $this->runPgTool(['createdb', '-U', $setupUser, $this->restoreDatabase]);
        $this->runPgTool(['pg_restore', '-U', $setupUser, '-d', $this->restoreDatabase, '/tmp/atlas-p2a1-restore.dump']);

        $restoreConfig = config('database.connections.atlas_p2_pg_verifier');
        $restoreConfig['database'] = $this->restoreDatabase;
        $restoreConfig['application_name'] = 'atlas_p2a1_restore_verifier';
        config(['database.connections.atlas_p2_pg_restore' => $restoreConfig]);
        config(['database.default' => 'atlas_p2_pg_restore']);
        DB::setDefaultConnection('atlas_p2_pg_restore');

        $identity = (array) DB::connection()->selectOne(
            "SELECT current_database(), session_user, current_user, current_setting('application_name') AS application_name",
        );
        self::assertSame($this->restoreDatabase, $identity['current_database']);
        self::assertSame('atlas_p2a1_verifier', $identity['session_user']);
        self::assertSame('atlas_p2a1_verifier', $identity['current_user']);
        self::assertSame('atlas_p2a1_restore_verifier', $identity['application_name']);

        $this->app->forgetInstance(AtlasEvidenceLedger::class);
        $this->app->forgetInstance(AtlasLedgerReplayService::class);
        $verification = app(AtlasLedgerReplayService::class)->verifyJourneyManifest($manifest);
        self::assertTrue($verification['valid']);
        self::assertSame($manifest['journey_manifest_hash'], $verification['journey_manifest_hash']);
    }

    private function prepareSource(): void
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

    /** @return array<string,mixed> */
    private function context(int $position): array
    {
        return [
            'tenant_id' => 'tenant-restore',
            'operator_id' => 'operator-restore',
            'envelope_id' => 'restore-'.$position,
            'correlation_id' => 'restore-journey',
            'scope_type' => 'journey',
            'scope_id' => 'restore-journey',
            'occurred_at' => sprintf('2026-07-24T15:00:%02dZ', $position),
        ];
    }

    /** @param list<string> $command */
    private function runPgTool(array $command): void
    {
        $container = $this->postgresContainerName();
        $process = new Process(['docker', 'exec', $container, ...$command]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function postgresContainerName(): string
    {
        $process = new Process(['docker', 'ps', '--format', '{{.Names}} {{.Ports}}']);
        $process->mustRun();
        $port = preg_quote((string) getenv('ATLAS_TEST_PG_PORT'), '/');
        if (preg_match('/^([^ ]+).*'.$port.'->5432\/tcp$/m', $process->getOutput(), $matches) !== 1) {
            self::fail('The isolated PostgreSQL container for ATLAS_TEST_PG_PORT was not found.');
        }

        return $matches[1];
    }
}
