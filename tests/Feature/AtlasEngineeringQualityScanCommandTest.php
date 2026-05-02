<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasEngineeringQualityScanCommandTest extends TestCase
{
    private string $workspace;

    private string $binDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH');
        $this->workspace = sys_get_temp_dir().'/atlas-quality-scan-'.bin2hex(random_bytes(4));
        $this->binDir = sys_get_temp_dir().'/atlas-quality-scan-bin-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->binDir);
        putenv('PATH='.$this->binDir.':'.($this->originalPath !== false ? $this->originalPath : ''));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::deleteDirectory($this->binDir);
        if ($this->originalPath !== false) {
            putenv('PATH='.$this->originalPath);
        }

        parent::tearDown();
    }

    public function test_quality_scan_skips_missing_tools_without_requiring_paid_services(): void
    {
        File::put($this->workspace.'/eslint.config.js', 'export default [];');

        $exit = Artisan::call('atlas:engineering:quality-scan', [
            '--workspace' => $this->workspace,
            '--profile' => 'fast',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['paid_tool_required'] ?? true));
        $this->assertSame('free_local_or_project_local', $payload['cost_posture'] ?? null);
        $this->assertGreaterThan(0, data_get($payload, 'summary.skipped_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.recommendation_count'));
        $this->assertSame('eslint', data_get($payload, 'recommendations.0.tool'));
        $this->assertFalse((bool) data_get($payload, 'recommendations.0.paid_tool_required'));
        $this->assertFileExists($payload['artifact_root'].'/scan.json');
    }

    public function test_quality_scan_normalizes_gitleaks_findings(): void
    {
        $this->installFakeGitleaks();

        $exit = Artisan::call('atlas:engineering:quality-scan', [
            '--workspace' => $this->workspace,
            '--profile' => 'standard',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $gitleaks = collect($payload['tools'] ?? [])->firstWhere('slug', 'gitleaks');

        $this->assertSame(1, $exit);
        $this->assertSame('failed', $payload['status'] ?? null);
        $this->assertSame('failed', data_get($gitleaks, 'status'));
        $this->assertSame('gitleaks', data_get($payload, 'findings.0.tool'));
        $this->assertSame('critical', data_get($payload, 'findings.0.severity'));
        $this->assertSame('config/app.php', data_get($payload, 'findings.0.file'));
        $this->assertTrue((bool) data_get($payload, 'findings.0.blocks_resolved'));
        $this->assertFileExists($payload['artifact_root'].'/gitleaks/stdout.txt');
        $this->assertFileExists($payload['artifact_root'].'/gitleaks/result.json');
    }

    private function installFakeGitleaks(): void
    {
        $php = PHP_BINARY;
        File::put($this->binDir.'/gitleaks', <<<PHP
#!{$php}
<?php
echo json_encode([[
    'RuleID' => 'generic-api-key',
    'Description' => 'Generic API key',
    'File' => 'config/app.php',
    'StartLine' => 12,
]])."\\n";
exit(1);
PHP);
        chmod($this->binDir.'/gitleaks', 0755);
    }
}
