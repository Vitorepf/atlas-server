<?php

namespace Tests\Feature;

use App\Services\Ai\Cli\AtlasCliDogfoodService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliDogfoodCommandTest extends TestCase
{
    private string $workspace;

    private string $dogfoodPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dogfood-test-'.bin2hex(random_bytes(4));
        $this->dogfoodPath = sys_get_temp_dir().'/atlas-cli-dogfood-'.bin2hex(random_bytes(4)).'.json';
        File::ensureDirectoryExists($this->workspace);

        config(['atlas.cli.dogfood_path' => $this->dogfoodPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::delete($this->dogfoodPath);

        parent::tearDown();
    }

    public function test_dogfood_records_session_and_reports_coverage(): void
    {
        $recordExit = Artisan::call('atlas:cli:dogfood', [
            'action' => 'record',
            '--workspace' => $this->workspace,
            '--scenario' => 'dev_task',
            '--provider' => 'codex_cli',
            '--result' => 'passed',
            '--duration-minutes' => 42,
            '--json' => true,
        ]);

        $this->assertSame(0, $recordExit);
        $this->assertFileExists($this->dogfoodPath);
        $this->assertStringContainsString('"dev_task"', Artisan::output());

        $reportExit = Artisan::call('atlas:cli:dogfood', [
            'action' => 'report',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $reportExit);
        $this->assertStringContainsString('"covered_scenarios"', $output);
        $this->assertStringContainsString('"dev_task"', $output);
        $this->assertStringContainsString('"missing_scenarios"', $output);
    }

    public function test_strict_report_requires_real_non_smoke_coverage(): void
    {
        /** @var AtlasCliDogfoodService $dogfood */
        $dogfood = app(AtlasCliDogfoodService::class);

        foreach ($dogfood->requiredScenarios() as $scenario) {
            $dogfood->record(
                workspace: $this->workspace,
                scenario: $scenario,
                provider: 'codex_cli',
                result: 'passed',
                durationMinutes: 1,
                notes: 'automated smoke evidence',
                metadata: ['profile' => 'smoke'],
            );
        }

        $smokeOnlyExit = Artisan::call('atlas:cli:dogfood', [
            'action' => 'report',
            '--workspace' => $this->workspace,
            '--days' => 1,
            '--strict' => true,
            '--json' => true,
        ]);
        $smokeOnlyOutput = Artisan::output();

        $this->assertSame(1, $smokeOnlyExit);
        $this->assertStringContainsString('"requires_real_usage": true', $smokeOnlyOutput);
        $this->assertStringContainsString('"real_usage_coverage"', $smokeOnlyOutput);
        $this->assertStringContainsString('"missing_real_scenarios"', $smokeOnlyOutput);

        foreach ($dogfood->requiredScenarios() as $scenario) {
            $dogfood->record(
                workspace: $this->workspace,
                scenario: $scenario,
                provider: 'codex_cli',
                result: 'passed',
                durationMinutes: 5,
                notes: 'real operator evidence',
            );
        }

        $realExit = Artisan::call('atlas:cli:dogfood', [
            'action' => 'report',
            '--workspace' => $this->workspace,
            '--days' => 1,
            '--strict' => true,
            '--json' => true,
        ]);
        $realOutput = Artisan::output();

        $this->assertSame(0, $realExit);
        $this->assertStringContainsString('"status": "passed"', $realOutput);
        $this->assertStringContainsString('"missing_real_scenarios": []', $realOutput);
    }
}
