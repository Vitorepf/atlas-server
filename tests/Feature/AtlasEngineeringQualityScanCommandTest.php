<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasEngineeringQualityScanCommandTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    private string $workspace;

    private string $binDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
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

    public function test_release_quality_scan_runs_security_and_sbom_tools_when_available(): void
    {
        File::put($this->workspace.'/composer.lock', json_encode(['packages' => []]));
        $this->installFakeExecutable('osv-scanner', <<<'PHP'
echo json_encode([
    'results' => [[
        'source' => ['path' => 'composer.lock'],
        'packages' => [[
            'package' => ['name' => 'vendor/package', 'ecosystem' => 'Packagist', 'version' => '1.0.0'],
            'vulnerabilities' => [['id' => 'OSV-2026-0001', 'summary' => 'OSV vulnerable dependency']],
        ]],
    ]],
])."\n";
exit(1);
PHP);
        $this->installFakeExecutable('trivy', <<<'PHP'
echo json_encode([
    'Results' => [[
        'Target' => 'composer.lock',
        'Vulnerabilities' => [[
            'VulnerabilityID' => 'CVE-2026-0001',
            'Severity' => 'CRITICAL',
            'Title' => 'Critical dependency vulnerability',
            'PkgName' => 'vendor/package',
            'InstalledVersion' => '1.0.0',
            'FixedVersion' => '1.0.1',
        ]],
    ]],
])."\n";
exit(1);
PHP);
        $this->installFakeExecutable('syft', <<<'PHP'
echo json_encode([
    'source' => ['type' => 'directory', 'name' => '.'],
    'artifacts' => [
        ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
        ['name' => 'node/package', 'version' => '2.0.0', 'type' => 'npm-package'],
    ],
])."\n";
exit(0);
PHP);
        $this->installFakeExecutable('grype', <<<'PHP'
echo json_encode([
    'matches' => [[
        'vulnerability' => [
            'id' => 'GHSA-2026-0001',
            'severity' => 'High',
            'description' => 'Grype vulnerable dependency',
            'fix' => ['versions' => ['1.0.1']],
        ],
        'artifact' => [
            'name' => 'vendor/package',
            'version' => '1.0.0',
            'type' => 'php-composer-package',
            'locations' => [['path' => 'composer.lock']],
        ],
    ]],
])."\n";
exit(1);
PHP);

        $exit = Artisan::call('atlas:engineering:quality-scan', [
            '--workspace' => $this->workspace,
            '--profile' => 'release',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $tools = collect($payload['tools'] ?? [])->keyBy('slug');
        $ruleIds = collect($payload['findings'] ?? [])->pluck('rule_id')->all();

        $this->assertSame(1, $exit);
        $this->assertSame('failed', $payload['status'] ?? null);
        $this->assertSame('security', data_get($tools, 'osv_scanner.category'));
        $this->assertSame('security', data_get($tools, 'trivy.category'));
        $this->assertSame('supply_chain', data_get($tools, 'syft.category'));
        $this->assertSame('supply_chain', data_get($tools, 'grype.category'));
        $this->assertSame('passed', data_get($tools, 'syft.status'));
        $this->assertSame(2, data_get($tools, 'syft.metrics.package_count'));
        $this->assertSame(1, data_get($tools, 'syft.metrics.package_type_counts.php-composer-package'));
        $this->assertSame('sbom_summary', data_get($tools, 'syft.artifacts.0.type'));
        $this->assertSame(1, data_get($tools, 'trivy.metrics.severity_counts.critical'));
        $this->assertSame(1, data_get($tools, 'grype.metrics.severity_counts.high'));
        $this->assertSame(1, data_get($tools, 'osv_scanner.metrics.vulnerability_count'));
        $this->assertContains('OSV-2026-0001', $ruleIds);
        $this->assertContains('CVE-2026-0001', $ruleIds);
        $this->assertContains('GHSA-2026-0001', $ruleIds);
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'summary.blocking_finding_count'));
        $this->assertFileExists($payload['artifact_root'].'/syft/stdout.txt');
        $this->assertFileExists($payload['artifact_root'].'/grype/result.json');
    }

    public function test_security_scan_command_runs_only_security_and_vulnerability_tools(): void
    {
        File::put($this->workspace.'/composer.lock', json_encode(['packages' => []]));
        File::put($this->workspace.'/composer.json', json_encode(['name' => 'atlas/test']));
        $this->installFakeExecutable('trivy', <<<'PHP'
echo json_encode([
    'Results' => [[
        'Target' => 'composer.lock',
        'Vulnerabilities' => [[
            'VulnerabilityID' => 'CVE-2026-SECURITY',
            'Severity' => 'HIGH',
            'Title' => 'Security scan vulnerability',
        ]],
    ]],
])."\n";
exit(1);
PHP);
        $this->installFakeExecutable('grype', <<<'PHP'
echo json_encode(['matches' => []])."\n";
exit(0);
PHP);

        $exit = Artisan::call('atlas:engineering:security-scan', [
            '--workspace' => $this->workspace,
            '--profile' => 'release',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $toolSlugs = collect($payload['tools'] ?? [])->pluck('slug')->all();

        $this->assertSame(1, $exit);
        $this->assertSame(['gitleaks', 'semgrep', 'osv_scanner', 'trivy', 'grype'], $toolSlugs);
        $this->assertNotContains('composer_validate', $toolSlugs);
        $this->assertNotContains('laravel_pint', $toolSlugs);
        $this->assertSame(['gitleaks', 'semgrep', 'osv_scanner', 'trivy', 'grype'], data_get($payload, 'scope.include_tools'));
        $this->assertSame('security', data_get(collect($payload['tools'])->firstWhere('slug', 'trivy'), 'category'));
        $this->assertSame('supply_chain', data_get(collect($payload['tools'])->firstWhere('slug', 'grype'), 'category'));
        $this->assertContains('CVE-2026-SECURITY', collect($payload['findings'] ?? [])->pluck('rule_id')->all());
    }

    public function test_sbom_command_runs_only_syft_and_returns_sbom_metrics(): void
    {
        $this->installFakeExecutable('syft', <<<'PHP'
echo json_encode([
    'source' => ['type' => 'directory', 'name' => '.'],
    'artifacts' => [
        ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
    ],
])."\n";
exit(0);
PHP);

        $exit = Artisan::call('atlas:engineering:sbom', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $toolSlugs = collect($payload['tools'] ?? [])->pluck('slug')->all();

        $this->assertSame(0, $exit);
        $this->assertSame(['syft'], $toolSlugs);
        $this->assertSame(['syft'], data_get($payload, 'scope.include_tools'));
        $this->assertSame('passed', data_get($payload, 'tools.0.status'));
        $this->assertSame(1, data_get($payload, 'tools.0.metrics.package_count'));
        $this->assertSame('sbom_summary', data_get($payload, 'tools.0.artifacts.0.type'));
    }

    public function test_security_scan_api_uses_same_filtered_runtime_contract(): void
    {
        File::put($this->workspace.'/composer.lock', json_encode(['packages' => []]));
        $this->installFakeExecutable('trivy', <<<'PHP'
echo json_encode([
    'Results' => [[
        'Target' => 'composer.lock',
        'Vulnerabilities' => [[
            'VulnerabilityID' => 'CVE-2026-API',
            'Severity' => 'HIGH',
            'Title' => 'API security scan vulnerability',
        ]],
    ]],
])."\n";
exit(1);
PHP);

        $this->postJson('/engineering/security-scan', [
            'workspace' => $this->workspace,
            'profile' => 'release',
            'run_context_type' => 'api_context',
            'run_context_id' => 'security-api-test',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('scope.include_tools.3', 'trivy')
            ->assertJsonPath('tools.3.slug', 'trivy')
            ->assertJsonPath('tools.3.metrics.severity_counts.high', 1)
            ->assertJsonPath('findings.0.rule_id', 'CVE-2026-API');
    }

    public function test_sbom_api_uses_same_filtered_runtime_contract(): void
    {
        $this->installFakeExecutable('syft', <<<'PHP'
echo json_encode([
    'source' => ['type' => 'directory', 'name' => '.'],
    'artifacts' => [
        ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
    ],
])."\n";
exit(0);
PHP);

        $this->postJson('/engineering/sbom', [
            'workspace' => $this->workspace,
            'profile' => 'release',
            'run_context_type' => 'api_context',
            'run_context_id' => 'sbom-api-test',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('scope.include_tools.0', 'syft')
            ->assertJsonPath('tools.0.slug', 'syft')
            ->assertJsonPath('tools.0.metrics.package_count', 1)
            ->assertJsonPath('tools.0.artifacts.0.type', 'sbom_summary');
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

    private function installFakeExecutable(string $name, string $body): void
    {
        $php = PHP_BINARY;
        File::put($this->binDir.'/'.$name, <<<PHP
#!{$php}
<?php
{$body}
PHP);
        chmod($this->binDir.'/'.$name, 0755);
    }
}
