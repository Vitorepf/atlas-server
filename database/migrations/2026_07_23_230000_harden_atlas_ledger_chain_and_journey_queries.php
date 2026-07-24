<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_type')) {
                $table->string('scope_type', 40)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_id')) {
                $table->string('scope_id', 80)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'event_hash')) {
                $table->string('event_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'prev_event_hash')) {
                $table->string('prev_event_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_basis')) {
                $table->string('chain_basis', 40)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_key_hash')) {
                $table->string('chain_key_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_position')) {
                $table->unsignedBigInteger('chain_position')->nullable();
            }
        });

        $this->ensureIndex(['event_hash'], 'atlas_ledger_events_event_hash_index');
        $this->ensureIndex(['prev_event_hash'], 'atlas_ledger_events_prev_event_hash_index');
        $this->ensureIndex(['chain_basis'], 'atlas_ledger_events_chain_basis_index');
        $this->ensureIndex(
            ['tenant_id', 'event_type', 'occurred_at', 'event_id'],
            'atlas_ledger_events_tenant_type_window_index',
        );
        $this->ensureIndex(
            ['tenant_id', 'envelope_id', 'chain_position'],
            'atlas_ledger_events_tenant_envelope_position_index',
        );
        $this->ensureIndex(
            ['tenant_id', 'correlation_id', 'chain_position'],
            'atlas_ledger_events_tenant_correlation_position_index',
        );
        $this->ensureIndex(
            ['tenant_id', 'scope_type', 'scope_id', 'chain_position'],
            'atlas_ledger_events_tenant_scope_position_index',
        );
        $this->ensureUniqueIndex(
            ['tenant_id', 'chain_key_hash', 'chain_position'],
            'atlas_ledger_events_tenant_chain_position_unique',
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->installPostgresAppendOnlyGuard();
        }
    }

    public function down(): void
    {
        // Expand-only authority migration: historical evidence and its guard are never removed.
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(array $columns, string $name): void
    {
        if (Schema::hasIndex('atlas_ledger_events', $name)) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUniqueIndex(array $columns, string $name): void
    {
        if (Schema::hasIndex('atlas_ledger_events', $name)) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });
    }

    private function installPostgresAppendOnlyGuard(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_atlas_ledger_events_updated_at ON atlas_ledger_events;

            CREATE OR REPLACE FUNCTION atlas_reject_ledger_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                RAISE EXCEPTION 'atlas_ledger_events is append-only: % is forbidden', TG_OP
                    USING ERRCODE = '55000';
            END;
            $function$;

            DROP TRIGGER IF EXISTS trg_atlas_ledger_events_append_only ON atlas_ledger_events;
            CREATE TRIGGER trg_atlas_ledger_events_append_only
            BEFORE UPDATE OR DELETE ON atlas_ledger_events
            FOR EACH ROW EXECUTE FUNCTION atlas_reject_ledger_mutation();

            REVOKE ALL ON TABLE atlas_ledger_events FROM PUBLIC;
        SQL);

        $enforced = filter_var(config('database.ledger_roles.enforced', false), FILTER_VALIDATE_BOOL);
        $runtimeRole = $this->configuredRole('runtime_connection', $enforced);
        $verifierRole = $this->configuredRole('verifier_connection', $enforced);
        if ($enforced && ($runtimeRole === null || $verifierRole === null || $runtimeRole === $verifierRole)) {
            throw new RuntimeException('Atlas ledger role enforcement requires distinct runtime and verifier identities.');
        }

        $this->configureRole($runtimeRole, true, $enforced);
        $this->configureRole($verifierRole, false, $enforced);
    }

    private function configuredRole(string $connectionKey, bool $required): ?string
    {
        $connectionName = trim((string) config('database.ledger_roles.'.$connectionKey, ''));
        $connection = config('database.connections.'.$connectionName);
        $role = is_array($connection) ? trim((string) ($connection['username'] ?? '')) : '';
        if ($role === '' || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            if ($required) {
                throw new RuntimeException("Atlas ledger {$connectionKey} is missing or invalid.");
            }

            return null;
        }

        return $role;
    }

    private function configureRole(?string $role, bool $mayInsert, bool $required): void
    {
        if ($role === null) {
            return;
        }

        $exists = DB::table('pg_roles')->where('rolname', $role)->exists();
        if (! $exists) {
            if ($required) {
                throw new RuntimeException("Atlas ledger role {$role} does not exist.");
            }

            return;
        }

        $quoted = '"'.str_replace('"', '""', $role).'"';
        $database = (string) DB::scalar('SELECT current_database()');
        $quotedDatabase = '"'.str_replace('"', '""', $database).'"';

        // A role can SET ROLE to every granted membership even with NOINHERIT.
        // Remove every direct membership before granting its narrow ledger rights.
        $memberships = DB::select(<<<'SQL'
            SELECT parent.rolname
            FROM pg_auth_members membership
            INNER JOIN pg_roles parent ON parent.oid = membership.roleid
            INNER JOIN pg_roles member ON member.oid = membership.member
            WHERE member.rolname = ?
        SQL, [$role]);
        foreach ($memberships as $membership) {
            $parent = trim((string) ($membership->rolname ?? ''));
            if ($parent !== '' && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $parent) === 1) {
                $quotedParent = '"'.str_replace('"', '""', $parent).'"';
                DB::statement("REVOKE {$quotedParent} FROM {$quoted}");
            }
        }

        DB::statement("ALTER ROLE {$quoted} NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
        DB::statement("REVOKE ALL PRIVILEGES ON DATABASE {$quotedDatabase} FROM {$quoted}");
        DB::statement("GRANT CONNECT ON DATABASE {$quotedDatabase} TO {$quoted}");
        DB::statement("REVOKE CREATE ON SCHEMA public FROM {$quoted}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$quoted}");
        DB::statement("REVOKE ALL ON TABLE atlas_ledger_events FROM {$quoted}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$quoted}");
        DB::statement("GRANT SELECT ON TABLE atlas_ledger_events TO {$quoted}");
        if ($mayInsert) {
            DB::statement("GRANT INSERT ON TABLE atlas_ledger_events TO {$quoted}");
        }

        if (! $required) {
            return;
        }

        $attributes = (array) DB::selectOne(
            'SELECT rolsuper, rolcreatedb, rolcreaterole, rolinherit, rolbypassrls FROM pg_roles WHERE rolname = ?',
            [$role],
        );
        $stillMember = DB::table('pg_auth_members as membership')
            ->join('pg_roles as member', 'member.oid', '=', 'membership.member')
            ->where('member.rolname', $role)
            ->exists();
        if (($attributes['rolsuper'] ?? true)
            || ($attributes['rolcreatedb'] ?? true)
            || ($attributes['rolcreaterole'] ?? true)
            || ($attributes['rolinherit'] ?? true)
            || ($attributes['rolbypassrls'] ?? true)
            || $stillMember) {
            throw new RuntimeException("Atlas ledger role {$role} did not converge to least privilege.");
        }
    }
};
