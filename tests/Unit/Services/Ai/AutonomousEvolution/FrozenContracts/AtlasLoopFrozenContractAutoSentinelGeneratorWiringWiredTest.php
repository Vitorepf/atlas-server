<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\FrozenContracts;

use App\Console\Commands\AtlasLoopFrozenContractsCommand;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractAutoSentinelGenerator;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractSentinelClobberException;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopFrozenContractAutoSentinelGeneratorWiringWiredTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/atlas-fc-sent-'.bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tempDir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_clobber_exception_thrown_when_target_exists_without_receipt(): void
    {
        $target = $this->tempDir.'/SomeFrozenContractSentinelTest.php';
        file_put_contents($target, 'preexisting');

        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator();
        $this->expectException(AtlasLoopFrozenContractSentinelClobberException::class);
        $generator->generate(
            ['class' => 'App\\Some\\FrozenContract', 'invariants' => ['inv1']],
            $target,
            null,
        );
    }

    public function test_cli_generate_sentinel_writes_exit_clobber_refused_when_target_exists(): void
    {
        $target = $this->tempDir.'/PreExistingSentinelTest.php';
        file_put_contents($target, '// pre-existing');

        $exit = Artisan::call('atlas:loop:frozen:contracts', [
            'action' => 'generate-sentinel',
            '--class' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopMasterSwitch',
            '--target' => $target,
            '--json' => true,
        ]);
        $this->assertSame(AtlasLoopFrozenContractsCommand::EXIT_CLOBBER_REFUSED, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('sentinel_clobber_refused', $decoded['reason']);
        $this->assertSame('// pre-existing', file_get_contents($target), 'file must be untouched');
    }

    public function test_cli_generate_sentinel_emits_scaffold_when_target_does_not_exist(): void
    {
        $target = $this->tempDir.'/NewSentinelTest.php';
        $this->assertFileDoesNotExist($target);

        $exit = Artisan::call('atlas:loop:frozen:contracts', [
            'action' => 'generate-sentinel',
            '--class' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopMasterSwitch',
            '--target' => $target,
            '--json' => true,
        ]);
        $this->assertSame(AtlasLoopFrozenContractsCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertArrayHasKey('class_name', $decoded);
    }

    public function test_cli_generate_sentinel_missing_args_returns_usage_exit(): void
    {
        $exit = Artisan::call('atlas:loop:frozen:contracts', ['action' => 'generate-sentinel']);
        $this->assertSame(AtlasLoopFrozenContractsCommand::EXIT_USAGE, $exit);
    }
}
