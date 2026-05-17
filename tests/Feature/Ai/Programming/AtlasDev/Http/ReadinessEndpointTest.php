<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

final class ReadinessEndpointTest extends AtlasDevHttpTestCase
{
    private string $tmpBin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBin = sys_get_temp_dir().'/atlas-dev-readiness-http-bin-'.bin2hex(random_bytes(4));
        mkdir($this->tmpBin, 0o755, true);
        file_put_contents($this->tmpBin.'/claude', "#!/bin/sh\nexit 0\n");
        chmod($this->tmpBin.'/claude', 0o755);

        config()->set('atlas.ai.providers.claude_cli.binary', $this->tmpBin.'/claude');
        config()->set('atlas.ai.providers.claude_cli.args', ['-p', '--allowedTools', 'Read']);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpBin);
        parent::tearDown();
    }

    public function test_readiness_endpoint_reports_blockers_provider_safe_without_absolute_paths(): void
    {
        config()->set('atlas_dev.efficient.run_enabled', false);
        config()->set('atlas_dev.efficient.desktop_enabled', false);

        $response = $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/readiness?strict=true')
            ->assertStatus(200)
            ->assertJsonPath('data.schema_version', 'atlas.dev.readiness.v1')
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.provider_safe', true);

        $checks = $response->json('data.checks');
        $this->assertIsArray($checks);
        $this->assertContains('config.run_enabled', array_column($checks, 'id'));
        $this->assertContains('config.desktop_enabled', array_column($checks, 'id'));
        $this->assertContains('provider.runtime', array_column($checks, 'id'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($this->tmpBin, $body);
        $this->assertStringNotContainsString($this->tmpStorage, $body);
        $this->assertStringNotContainsString('/Users/', $body);
        $this->assertStringNotContainsString('/private/var/', $body);
        $this->assertStringNotContainsString('/var/folders/', $body);
    }

    public function test_readiness_endpoint_passes_when_desktop_runtime_flags_are_enabled(): void
    {
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);

        $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/readiness?strict=true')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'passed')
            ->assertJsonPath('data.summary.failed', 0);
    }
}
