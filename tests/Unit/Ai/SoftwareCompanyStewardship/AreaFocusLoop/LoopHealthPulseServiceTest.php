<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopHealthPulseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LoopHealthPulseServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_health_pulse_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): LoopHealthPulseService
    {
        return new LoopHealthPulseService();
    }

    // ---------------------------------------------------------------- schema

    public function test_pulse_returns_expected_schema_keys(): void
    {
        $svc = $this->service();
        $svc->setCommandRunnerForTesting(static fn (string $cmd): string => '');
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);

        $result = $svc->pulse(10, $this->tmp);

        $this->assertSame(LoopHealthPulseService::PULSE_SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('memory_mb', $result);
        $this->assertArrayHasKey('memory_limit_mb', $result);
        $this->assertArrayHasKey('memory_ok', $result);
        $this->assertArrayHasKey('git_object_count', $result);
        $this->assertArrayHasKey('git_gc_triggered', $result);
        $this->assertArrayHasKey('jsonl_size_mb', $result);
        $this->assertArrayHasKey('jsonl_rotated', $result);
        $this->assertArrayHasKey('zombie_pids_killed', $result);
        $this->assertArrayHasKey('disk_free_gb', $result);
        $this->assertArrayHasKey('cycle_index', $result);
        $this->assertArrayHasKey('healthy', $result);
        $this->assertSame(10, $result['cycle_index']);
    }

    // ---------------------------------------------------------------- memory check

    public function test_memory_ok_false_when_usage_exceeds_80_percent_limit(): void
    {
        $svc = $this->service();
        // Use a memory provider that returns a value exceeding 80% of the CURRENT limit.
        // We don't reduce the ini limit; instead we simulate very high usage (> 80% of
        // whatever the current limit is, or we fake both via the provider + ini read).
        // Strategy: get the current limit, then report 85% of it as usage.
        $currentRaw = trim((string) ini_get('memory_limit'));
        if ($currentRaw === '-1') {
            // Unlimited — simulate usage > 80% of an arbitrary reference (512 MB).
            $limitMb = 512;
        } else {
            $unit = strtolower(substr($currentRaw, -1));
            $value = (int) $currentRaw;
            $limitMb = match ($unit) {
                'g' => $value * 1024,
                'm' => $value,
                'k' => (int) round($value / 1024),
                default => (int) round($value / 1024 / 1024),
            };
        }

        // Report 85% of the limit as usage (> 80% threshold → memory_ok=false).
        $usageMb = (int) round($limitMb * 0.85);
        $svc->setMemoryProviderForTesting(static function () use ($usageMb): int { return $usageMb * 1024 * 1024; });
        $svc->setCommandRunnerForTesting(static fn (string $cmd): string => '');
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);

        $result = $svc->pulse(5, $this->tmp);

        $this->assertFalse($result['memory_ok'], 'Should be false when usage > 80% of limit');
        $this->assertSame($usageMb, $result['memory_mb']);
    }

    public function test_memory_ok_true_when_usage_below_80_percent(): void
    {
        $svc = $this->service();
        // Simulate usage at 50% of the current limit — well below the 80% threshold.
        $currentRaw = trim((string) ini_get('memory_limit'));
        if ($currentRaw === '-1') {
            $limitMb = 512;
        } else {
            $unit = strtolower(substr($currentRaw, -1));
            $value = (int) $currentRaw;
            $limitMb = match ($unit) {
                'g' => $value * 1024,
                'm' => $value,
                'k' => (int) round($value / 1024),
                default => (int) round($value / 1024 / 1024),
            };
        }

        $usageMb = (int) round($limitMb * 0.50);
        $svc->setMemoryProviderForTesting(static function () use ($usageMb): int { return $usageMb * 1024 * 1024; });
        $svc->setCommandRunnerForTesting(static fn (string $cmd): string => '');
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);

        $result = $svc->pulse(5, $this->tmp);

        $this->assertTrue($result['memory_ok'], 'Should be true when usage < 80% of limit');
    }

    // ---------------------------------------------------------------- git gc trigger

    public function test_git_gc_triggered_when_loose_objects_exceed_threshold(): void
    {
        $gcCalled = false;
        $svc = $this->service();
        $svc->setCommandRunnerForTesting(static function (string $cmd) use (&$gcCalled): string {
            if (str_contains($cmd, 'count-objects')) {
                return "count: 1200\nsize: 0\nin-pack: 0\npacks: 0\n";
            }
            if (str_contains($cmd, 'gc')) {
                $gcCalled = true;
            }

            return '';
        });
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);

        $result = $svc->pulse(10, $this->tmp);

        $this->assertTrue($result['git_gc_triggered']);
        $this->assertSame(1200, $result['git_object_count']);
        $this->assertTrue($gcCalled, 'Expected git gc command to have been called');
    }

    public function test_git_gc_not_triggered_when_loose_objects_below_threshold(): void
    {
        $svc = $this->service();
        $svc->setCommandRunnerForTesting(static fn (string $cmd): string => str_contains($cmd, 'count-objects')
            ? "count: 500\nsize: 0\n"
            : '');
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);

        $result = $svc->pulse(10, $this->tmp);

        $this->assertFalse($result['git_gc_triggered']);
        $this->assertSame(500, $result['git_object_count']);
    }

    // ---------------------------------------------------------------- JSONL rotation

    public function test_jsonl_rotated_when_file_exceeds_50mb(): void
    {
        $jsonlPath = $this->tmp.'/test.jsonl';
        file_put_contents($jsonlPath, '{"test":1}'.PHP_EOL);

        $svc = $this->service();
        $svc->setCommandRunnerForTesting(static fn (string $cmd): string => '');
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);
        // Simulate 51 MB file size
        $svc->setFileSizeProviderForTesting(static fn (string $p): int => 51 * 1024 * 1024);

        $result = $svc->pulse(20, $this->tmp, $jsonlPath);

        $this->assertTrue($result['jsonl_rotated']);
        $this->assertGreaterThan(50.0, $result['jsonl_size_mb']);
        // Original path should now be a new empty file.
        $this->assertFileExists($jsonlPath);
        $this->assertSame(0, filesize($jsonlPath) ?: 0);
        // Rotated archive must also exist.
        $rotatedFiles = glob($this->tmp.'/test.jsonl.rotated_at_cycle_*');
        $this->assertNotEmpty($rotatedFiles, 'Expected at least one rotated JSONL archive');
    }

    public function test_jsonl_not_rotated_when_file_under_50mb(): void
    {
        $jsonlPath = $this->tmp.'/test.jsonl';
        file_put_contents($jsonlPath, '{"test":1}'.PHP_EOL);

        $svc = $this->service();
        $svc->setCommandRunnerForTesting(static fn (string $cmd): string => '');
        $svc->setProcessTableForTesting(static fn (): array => []);
        $svc->setDiskFreeProviderForTesting(static fn (string $p): float => 50.0);
        // Simulate 10 MB file size
        $svc->setFileSizeProviderForTesting(static fn (string $p): int => 10 * 1024 * 1024);

        $result = $svc->pulse(20, $this->tmp, $jsonlPath);

        $this->assertFalse($result['jsonl_rotated']);
    }

    // ---------------------------------------------------------------- sandbox pre-validation

    public function test_sandbox_validation_valid_when_all_checks_pass(): void
    {
        $firewall = app(LoopPreflightCycleFirewallService::class);

        // Create a fake sandbox directory that won't trigger any failure.
        $sandboxPath = $this->tmp.'/sandbox';
        File::ensureDirectoryExists($sandboxPath);

        // No vendor directory — isolation check is skipped.
        // No artisan — artisan bootstrap check is skipped.
        // No modified files — syntax check is skipped.
        // Git status can fail gracefully — it returns empty.

        $result = $firewall->validateSandboxBeforeExecution($sandboxPath, $this->tmp, []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['failed_checks']);
        $this->assertSame('', $result['specific_reason']);
    }

    public function test_sandbox_validation_fails_when_directory_missing(): void
    {
        $firewall = app(LoopPreflightCycleFirewallService::class);

        $result = $firewall->validateSandboxBeforeExecution(
            $this->tmp.'/nonexistent_sandbox',
            $this->tmp,
            [],
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('sandbox_directory_missing', $result['failed_checks']);
        $this->assertNotEmpty($result['specific_reason']);
    }

    public function test_sandbox_validation_fails_when_vendor_isolation_broken(): void
    {
        $firewall = app(LoopPreflightCycleFirewallService::class);

        // Create a shared vendor directory and symlink it into both "main" and "sandbox".
        $sharedVendor = $this->tmp.'/shared_vendor';
        File::ensureDirectoryExists($sharedVendor);

        $mainPath = $this->tmp.'/main_repo';
        File::ensureDirectoryExists($mainPath);
        // Point main/vendor to shared_vendor.
        @symlink($sharedVendor, $mainPath.'/vendor');

        $sandboxPath = $this->tmp.'/sandbox_broken';
        File::ensureDirectoryExists($sandboxPath);
        // Point sandbox/vendor to the SAME shared_vendor (broken isolation).
        @symlink($sharedVendor, $sandboxPath.'/vendor');

        $result = $firewall->validateSandboxBeforeExecution($sandboxPath, $mainPath, []);

        $this->assertFalse($result['valid']);
        $this->assertContains('vendor_isolation_broken', $result['failed_checks']);
    }

    public function test_sandbox_validation_result_structure(): void
    {
        $firewall = app(LoopPreflightCycleFirewallService::class);

        $result = $firewall->validateSandboxBeforeExecution($this->tmp.'/missing', $this->tmp, []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('failed_checks', $result);
        $this->assertArrayHasKey('specific_reason', $result);
        $this->assertIsBool($result['valid']);
        $this->assertIsArray($result['failed_checks']);
        $this->assertIsString($result['specific_reason']);
    }

    // ---------------------------------------------------------------- never throws

    public function test_pulse_never_throws_on_invalid_repo_root(): void
    {
        $svc = $this->service();

        $result = $svc->pulse(1, '/nonexistent/path/xyz');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('healthy', $result);
    }

    public function test_pulse_never_throws_on_missing_jsonl_path(): void
    {
        $svc = $this->service();

        $result = $svc->pulse(1, $this->tmp, '/nonexistent/ledger.jsonl');

        $this->assertIsArray($result);
        $this->assertSame(0.0, $result['jsonl_size_mb']);
        $this->assertFalse($result['jsonl_rotated']);
    }

    // ---------------------------------------------------------------- constants

    public function test_pulse_constants_have_expected_values(): void
    {
        $this->assertSame(10, LoopHealthPulseService::PULSE_EVERY_N_CYCLES);
        $this->assertSame(50, LoopHealthPulseService::JSONL_ROTATE_MB);
        $this->assertSame(1000, LoopHealthPulseService::GIT_GC_LOOSE_OBJECT_THRESHOLD);
    }
}
