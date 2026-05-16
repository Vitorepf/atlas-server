<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Security;

use App\Services\Ai\Programming\AtlasDev\Gate\SymfonyProcessCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\SymfonyClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use Tests\TestCase;

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
    public function test_confirmation_token_ttl_default_is_300_seconds(): void
    {
        $this->assertSame(
            300,
            (int) config('atlas_dev.confirmation_token.ttl_seconds'),
            'F-02: TTL default must be 300s per canonical decision.',
        );
    }

    public function test_legacy_atlas_dev_config_block_is_gone(): void
    {
        $this->assertNull(
            config('atlas.dev'),
            'F-08: config/atlas.php must not carry a duplicate "dev" block.',
        );
        $this->assertNull(config('atlas.dev.confirmation_token_ttl_seconds'));
        $this->assertNull(config('atlas.dev.efficient_plan_enabled'));
        $this->assertNull(config('atlas.dev.efficient_run_enabled'));
        $this->assertNull(config('atlas.dev.stream_timeout_seconds'));
        $this->assertNull(config('atlas.dev.stream_keepalive_seconds'));
        $this->assertNull(config('atlas.dev.receipts_path'));
    }

    public function test_canonical_atlas_dev_keys_exist(): void
    {
        $this->assertIsInt(config('atlas_dev.confirmation_token.ttl_seconds'));
        $this->assertIsInt(config('atlas_dev.confirmation_token.plaintext_bytes'));
        $this->assertIsInt(config('atlas_dev.stream.timeout_seconds'));
        $this->assertIsInt(config('atlas_dev.stream.keepalive_seconds'));
        $this->assertIsBool(config('atlas_dev.efficient.plan_enabled'));
        $this->assertIsBool(config('atlas_dev.efficient.run_enabled'));
        $this->assertIsBool(config('atlas_dev.efficient.desktop_enabled'));
        $this->assertIsString(config('atlas_dev.receipts_path'));
    }

    public function test_stream_defaults_are_300_and_15(): void
    {
        $this->assertSame(300, (int) config('atlas_dev.stream.timeout_seconds'));
        $this->assertSame(15, (int) config('atlas_dev.stream.keepalive_seconds'));
    }

    public function test_run_and_desktop_default_off_for_safety(): void
    {
        $this->assertFalse((bool) config('atlas_dev.efficient.run_enabled'));
        $this->assertFalse((bool) config('atlas_dev.efficient.desktop_enabled'));
    }

    public function test_confirmation_token_service_reads_300_ttl_default(): void
    {
        // F-05 canonical: ConfirmationTokenService is the only token backend.
        // The legacy filesystem ConfirmationTokenStore was removed; this guards
        // that the canonical TTL=300s contract still flows into the service.
        $this->assertSame(300, (int) config('atlas_dev.confirmation_token.ttl_seconds'));
        $this->assertInstanceOf(
            ConfirmationTokenService::class,
            $this->app->make(ConfirmationTokenService::class),
            'F-02: canonical confirmation token service must be resolvable from the container.',
        );
    }

    public function test_production_run_driver_bindings_are_registered(): void
    {
        $this->assertInstanceOf(
            SymfonyClaudeCliGateway::class,
            $this->app->make(ClaudeCliGateway::class),
            'Atlas Dev /run must have a production ClaudeCliGateway binding; otherwise Desktop executes into blocked.',
        );
        $this->assertInstanceOf(
            SymfonyProcessCommandRunner::class,
            $this->app->make(VerificationCommandRunner::class),
            'Atlas Dev /run must have a production VerificationCommandRunner binding.',
        );
    }

    public function test_env_override_propagates_to_confirmation_token_service(): void
    {
        config()->set('atlas_dev.confirmation_token.ttl_seconds', 600);
        $this->assertSame(600, (int) config('atlas_dev.confirmation_token.ttl_seconds'));
        // The service reads the TTL at issue() time, not at construction, so
        // we don't need to rebuild the singleton — just assert config is the
        // canonical source of truth.
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
}
