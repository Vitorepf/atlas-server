<?php

namespace Tests\Feature\Ai\Rivals;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EngineeringNativeUnitScriptTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/rivals_engineering_unit_'.uniqid();
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    public function test_plan_binds_bare_to_raw_verboo_and_atlas_to_real_cli_dev_bridge(): void
    {
        $caseFile = base_path('tests/Fixtures/Rivals/cases/archbench/archbench_adr_000.json');

        $bare = $this->plan($caseFile, 'bare');
        $this->assertSame('rivals-hermes-bare.php', basename($bare['solver_argv'][1]));
        $this->assertSame('bare', $bare['runtime']);
        $this->assertSame('kimi-k2.7', $bare['model']);
        $this->assertStringNotContainsString('atlas-dev', implode(' ', $bare['solver_argv']));

        $atlas = $this->plan($caseFile, 'atlas_dev');
        $this->assertSame('rivals-atlas-dev-bridge.php', basename($atlas['solver_argv'][1]));
        $this->assertSame('atlas_dev', $atlas['runtime']);
        $this->assertStringContainsString('rivals-atlas-dev-bridge.php', implode(' ', $atlas['solver_argv']));
        $this->assertNotSame($bare['solver_argv'][1], $atlas['solver_argv'][1]);
    }

    public function test_plan_rejects_an_unimplemented_suite_or_runtime_before_provider_spend(): void
    {
        $caseFile = base_path('tests/Fixtures/Rivals/cases/archbench/archbench_adr_000.json');
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-engineering-unit.php'),
            '--suite=archbench',
            '--case-file='.$caseFile,
            '--model=kimi-k2.7',
            '--registry-model=kimi-k2.7',
            '--runtime=forge',
            '--scratch='.$this->scratch,
            '--rep=1',
            '--plan',
        ], base_path());
        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString(
            'rivals_engineering_unit_invalid_runtime',
            $process->getErrorOutput(),
        );
    }

    /** @return array<string, mixed> */
    private function plan(string $caseFile, string $runtime): array
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-engineering-unit.php'),
            '--suite=archbench',
            '--case-file='.$caseFile,
            '--model=kimi-k2.7',
            '--registry-model=kimi-k2.7',
            '--runtime='.$runtime,
            '--scratch='.$this->scratch,
            '--rep=1',
            '--plan',
        ], base_path());
        $process->mustRun();
        $payload = json_decode($process->getOutput(), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
