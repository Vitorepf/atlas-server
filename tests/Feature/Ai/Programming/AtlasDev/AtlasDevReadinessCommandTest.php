<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasDevReadinessCommandTest extends TestCase
{
    private string $tmpBin;

    private string $tmpReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBin = sys_get_temp_dir().'/atlas-dev-readiness-bin-'.bin2hex(random_bytes(4));
        $this->tmpReceipts = sys_get_temp_dir().'/atlas-dev-readiness-'.bin2hex(random_bytes(4));
        mkdir($this->tmpBin, 0o755, true);
        file_put_contents($this->tmpBin.'/claude', "#!/bin/sh\nexit 0\n");
        chmod($this->tmpBin.'/claude', 0o755);

        config()->set('atlas_dev.receipts_path', $this->tmpReceipts);
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');
        config()->set('atlas.ai.providers.claude_cli.binary', $this->tmpBin.'/claude');
        config()->set('atlas.ai.providers.claude_cli.args', [
            '-p',
            '--output-format',
            'stream-json',
            '--verbose',
            '--no-session-persistence',
            '--allowedTools',
            'Read',
        ]);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('R', 32)));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpBin);
        $this->rmrf($this->tmpReceipts);
        parent::tearDown();
    }

    public function test_readiness_json_passes_when_desktop_runtime_is_configured(): void
    {
        $exit = Artisan::call('atlas:dev:readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.readiness.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertContains('http.routes', array_column($payload['checks'], 'id'));
        $this->assertContains('worker.runtime', array_column($payload['checks'], 'id'));
        $this->assertContains('provider.runtime', array_column($payload['checks'], 'id'));
    }

    public function test_readiness_provider_safe_redacts_local_paths(): void
    {
        $exit = Artisan::call('atlas:dev:readiness', [
            '--json' => true,
            '--strict' => true,
            '--provider-safe' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['strict']);
        $this->assertTrue($payload['provider_safe']);
        $this->assertStringNotContainsString($this->tmpBin, $output);
        $this->assertStringNotContainsString($this->tmpReceipts, $output);

        $receiptsCheck = collect($payload['checks'])->firstWhere('id', 'storage.receipts_path');
        $providerCheck = collect($payload['checks'])->firstWhere('id', 'provider.runtime');

        $this->assertArrayHasKey('path_label', $receiptsCheck['details']);
        $this->assertArrayNotHasKey('path', $receiptsCheck['details']);
        $this->assertArrayHasKey('binary_label', $providerCheck['details']);
        $this->assertArrayNotHasKey('binary', $providerCheck['details']);
        $this->assertArrayHasKey('resolved_binary_label', $providerCheck['details']);
        $this->assertArrayNotHasKey('resolved_binary', $providerCheck['details']);
    }

    public function test_readiness_blocks_when_app_key_is_too_short(): void
    {
        config()->set('app.key', 'short');

        $exit = Artisan::call('atlas:dev:readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('security.app_key', array_column($payload['checks'], 'id'));
    }

    public function test_readiness_blocks_when_provider_tools_are_not_read_only(): void
    {
        config()->set('atlas.ai.providers.claude_cli.args', [
            '-p',
            '--output-format',
            'stream-json',
            '--verbose',
            '--no-session-persistence',
        ]);

        $exit = Artisan::call('atlas:dev:readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $providerCheck = collect($payload['checks'])->firstWhere('id', 'provider.runtime');
        $this->assertSame('failed', $providerCheck['status']);
        $this->assertContains('claude_cli read-only tools', $providerCheck['details']['missing']);
    }

    public function test_readiness_blocks_when_provider_binary_is_missing(): void
    {
        config()->set('atlas.ai.providers.claude_cli.binary', $this->tmpBin.'/missing-claude');

        $exit = Artisan::call('atlas:dev:readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $providerCheck = collect($payload['checks'])->firstWhere('id', 'provider.runtime');
        $this->assertSame('failed', $providerCheck['status']);
        $this->assertContains('executable claude_cli binary', $providerCheck['details']['missing']);
    }

    public function test_readiness_blocks_when_provider_binary_cannot_start_version_probe(): void
    {
        file_put_contents($this->tmpBin.'/claude', "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo broken >&2; exit 42; fi\nexit 0\n");
        chmod($this->tmpBin.'/claude', 0o755);

        $exit = Artisan::call('atlas:dev:readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $providerCheck = collect($payload['checks'])->firstWhere('id', 'provider.runtime');
        $this->assertSame('failed', $providerCheck['status']);
        $this->assertContains('claude_cli version command', $providerCheck['details']['missing']);
        $this->assertSame(42, $providerCheck['details']['version_probe']['exit_code']);
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
