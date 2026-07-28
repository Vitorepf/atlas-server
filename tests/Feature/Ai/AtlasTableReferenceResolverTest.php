<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Signal\AtlasTableReferenceResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * O endereço da lacuna. Sem ele, os 572 nós compilados em fee982f52 saíam todos
 * `not_muscle_ready` com `chain_value_score=0`: o compilador nunca inventa alvo.
 *
 * Duas formas de mentir aqui, e as duas inflam o placar sem trabalho nenhum:
 * contar a migration como pista (toda tabela tem a sua, então todas pareceriam
 * endereçadas) e casar sem fronteira de palavra (`jobs` acerta dentro de
 * `failed_jobs` e aponta para o arquivo errado).
 */
final class AtlasTableReferenceResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // A raiz fica sob storage/ de propósito: storage é gitignored, e rg
        // respeita .gitignore por padrão — foi assim que estes testes pegaram o
        // no-op silencioso. Ela também não tem config/ nem routes/, o que pega o
        // outro modo de falha: rg sai com 2 se qualquer caminho não existir, e um
        // diretório ausente derrubaria a busca inteira para um "sem pista" falso.
        $this->root = storage_path('framework/testing/ref-resolver-'.getmypid());
        File::ensureDirectoryExists($this->root.'/app');
        File::ensureDirectoryExists($this->root.'/database/migrations');

        File::put($this->root.'/app/DonoDaTabela.php', '<?php DB::table("tabela_com_dono")->insert([]);');
        File::put($this->root.'/app/OutroLeitor.php', '<?php DB::table("tabela_com_dono")->count();');
        // Só a migration cita: definida e reivindicada por ninguém.
        File::put(
            $this->root.'/database/migrations/2026_01_01_000000_create_tabela_so_migration_table.php',
            '<?php Schema::create("tabela_so_migration", fn () => null);',
        );
        // A armadilha da fronteira de palavra: o arquivo cita `failed_jobs`, e
        // nada mais. Uma busca frouxa por `jobs` acharia aqui.
        File::put($this->root.'/app/SoFailedJobs.php', '<?php DB::table("failed_jobs")->count();');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function resolve(array $tables): array
    {
        return (new AtlasTableReferenceResolver($this->root))->resolve($tables);
    }

    public function test_it_finds_the_files_that_cite_the_table(): void
    {
        $out = $this->resolve(['tabela_com_dono']);

        self::assertTrue($out['available']);
        self::assertSame(
            ['app/DonoDaTabela.php', 'app/OutroLeitor.php'],
            $out['references']['tabela_com_dono'],
        );
        self::assertSame([], $out['migration_only']);
        self::assertSame([], $out['unreferenced']);
    }

    public function test_a_migration_is_a_real_reference_and_a_false_target(): void
    {
        // Se a migration contasse, TODA tabela vazia pareceria endereçada — cada
        // uma tem a sua — e o placar subiria sem um nó trabalhável a mais.
        $out = $this->resolve(['tabela_so_migration']);

        self::assertArrayNotHasKey('tabela_so_migration', $out['references']);
        self::assertSame(['tabela_so_migration'], $out['migration_only']);
    }

    public function test_a_table_nobody_cites_gets_no_hint_invented_for_it(): void
    {
        $out = $this->resolve(['tabela_que_ninguem_cita']);

        self::assertSame([], $out['references']);
        self::assertSame(['tabela_que_ninguem_cita'], $out['unreferenced']);
    }

    public function test_the_match_respects_word_boundaries(): void
    {
        // `jobs` dentro de `failed_jobs` não é uma citação de `jobs`.
        $out = $this->resolve(['jobs']);

        self::assertArrayNotHasKey('jobs', $out['references']);
        self::assertSame(['jobs'], $out['unreferenced']);
    }

    public function test_a_model_owns_its_table_even_when_the_name_appears_nowhere(): void
    {
        // O dono invisível para uma busca de texto: um Model sem `$table` deriva
        // o nome da classe, então a string não existe em arquivo nenhum. Era o
        // caso de 15 das 21 tabelas que a busca dava como órfãs — o endereço
        // existia, o instrumento é que não alcançava.
        $out = (new AtlasTableReferenceResolver)->resolve(['atlas_plans']);

        self::assertContains('app/Models/AtlasPlan.php', $out['references']['atlas_plans'] ?? []);
        self::assertNotContains('atlas_plans', $out['unreferenced']);

        // E o Eloquent é PERGUNTADO, não imitado. `AtlasToolPolicy` resolve
        // `atlas_tool_policies`; uma pluralização caseira daria `..._policys` e o
        // dono sumiria. O repo tem 8 modelos assim — e quem declara `$table`
        // explícito é achado pela busca de texto de qualquer jeito, então é o
        // plural irregular que separa perguntar de adivinhar.
        $irregular = (new AtlasTableReferenceResolver)->resolve(['atlas_tool_policies']);
        self::assertContains(
            'app/Models/AtlasToolPolicy.php',
            $irregular['references']['atlas_tool_policies'] ?? [],
        );
    }

    public function test_a_name_that_is_not_an_identifier_never_reaches_the_search(): void
    {
        // Um "nome" com metacaracteres viraria regex arbitrária dentro do rg, e o
        // resultado deixaria de ser o que qualquer um repete à mão.
        $out = $this->resolve(['.*', 'tabela_com_dono']);

        self::assertArrayHasKey('tabela_com_dono', $out['references']);
        self::assertNotContains('.*', $out['unreferenced']);
        self::assertNotContains('.*', array_keys($out['references']));
    }
}
