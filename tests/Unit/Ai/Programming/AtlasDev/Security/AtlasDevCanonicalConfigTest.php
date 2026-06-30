<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for review findings F-02 and F-08:
 *
 *   F-02 confirmation_token TTL default must be 300s, not 3600s.
 *   F-08 there must be exactly ONE canonical config source for Atlas Dev
 *        Efficient knobs (config/atlas_dev.php). The legacy duplicate block
 *        under config('atlas.dev.*') must not exist.
 */
final class AtlasDevCanonicalConfigTest extends TestCase
{
    private array $atlasDevConfig;

    private string $atlasConfigSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forceEnv('ATLAS_DEV_RECEIPTS_PATH', sys_get_temp_dir().'/atlas-dev/receipts');
        $this->atlasDevConfig = require $this->repoPath('config/atlas_dev.php');
        $this->atlasConfigSource = (string) file_get_contents($this->repoPath('config/atlas.php'));
    }

    public function test_confirmation_token_ttl_default_is_300_seconds(): void
    {
        $this->assertSame(
            300,
            (int) $this->atlasDevConfig['confirmation_token']['ttl_seconds'],
            'F-02: TTL default must be 300s per canonical decision.',
        );
    }

    public function test_legacy_atlas_dev_config_block_is_gone(): void
    {
        $this->assertNull(
            preg_match("/['\"]dev['\"]\\s*=>/", $this->atlasConfigSource) === 1 ? 'dev' : null,
            'F-08: config/atlas.php must not carry a duplicate "dev" block.',
        );
    }

    public function test_canonical_atlas_dev_keys_exist(): void
    {
        $this->assertIsInt($this->atlasDevConfig['confirmation_token']['ttl_seconds']);
        $this->assertIsInt($this->atlasDevConfig['confirmation_token']['plaintext_bytes']);
        $this->assertIsInt($this->atlasDevConfig['stream']['timeout_seconds']);
        $this->assertIsInt($this->atlasDevConfig['stream']['keepalive_seconds']);
        $this->assertIsBool($this->atlasDevConfig['efficient']['plan_enabled']);
        $this->assertIsBool($this->atlasDevConfig['efficient']['run_enabled']);
        $this->assertIsBool($this->atlasDevConfig['efficient']['desktop_enabled']);
        $this->assertIsString($this->atlasDevConfig['receipts_path']);
    }

    public function test_stream_defaults_are_300_and_15(): void
    {
        $this->assertSame(300, (int) $this->atlasDevConfig['stream']['timeout_seconds']);
        $this->assertSame(15, (int) $this->atlasDevConfig['stream']['keepalive_seconds']);
    }

    /**
     * VAL-M1-001 / VAL-M1-015: the canonical spine ships `run_enabled` ON by
     * default (env fallback true, not false). `desktop_enabled` remains OFF
     * for safety until the desktop surface is explicitly opted in. The old
     * pre-M1 assertion of a `false` run_enabled default is retired — no stale
     * `false` default assertion remains for the run surface.
     */
    public function test_run_enabled_default_on_and_desktop_default_off(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));

        $this->assertStringContainsString("'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', true)", $configSource);
        $this->assertStringContainsString("'desktop_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED', false)", $configSource);
    }

    public function test_confirmation_token_service_reads_300_ttl_default(): void
    {
        // F-05 canonical: ConfirmationTokenService is the only token backend.
        // The legacy filesystem ConfirmationTokenStore was removed; this guards
        // that the canonical TTL=300s contract still flows into the service.
        $providerSource = (string) file_get_contents($this->repoPath('app/Providers/AtlasDevServiceProvider.php'));

        $this->assertSame(300, (int) $this->atlasDevConfig['confirmation_token']['ttl_seconds']);
        $this->assertStringContainsString(
            'singleton(ConfirmationTokenService::class',
            $providerSource,
            'F-02/F-05: canonical confirmation token service must be registered by AtlasDevServiceProvider.',
        );
    }

    public function test_production_run_driver_bindings_are_registered(): void
    {
        $providerSource = (string) file_get_contents($this->repoPath('app/Providers/AtlasDevServiceProvider.php'));

        $this->assertStringContainsString('singleton(ClaudeCliGateway::class', $providerSource);
        $this->assertStringContainsString('new SymfonyClaudeCliGateway', $providerSource);
        $this->assertStringContainsString('singleton(VerificationCommandRunner::class', $providerSource);
        $this->assertStringContainsString('new SymfonyProcessCommandRunner', $providerSource);
    }

    public function test_env_override_propagates_to_confirmation_token_service(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));
        $serviceSource = (string) file_get_contents($this->repoPath('app/Services/Ai/Programming/AtlasDev/Security/ConfirmationTokenService.php'));

        $this->assertStringContainsString('ATLAS_DEV_CONFIRMATION_TOKEN_TTL_SECONDS', $configSource);
        $this->assertStringContainsString("config('atlas_dev.confirmation_token.ttl_seconds', 300)", $serviceSource);
    }

    public function test_legacy_filesystem_confirmation_token_store_is_gone(): void
    {
        // F-05: the orphan filesystem ConfirmationTokenStore was removed. Any
        // residual reference would re-introduce a second source of truth.
        $this->assertFalse(
            class_exists('App\\Http\\Controllers\\AtlasDev\\Support\\ConfirmationTokenStore'),
            'F-05: filesystem ConfirmationTokenStore must not exist.',
        );
        $this->assertFalse(
            class_exists('App\\Http\\Controllers\\AtlasDev\\Support\\ConfirmationTokenStatus'),
            'F-05: ConfirmationTokenStatus enum (filesystem path) must not exist.',
        );
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
}
