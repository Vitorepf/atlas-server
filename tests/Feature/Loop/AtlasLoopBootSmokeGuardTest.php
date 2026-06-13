<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * INDEPENDÊNCIA 24h+: o piso de sanidade do auto-merge era só `php -l` (SINTAXE). Um merge
 * com erro de LÓGICA que quebra o BOOT do app (um provider que lança ao bootar) passaria —
 * e quando o supervisor reiniciasse por drift, carregaria o código quebrado e entraria em
 * CRASH-LOOP, derrubando o loop inteiro. O boot-smoke estende o piso para "BOOT quebrado
 * nunca entra": boota o app com a mudança aplicada; se falhar, rejeita ANTES do commit.
 *
 * Estes testes congelam o contrato sem depender de um Laravel real numa árvore temporária:
 * (a) app que boota limpo ⇒ OK; (b) bootstrap que LANÇA ⇒ rejeitado; (c) ambiente sem
 * vendor/bootstrap ⇒ degrade-safe (true, não bloqueia por ambiente incompleto).
 */
final class AtlasLoopBootSmokeGuardTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function smoke(string $repoRoot): bool
    {
        $svc = app(AtlasLoopAutoMergeService::class);
        $m = new ReflectionMethod(AtlasLoopAutoMergeService::class, 'bootSmokeOk');
        $m->setAccessible(true);

        return (bool) $m->invoke($svc, $repoRoot);
    }

    private function fakeRepo(string $bootstrapBody): string
    {
        $d = sys_get_temp_dir().'/atlas-boot-smoke-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/vendor');
        File::ensureDirectoryExists($d.'/bootstrap');
        // autoloader mínimo válido — o boot-smoke faz require dele primeiro.
        File::put($d.'/vendor/autoload.php', "<?php\n// fake autoloader\n");
        File::put($d.'/bootstrap/app.php', "<?php\n".$bootstrapBody);

        return $d;
    }

    public function test_healthy_real_app_boots_ok(): void
    {
        // O repo real DEVE bootar (a árvore atual está sã).
        $this->assertTrue($this->smoke(base_path()), 'o app real boota limpo');
    }

    public function test_bootstrap_that_throws_is_caught(): void
    {
        // Simula um merge que quebra o boot (um provider/bootstrap que lança).
        $repo = $this->fakeRepo("throw new \\RuntimeException('merge quebrou o boot');\n");

        $this->assertFalse($this->smoke($repo), 'boot que lança é PEGO (merge seria rejeitado)');
    }

    public function test_bootstrap_without_the_ok_marker_fails(): void
    {
        // Retorna um objeto sem o método make/bootstrap → o script falha ⇒ não-OK.
        $repo = $this->fakeRepo("return new \\stdClass();\n");

        $this->assertFalse($this->smoke($repo), 'um app que não completa o bootstrap não passa');
    }

    public function test_missing_vendor_is_degrade_safe(): void
    {
        // Ambiente incompleto (sem vendor/bootstrap) NÃO bloqueia — o php -l já cobriu sintaxe.
        $d = sys_get_temp_dir().'/atlas-boot-empty-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d);

        $this->assertTrue($this->smoke($d), 'sem vendor/bootstrap ⇒ degrade-safe (true)');
    }
}
