<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use Tests\TestCase;

/**
 * O Postgres corta identificador em 63 caracteres — em silêncio, sem aviso nem
 * erro. Nenhuma camada acima percebe: o CREATE "funciona", a tabela existe com
 * outro nome, e todo o código consulta um nome que não existe. A tabela nunca
 * recebe nem devolve nada, e nada nunca reclama.
 *
 * Aconteceu:
 * `atlas_self_construction_agent_dispatch_executor_release_authorizations` (70)
 * virou `..._release_authori` (63) e ficou meses assim, invisível — só apareceu
 * porque o censo de tabelas vazias perguntou quem cita cada nome e essa não era
 * citada por ninguém.
 *
 * Um teste, não um comentário: a próxima tabela de nome longo falha AQUI, em vez
 * de existir muda no banco.
 */
final class PostgresIdentifierLimitTest extends TestCase
{
    private const PG_IDENTIFIER_LIMIT = 63;

    public function test_no_migration_declares_a_table_name_postgres_would_truncate(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/Schema::(?:create|rename)\(\s*'([a-z0-9_]+)'/", $source, $matches);
            foreach ($matches[1] as $table) {
                if (strlen($table) > self::PG_IDENTIFIER_LIMIT) {
                    $offenders[] = basename($file).' → '.$table.' ('.strlen($table).')';
                }
            }
        }

        self::assertSame([], $offenders, "o Postgres cortaria estes nomes em silêncio:\n".implode("\n", $offenders));
    }

    public function test_the_model_that_was_pointing_at_a_nonexistent_table_declares_its_own(): void
    {
        // O nome derivado da classe tem 70 caracteres. Sem $table explícito, o
        // model volta a apontar para uma tabela que não existe — e volta a
        // fazê-lo em silêncio.
        $table = (new AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization)->getTable();

        self::assertLessThanOrEqual(self::PG_IDENTIFIER_LIMIT, strlen($table));
        self::assertSame('atlas_self_construction_agent_dispatch_authorizations', $table);
    }
}
