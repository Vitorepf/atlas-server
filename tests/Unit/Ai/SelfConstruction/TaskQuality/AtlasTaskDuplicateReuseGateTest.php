<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskDuplicateReuseGate;
use PHPUnit\Framework\TestCase;

/**
 * F0 da limpeza 05/07 — prova o freio no produtor de duplicação da esteira:
 * símbolo homônimo re-declarado = BLOCKER; bloco clonado de irmão = observação (nunca bloqueia);
 * entrega única passa limpa; arquivo deletado e renomeio dentro da mesma entrega não acusam.
 */
final class AtlasTaskDuplicateReuseGateTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/dedup-gate-'.bin2hex(random_bytes(6));
        mkdir($this->repo.'/app/Services/Alpha', 0777, true);
        mkdir($this->repo.'/app/Services/Beta', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->repo);
        parent::tearDown();
    }

    private function put(string $rel, string $contents): void
    {
        $abs = $this->repo.'/'.$rel;
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        file_put_contents($abs, $contents);
    }

    private function classSource(string $name, int $methods = 2): string
    {
        $body = '';
        for ($i = 0; $i < $methods; $i++) {
            $body .= "    public function m{$i}(): int\n    {\n        return {$i};\n    }\n";
        }

        return "<?php\n\nnamespace App\\Services;\n\nfinal class {$name}\n{\n{$body}}\n";
    }

    public function test_redeclared_symbol_in_another_file_is_a_blocker(): void
    {
        $this->put('app/Services/Alpha/Widget.php', $this->classSource('Widget'));
        $this->put('app/Services/Beta/Widget.php', $this->classSource('Widget'));

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluate(['app/Services/Beta/Widget.php'], $this->repo);

        $this->assertFalse($verdict['passed']);
        $this->assertSame(['duplicate_class_name:App\\Services\\Widget:app/Services/Alpha/Widget.php'], $verdict['blockers']);
    }

    public function test_unique_symbol_passes_clean(): void
    {
        $this->put('app/Services/Alpha/Widget.php', $this->classSource('Widget'));
        $this->put('app/Services/Beta/Gadget.php', $this->classSource('Gadget'));

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluate(['app/Services/Beta/Gadget.php'], $this->repo);

        $this->assertTrue($verdict['passed']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame(1, $verdict['examined']);
    }

    public function test_rename_within_same_delivery_does_not_block(): void
    {
        // Renomeio em curso: os DOIS paths estão na mesma entrega — o gate não pode acusar
        // a entrega de duplicar a si mesma.
        $this->put('app/Services/Alpha/Widget.php', $this->classSource('Widget'));
        $this->put('app/Services/Beta/Widget.php', $this->classSource('Widget'));

        $verdict = (new AtlasTaskDuplicateReuseGate)
            ->evaluate(['app/Services/Alpha/Widget.php', 'app/Services/Beta/Widget.php'], $this->repo);

        $this->assertTrue($verdict['passed']);
    }

    public function test_deleted_file_is_skipped(): void
    {
        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluate(['app/Services/Gone/Removed.php'], $this->repo);

        $this->assertTrue($verdict['passed']);
        $this->assertSame(0, $verdict['examined']);
    }

    public function test_sibling_clone_block_is_observation_only_never_blocker(): void
    {
        // Dois irmãos com 40 linhas de código idênticas (métodos iguais) — o padrão
        // template-copiado do worker. Deve virar observação e passed continua true.
        $this->put('app/Services/Alpha/GateOne.php', $this->classSource('GateOne', 20));
        $this->put('app/Services/Alpha/GateTwo.php', $this->classSource('GateTwo', 20));

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluate(['app/Services/Alpha/GateTwo.php'], $this->repo);

        $this->assertTrue($verdict['passed']);
        $this->assertNotEmpty($verdict['observations']);
        $this->assertStringStartsWith('clone_block:app/Services/Alpha/GateTwo.php~GateOne.php:', $verdict['observations'][0]);
    }

    public function test_short_files_produce_no_clone_observation(): void
    {
        $this->put('app/Services/Alpha/TinyOne.php', $this->classSource('TinyOne', 2));
        $this->put('app/Services/Alpha/TinyTwo.php', $this->classSource('TinyTwo', 2));

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluate(['app/Services/Alpha/TinyTwo.php'], $this->repo);

        $this->assertTrue($verdict['passed']);
        $this->assertSame([], $verdict['observations']);
    }

    public function test_non_php_files_are_ignored(): void
    {
        $this->put('docs/nota.md', "# nota\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluate(['docs/nota.md'], $this->repo);

        $this->assertTrue($verdict['passed']);
        $this->assertSame(0, $verdict['examined']);
    }
}
