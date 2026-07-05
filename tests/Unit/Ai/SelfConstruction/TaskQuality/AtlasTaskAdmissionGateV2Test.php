<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskDuplicateReuseGate;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskWiringAdmissionGate;
use PHPUnit\Framework\TestCase;

/**
 * OBRA #6 V0 — admission gate v2. Testes wiper-safe puros (filesystem sintético num tmpdir, zero DB,
 * zero RefreshDatabase). Cada teste monta um mini-repo com app/ e prova o comportamento determinístico
 * dos dois checks: reuse-first de LÓGICA (bloco >= 30 linhas) e wired-or-tagged (classe 0-ref).
 */
final class AtlasTaskAdmissionGateV2Test extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/obra6-admission-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function put(string $rel, string $contents): void
    {
        $abs = $this->root.'/'.$rel;
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($abs, $contents);
    }

    /** Um corpo de método com >= 30 linhas de lógica normalizada real. */
    private function bodyOf30(): string
    {
        $lines = [];
        for ($i = 1; $i <= 34; $i++) {
            $lines[] = "        \$acc[$i] = \$input[$i] * $i + \$carry;";
        }

        return implode("\n", $lines);
    }

    public function test_logic_reuse_blocks_a_30_line_clone_of_an_existing_file(): void
    {
        $body = $this->bodyOf30();
        $this->put('app/Existing/OriginalScorer.php', "<?php\nnamespace App\\Existing;\nclass OriginalScorer {\n    public function score(array \$input, int \$carry): array {\n$body\n        return \$acc;\n    }\n}\n");
        // entrega copia o MESMO bloco com outro nome/namespace
        $this->put('app/New/CopiedScorer.php', "<?php\nnamespace App\\New;\nclass CopiedScorer {\n    public function rate(array \$input, int \$carry): array {\n$body\n        return \$acc;\n    }\n}\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluateLogicReuse(['app/New/CopiedScorer.php'], $this->root);

        $this->assertFalse($verdict['passed'], 'clone de 30+ linhas deve ser blocker');
        $this->assertNotEmpty($verdict['blockers']);
        $this->assertStringContainsString('duplicate_logic_blocked:app/New/CopiedScorer.php~app/Existing/OriginalScorer.php', $verdict['blockers'][0]);
    }

    public function test_logic_reuse_passes_original_logic(): void
    {
        $this->put('app/Existing/OriginalScorer.php', "<?php\nnamespace App\\Existing;\nclass OriginalScorer {\n    public function score(): int {\n        return 1;\n    }\n}\n");
        $body = $this->bodyOf30();
        $this->put('app/New/GenuinelyNew.php', "<?php\nnamespace App\\New;\nclass GenuinelyNew {\n    public function compute(array \$input, int \$carry): array {\n$body\n        return \$acc;\n    }\n}\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluateLogicReuse(['app/New/GenuinelyNew.php'], $this->root);

        $this->assertTrue($verdict['passed'], 'lógica nova (sem gêmea em app/) deve passar');
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame(1, $verdict['examined']);
    }

    public function test_logic_reuse_normalizes_dot_slash_path_no_self_match(): void
    {
        // FURO do verify adversarial: './app/...' furava a auto-exclusão e o arquivo batia consigo mesmo.
        $body = $this->bodyOf30();
        $this->put('app/New/Solo.php', "<?php\nnamespace App\\New;\nclass Solo {\n    public function compute(array \$input, int \$carry): array {\n$body\n        return \$acc;\n    }\n}\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluateLogicReuse(['./app/New/Solo.php'], $this->root);

        $this->assertTrue($verdict['passed'], "'./app/...' não pode casar contra a própria cópia no disco");
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_logic_reuse_ignores_structural_config_and_match_repetition(): void
    {
        // FURO do verify adversarial: 30+ linhas de 'chave => valor' idênticas = repetição estrutural,
        // não lógica copiada. Dois mapas de config sobre o mesmo domínio NÃO devem bloquear.
        $rows = [];
        for ($i = 1; $i <= 34; $i++) {
            $rows[] = "        'permission_$i' => ['read', 'write', 'level_$i'],";
        }
        $block = implode("\n", $rows);
        $this->put('app/Existing/PermsA.php', "<?php\nnamespace App\\Existing;\nclass PermsA {\n    public function map(): array {\n        return [\n$block\n        ];\n    }\n}\n");
        $this->put('app/New/PermsB.php', "<?php\nnamespace App\\New;\nclass PermsB {\n    public function table(): array {\n        return [\n$block\n        ];\n    }\n}\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluateLogicReuse(['app/New/PermsB.php'], $this->root);

        $this->assertTrue($verdict['passed'], 'repetição estrutural de array de config não é clone de lógica');
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_logic_reuse_still_blocks_real_copied_statements(): void
    {
        // controle: o filtro estrutural NÃO pode deixar passar lógica real copiada (statements).
        $body = $this->bodyOf30();
        $this->put('app/Existing/RealLogic.php', "<?php\nnamespace App\\Existing;\nclass RealLogic {\n    public function run(array \$input, int \$carry): array {\n$body\n        return \$acc;\n    }\n}\n");
        $this->put('app/New/CopiedLogic.php', "<?php\nnamespace App\\New;\nclass CopiedLogic {\n    public function exec(array \$input, int \$carry): array {\n$body\n        return \$acc;\n    }\n}\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluateLogicReuse(['app/New/CopiedLogic.php'], $this->root);

        $this->assertFalse($verdict['passed'], 'lógica real copiada (statements) continua bloqueada');
    }

    public function test_logic_reuse_ignores_short_shared_snippets(): void
    {
        // 5 linhas iguais (boilerplate curto) NÃO é clone — abaixo do piso de 30.
        $short = "        \$a = 1;\n        \$b = 2;\n        \$c = 3;\n        \$d = 4;\n        \$e = 5;";
        $this->put('app/Existing/A.php', "<?php\nnamespace App\\Existing;\nclass A {\n    public function f(): int {\n$short\n        return \$a;\n    }\n}\n");
        $this->put('app/New/B.php', "<?php\nnamespace App\\New;\nclass B {\n    public function g(): int {\n$short\n        return \$a;\n    }\n}\n");

        $verdict = (new AtlasTaskDuplicateReuseGate)->evaluateLogicReuse(['app/New/B.php'], $this->root);

        $this->assertTrue($verdict['passed'], 'snippet curto compartilhado não deve bloquear');
    }

    public function test_wiring_blocks_unwired_class_without_tag(): void
    {
        $this->put('app/New/OrphanOrgan.php', "<?php\nnamespace App\\New;\nclass OrphanOrgan {\n    public function run(): void {}\n}\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['app/New/OrphanOrgan.php'], $this->root, '2026-07-05');

        $this->assertFalse($verdict['passed'], 'classe 0-ref sem tag deve bloquear');
        $this->assertSame(['unwired_class:App\\New\\OrphanOrgan'], $verdict['blockers']);
    }

    public function test_wiring_passes_class_with_a_real_caller(): void
    {
        $this->put('app/New/WiredOrgan.php', "<?php\nnamespace App\\New;\nclass WiredOrgan {\n    public function run(): void {}\n}\n");
        $this->put('app/Existing/Caller.php', "<?php\nnamespace App\\Existing;\nuse App\\New\\WiredOrgan;\nclass Caller {\n    public function go(): void { (new WiredOrgan)->run(); }\n}\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['app/New/WiredOrgan.php'], $this->root, '2026-07-05');

        $this->assertTrue($verdict['passed'], 'classe com caller real deve passar');
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_wiring_passes_valid_unwired_tag_as_observation(): void
    {
        $this->put('app/New/Declared.php', "<?php\nnamespace App\\New;\n/**\n * @unwired-until 2026-08-01\n */\nclass Declared {\n    public function run(): void {}\n}\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['app/New/Declared.php'], $this->root, '2026-07-05');

        $this->assertTrue($verdict['passed'], 'unwired declarado com prazo futuro passa (observação)');
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame(['unwired_declared:App\\New\\Declared:until_2026-08-01'], $verdict['observations']);
    }

    public function test_wiring_blocks_expired_unwired_tag(): void
    {
        $this->put('app/New/Stale.php', "<?php\nnamespace App\\New;\n/**\n * @unwired-until 2026-06-01\n */\nclass Stale {\n    public function run(): void {}\n}\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['app/New/Stale.php'], $this->root, '2026-07-05');

        $this->assertFalse($verdict['passed'], 'tag vencida deve bloquear');
        $this->assertSame(['unwired_expired:App\\New\\Stale:2026-06-01'], $verdict['blockers']);
    }

    public function test_wiring_treats_console_command_as_wired_by_convention(): void
    {
        // Laravel auto-descobre app/Console/Commands/ — sem caller explícito, mas wired.
        $this->put('app/Console/Commands/SomeCommand.php', "<?php\nnamespace App\\Console\\Commands;\nuse Illuminate\\Console\\Command;\nclass SomeCommand extends Command {\n    protected \$signature = 'x';\n    public function handle(): int { return 0; }\n}\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['app/Console/Commands/SomeCommand.php'], $this->root, '2026-07-05');

        $this->assertTrue($verdict['passed'], 'Console Command é wired por convenção do framework');
        $this->assertSame(0, $verdict['examined']);
    }

    public function test_wiring_counts_a_same_delivery_caller(): void
    {
        // entrega adiciona Command + Service que ele usa: o Service está wired PELO Command da mesma entrega.
        $this->put('app/Console/Commands/RunSnapshotCommand.php', "<?php\nnamespace App\\Console\\Commands;\nuse Illuminate\\Console\\Command;\nuse App\\New\\SnapshotService;\nclass RunSnapshotCommand extends Command {\n    public function handle(): int { (new SnapshotService)->run(); return 0; }\n}\n");
        $this->put('app/New/SnapshotService.php', "<?php\nnamespace App\\New;\nclass SnapshotService {\n    public function run(): void {}\n}\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(
            ['app/Console/Commands/RunSnapshotCommand.php', 'app/New/SnapshotService.php'],
            $this->root,
            '2026-07-05',
        );

        $this->assertTrue($verdict['passed'], 'Service usado por Command da mesma entrega está wired');
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_wiring_finds_caller_in_routes(): void
    {
        $this->put('app/New/RoutedController.php', "<?php\nnamespace App\\New;\nclass RoutedController {\n    public function index(): void {}\n}\n");
        $this->put('routes/api.php', "<?php\nuse App\\New\\RoutedController;\nRoute::get('/x', [RoutedController::class, 'index']);\n");

        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['app/New/RoutedController.php'], $this->root, '2026-07-05');

        $this->assertTrue($verdict['passed'], 'classe referenciada em routes/ está wired');
    }

    public function test_wiring_ignores_test_files(): void
    {
        $verdict = (new AtlasTaskWiringAdmissionGate)->evaluate(['tests/Unit/Foo/SomethingTest.php'], $this->root, '2026-07-05');
        $this->assertTrue($verdict['passed']);
        $this->assertSame(0, $verdict['examined']);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
