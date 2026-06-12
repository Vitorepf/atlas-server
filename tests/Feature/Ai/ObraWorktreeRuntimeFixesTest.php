<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L3-13: os dois bugs do harness do obra que impediam o run LIVE de CERTIFICAR.
 *
 *  1. PATH ANINHADO — quando o workspace do brain é o guarda-chuva (`/…/Atlas`) e o repo é
 *     `atlas-server`, o provider gera `atlas-server/app/Support/X.php`; escrito num worktree
 *     que JÁ É o atlas-server vira um arquivo novo no lugar errado. `normalizeWorktreeRelative
 *     Path` strip o prefixo do umbrella (bounded a 1 nível, só quando seguro).
 *  2. VENDOR AUSENTE — o `git worktree` é um checkout limpo sem os deps gitignored, então o
 *     integrated-check (`php artisan test`) quebrava por `vendor/autoload.php`. `linkRuntime
 *     Deps` liga vendor/.env/node_modules por symlink.
 */
final class ObraWorktreeRuntimeFixesTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private function worktree(): string
    {
        $d = sys_get_temp_dir().'/atlas-obra-wt-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Support');
        File::ensureDirectoryExists($d.'/config');

        return $d;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function normalize(string $worktree, string $path): string
    {
        $m = new ReflectionMethod(GovernedBranchMaterializationService::class, 'normalizeWorktreeRelativePath');
        $m->setAccessible(true);

        return $m->invoke(app(GovernedBranchMaterializationService::class), $worktree, $path);
    }

    public function test_umbrella_prefix_is_stripped_when_worktree_is_the_repo(): void
    {
        $wt = $this->worktree(); // tem app/ e config/, NÃO tem atlas-server/

        $this->assertSame(
            'app/Support/TerminalMarkdownRenderer.php',
            $this->normalize($wt, 'atlas-server/app/Support/TerminalMarkdownRenderer.php'),
            'o prefixo do umbrella é removido quando o worktree já é o repo'
        );
    }

    public function test_correct_repo_relative_path_is_left_untouched(): void
    {
        $wt = $this->worktree();

        $this->assertSame('app/Support/X.php', $this->normalize($wt, 'app/Support/X.php'), 'path já-correto intacto');
        $this->assertSame('config/atlas.php', $this->normalize($wt, 'config/atlas.php'), 'path top-level intacto');
    }

    public function test_does_not_strip_when_it_would_be_unsafe(): void
    {
        $wt = $this->worktree();
        // 'tests/Unit/X.php' — 'tests' não existe no worktree e 'Unit' também não → NÃO strip
        // (não há evidência de que 'tests' seja prefixo de umbrella; deixa intacto).
        $this->assertSame('tests/Unit/X.php', $this->normalize($wt, 'tests/Unit/X.php'));
    }

    public function test_link_runtime_deps_symlinks_existing_deps_into_worktree(): void
    {
        $repo = $this->worktree();
        File::ensureDirectoryExists($repo.'/vendor');
        File::put($repo.'/vendor/autoload.php', "<?php\n");
        File::put($repo.'/.env', "APP_ENV=testing\n");
        $worktree = $this->worktree();

        $m = new ReflectionMethod(GovernedBranchMaterializationService::class, 'linkRuntimeDeps');
        $m->setAccessible(true);
        $m->invoke(app(GovernedBranchMaterializationService::class), $repo, $worktree);

        $this->assertTrue(is_link($worktree.'/vendor') || is_dir($worktree.'/vendor'), 'vendor ligado no worktree');
        $this->assertFileExists($worktree.'/vendor/autoload.php', 'autoload alcançável via symlink');
        $this->assertTrue(is_link($worktree.'/.env') || is_file($worktree.'/.env'), '.env ligado');
    }
}
