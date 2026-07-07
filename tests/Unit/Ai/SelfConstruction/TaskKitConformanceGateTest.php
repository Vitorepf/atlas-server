<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskKitConformanceGate;
use PHPUnit\Framework\TestCase;

/**
 * K4 (Obra #18) — the kit conformance gate: untouched pre-written oracle, no
 * artisan command-name collision, diff ⊆ allowed. Deterministic; runs against
 * an isolated temp root so nothing touches the real tree.
 */
final class TaskKitConformanceGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-kit-gate-'.uniqid('', true);
        mkdir($this->root.'/app/Console/Commands', 0775, true);
        mkdir($this->root.'/tests', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    private function writeCommand(string $relPath, string $signatureName): void
    {
        file_put_contents($this->root.'/'.$relPath, "<?php\nclass X { protected \$signature = '{$signatureName} {--foo}'; }\n");
    }

    private function gate(): AtlasTaskKitConformanceGate
    {
        return new AtlasTaskKitConformanceGate($this->root);
    }

    public function test_untouched_acceptance_test_passes(): void
    {
        $rel = 'tests/FooTest.php';
        file_put_contents($this->root.'/'.$rel, '<?php // the oracle');
        $hash = hash('sha256', (string) file_get_contents($this->root.'/'.$rel));

        $result = $this->gate()->evaluate([
            'allowed_files' => ['app/Foo.php'],
            'acceptance_test_ref' => ['path' => $rel, 'hash' => $hash],
        ], ['app/Foo.php']);

        $this->assertTrue($result['passed']);
    }

    public function test_edited_acceptance_test_fails(): void
    {
        $rel = 'tests/FooTest.php';
        file_put_contents($this->root.'/'.$rel, '<?php // the oracle');
        $frozen = hash('sha256', 'DIFFERENT CONTENT');

        $result = $this->gate()->evaluate([
            'allowed_files' => ['app/Foo.php'],
            'acceptance_test_ref' => ['path' => $rel, 'hash' => $frozen],
        ], ['app/Foo.php']);

        $this->assertFalse($result['passed']);
        $this->assertSame('acceptance_test_hash_mismatch', $result['checks']['acceptance_test_untouched']['reason']);
    }

    public function test_acceptance_test_in_diff_fails(): void
    {
        $rel = 'tests/FooTest.php';
        file_put_contents($this->root.'/'.$rel, '<?php');

        $result = $this->gate()->evaluate([
            'allowed_files' => [$rel],
            'acceptance_test_ref' => ['path' => $rel, 'hash' => hash('sha256', '<?php')],
        ], [$rel]);

        $this->assertFalse($result['passed']);
        $this->assertSame('acceptance_test_in_diff', $result['checks']['acceptance_test_untouched']['reason']);
    }

    public function test_artisan_command_name_collision_fails(): void
    {
        $this->writeCommand('app/Console/Commands/ExistingCommand.php', 'atlas:existing');
        $this->writeCommand('app/Console/Commands/NewCommand.php', 'atlas:existing');

        $result = $this->gate()->evaluate(
            ['allowed_files' => ['app/Console/Commands/NewCommand.php']],
            ['app/Console/Commands/NewCommand.php'],
        );

        $this->assertFalse($result['passed']);
        $this->assertContains('atlas:existing', $result['checks']['no_artisan_command_collision']['names']);
    }

    public function test_unique_new_command_passes(): void
    {
        $this->writeCommand('app/Console/Commands/ExistingCommand.php', 'atlas:existing');
        $this->writeCommand('app/Console/Commands/NewCommand.php', 'atlas:brand-new');

        $result = $this->gate()->evaluate(
            ['allowed_files' => ['app/Console/Commands/NewCommand.php']],
            ['app/Console/Commands/NewCommand.php'],
        );

        $this->assertTrue($result['passed']);
    }

    public function test_diff_outside_allowed_fails(): void
    {
        $result = $this->gate()->evaluate(
            ['allowed_files' => ['app/Foo.php']],
            ['app/Foo.php', 'app/Sneaky.php'],
        );

        $this->assertFalse($result['passed']);
        $this->assertContains('app/Sneaky.php', $result['checks']['diff_within_allowed']['outside']);
    }

    public function test_non_kit_packet_passes_clean(): void
    {
        $result = $this->gate()->evaluate(['allowed_files' => ['app/Foo.php']], ['app/Foo.php']);
        $this->assertTrue($result['passed']);
    }
}
