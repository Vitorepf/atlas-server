<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Security;

use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

final class AtlasDevFeatureFlagsTest extends TestCase
{
    private array $atlasDevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forceEnv('ATLAS_DEV_RECEIPTS_PATH', sys_get_temp_dir().'/atlas-dev/receipts');
        $this->atlasDevConfig = require $this->repoPath('config/atlas_dev.php');
    }

    public function test_feature_flag_keys_exist(): void
    {
        $flags = $this->atlasDevConfig['efficient'];

        $this->assertIsArray($flags);
        $this->assertArrayHasKey('enabled', $flags);
        $this->assertArrayHasKey('plan_enabled', $flags);
        $this->assertArrayHasKey('run_enabled', $flags);
        $this->assertArrayHasKey('desktop_enabled', $flags);
    }

    /**
     * VAL-M1-001 / VAL-M1-015: the canonical spine is ON by default. The
     * `run_enabled` env fallback in config/atlas_dev.php flips from false to
     * true so `atlas dev '<task>' --yes` executes the governed pipeline out of
     * the box. `desktop_enabled` stays OFF until the desktop UX is explicitly
     * opted in (M1 turns the run spine on, not the desktop surface).
     */
    public function test_run_enabled_is_on_by_default_and_desktop_remains_off(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));

        $this->assertStringContainsString("'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', true)", $configSource);
        $this->assertStringContainsString("'desktop_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED', false)", $configSource);
    }

    /**
     * VAL-M1-001: with no ATLAS_DEV_EFFICIENT_RUN_ENABLED env var set, a
     * runtime read of the canonical config yields a truthy run_enabled — the
     * single switch that makes the spine live by default.
     */
    public function test_run_enabled_resolves_true_when_env_unset(): void
    {
        $this->clearEnv('ATLAS_DEV_EFFICIENT_RUN_ENABLED');
        $config = require $this->repoPath('config/atlas_dev.php');

        $this->assertTrue((bool) $config['efficient']['run_enabled']);
    }

    /**
     * VAL-M1-004: the operator can still opt out. Explicit
     * ATLAS_DEV_EFFICIENT_RUN_ENABLED=false makes the canonical config resolve
     * run_enabled to false, so the guard sites re-engage the hard block
     * (CLI exit 2 / HTTP 503 ATLAS_DEV_RUN_DISABLED). The guard remains a
     * safety/capability check; it just no longer hard-blocks by default.
     */
    public function test_explicit_run_enabled_env_false_re_enables_hard_block(): void
    {
        $this->forceEnv('ATLAS_DEV_EFFICIENT_RUN_ENABLED', 'false');
        $config = require $this->repoPath('config/atlas_dev.php');

        $this->assertFalse((bool) $config['efficient']['run_enabled']);

        // Restore the process env so the override never leaks into later tests.
        $this->clearEnv('ATLAS_DEV_EFFICIENT_RUN_ENABLED');
    }

    /**
     * VAL-M2-001: the E1 (intent-falsification / coverage probe) elevation is
     * promoted advisory -> hard. The canonical config source
     * config/atlas_dev.php ships `elevations.e1.mode` with an env fallback of
     * `hard` (not `advisory`). This is the single switch that makes the E1
     * hard branch live by default: a hard E1 trip on an intent-missing diff
     * produces a `failed` completion (not `needs_review`).
     */
    public function test_e1_mode_config_source_defaults_to_hard(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));

        $this->assertStringContainsString(
            "'mode' => env('ATLAS_DEV_ELEVATION_E1_MODE', 'hard')",
            $configSource,
            'VAL-M2-001: the e1.mode config source default must be hard (promoted from advisory).',
        );
    }

    /**
     * VAL-M2-001: with no ATLAS_DEV_ELEVATION_E1_MODE env var set, a runtime
     * read of the canonical config yields `elevations.e1.mode === 'hard'`, and
     * the elevation resolver classifies E1 as hard
     * (ElevationConfig::for('e1', $block)->isHard() === true). This proves the
     * hard default is live at the resolution layer, not just in the source.
     */
    public function test_e1_mode_resolves_hard_when_env_unset(): void
    {
        $this->clearEnv('ATLAS_DEV_ELEVATION_E1_MODE');
        $config = require $this->repoPath('config/atlas_dev.php');

        $this->assertSame('hard', $config['elevations']['e1']['mode']);

        $e1 = ElevationConfig::for('e1', $config['elevations']['e1']);
        $this->assertTrue($e1->isHard(), 'VAL-M2-001: ElevationConfig must classify e1 as hard by default.');
        $this->assertFalse($e1->isAdvisory());
        $this->assertFalse($e1->isOff());
    }

    public function test_plan_enabled_is_on_under_testing_environment(): void
    {
        $this->assertTrue((bool) $this->atlasDevConfig['efficient']['plan_enabled']);
        $this->assertTrue((bool) $this->atlasDevConfig['efficient']['enabled']);
    }

    public function test_confirmation_token_defaults_are_safe(): void
    {
        $this->assertSame(300, (int) $this->atlasDevConfig['confirmation_token']['ttl_seconds']);
        $this->assertGreaterThanOrEqual(16, (int) $this->atlasDevConfig['confirmation_token']['plaintext_bytes']);
    }

    public function test_run_index_limits_are_sane(): void
    {
        $default = (int) $this->atlasDevConfig['run_index']['list_default_limit'];
        $max = (int) $this->atlasDevConfig['run_index']['list_max_limit'];

        $this->assertGreaterThan(0, $default);
        $this->assertGreaterThanOrEqual($default, $max);
    }

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 6).'/'.$relative;
    }

    private function forceEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnv(string $key): void
    {
        putenv($key); // no argument unsets the variable.
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
