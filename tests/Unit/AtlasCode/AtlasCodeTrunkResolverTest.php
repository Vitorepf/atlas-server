<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeTrunkResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Os três casos aqui são os três casos REAIS do disco do operador — o motor
 * de regras quebrava em dois deles:
 *
 *   atlas-server     → trunk `main` (o caso que já funcionava)
 *   nivor-back-end   → não tem `main`; trunk `production`  → varredura morria
 *   blackink-website → tem `main` E `production`; trunk `production`
 *                      → o Atlas ACUSARIA trabalho correto
 */
final class AtlasCodeTrunkResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/atlas-trunk-'.uniqid();
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
    }

    public function test_a_repository_without_main_resolves_its_real_trunk(): void
    {
        // nivor-back-end: não tem `main`, tem `production` e `develop`, e
        // declara production. `git rev-parse main` falhava e levava a varredura
        // inteira junto — o app dizia "não consegui varrer as regras".
        $repo = $this->repository('sem-main', trunk: 'production', extraBranches: ['develop']);
        $this->declareOrigin($repo, 'production');

        self::assertSame('production', (new AtlasCodeTrunkResolver())->resolve($repo));
    }

    public function test_without_a_declaration_a_single_plausible_trunk_is_enough(): void
    {
        $repo = $this->repository('so-develop', trunk: 'develop');

        self::assertSame('develop', (new AtlasCodeTrunkResolver())->resolve($repo));
    }

    public function test_an_ambiguous_trunk_stays_silent_instead_of_guessing(): void
    {
        // Sem declaração, sem main/master, e dois nomes plausíveis: qualquer
        // escolha é chute — e chute aqui acusa trabalho correto. O resolvedor
        // devolve a branch atual, e nenhuma regra dispara.
        $repo = $this->repository('ambiguo', trunk: 'develop', extraBranches: ['production']);

        self::assertSame('develop', (new AtlasCodeTrunkResolver())->resolve($repo));

        // Prova de que é silêncio e não sorte: de pé na production, a "trunk"
        // acompanha — ou seja, o motor não acusa ninguém.
        $this->git($repo, ['git', 'checkout', '--quiet', 'production']);
        self::assertSame('production', (new AtlasCodeTrunkResolver())->resolve($repo));
    }

    public function test_when_main_and_production_coexist_the_declared_trunk_wins(): void
    {
        // blackink-website: o pior caso. Comparar contra `main` faria o Atlas
        // acusar quem trabalha na production — que é o certo lá.
        $repo = $this->repository('main-e-production', trunk: 'production', extraBranches: ['main']);
        $this->declareOrigin($repo, 'production');

        self::assertSame('production', (new AtlasCodeTrunkResolver())->resolve($repo));
    }

    public function test_the_atlas_repository_still_resolves_main(): void
    {
        $repo = $this->repository('atlas-like', trunk: 'main');

        self::assertSame('main', (new AtlasCodeTrunkResolver())->resolve($repo));
    }

    public function test_a_branch_name_that_is_not_a_branch_name_never_reaches_git(): void
    {
        $repo = $this->repository('seguro', trunk: 'main');
        $resolver = new AtlasCodeTrunkResolver();

        self::assertFalse($resolver->branchExists($repo, '--upload-pack=touch /tmp/pwned'));
        self::assertFalse($resolver->branchExists($repo, 'main; rm -rf /'));
        self::assertTrue($resolver->branchExists($repo, 'main'));
    }

    /** @param array<int,string> $extraBranches */
    private function repository(string $name, string $trunk, array $extraBranches = []): string
    {
        $path = $this->root.'/'.$name;
        mkdir($path, 0o777, true);
        $this->git($path, ['git', 'init', '--quiet', '--initial-branch='.$trunk]);
        $this->git($path, ['git', 'config', 'user.email', 'teste@atlas.local']);
        $this->git($path, ['git', 'config', 'user.name', 'Teste']);
        file_put_contents($path.'/README.md', "# {$name}\n");
        $this->git($path, ['git', 'add', '.']);
        $this->git($path, ['git', 'commit', '--quiet', '-m', 'primeiro commit']);
        foreach ($extraBranches as $branch) {
            $this->git($path, ['git', 'branch', $branch]);
        }

        return $path;
    }

    private function declareOrigin(string $path, string $trunk): void
    {
        // Simula o que o host declara: refs/remotes/origin/HEAD → origin/<trunk>.
        mkdir($path.'/.git/refs/remotes/origin', 0o777, true);
        $head = $this->git($path, ['git', 'rev-parse', $trunk]);
        file_put_contents($path.'/.git/refs/remotes/origin/'.$trunk, trim($head)."\n");
        file_put_contents($path.'/.git/refs/remotes/origin/HEAD', "ref: refs/remotes/origin/{$trunk}\n");
    }

    /** @param array<int,string> $command */
    private function git(string $cwd, array $command): string
    {
        $process = new Process($command, $cwd);
        $process->run();

        return $process->getOutput();
    }
}
