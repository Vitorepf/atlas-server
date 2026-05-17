<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\SymfonyClaudeCliGateway;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Ai\Programming\AtlasDev\Http\AtlasDevHttpTestCase;

final class AtlasDevDesktopRealSmokeCommandTest extends AtlasDevHttpTestCase
{
    private string $fakeBinDir;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');

        $this->fakeBinDir = sys_get_temp_dir().'/atlas-dev-real-smoke-bin-'.bin2hex(random_bytes(4));
        mkdir($this->fakeBinDir, 0o755, true);
        $binary = $this->fakeBinDir.'/claude';
        file_put_contents($binary, <<<'SH'
#!/usr/bin/env bash
if [[ "${1:-}" == "--version" ]]; then
  echo "2.1.143 (Claude Code)"
  exit 0
fi

cat >/dev/null
cat <<'DIFF'
```diff
--- src/SmokeSubject.php
+++ src/SmokeSubject.php
@@ -5,7 +5,7 @@ final class SmokeSubject
     public function greeting(): string
     {
-        return 'helo atlas';
+        return 'hello atlas';
     }
 }
```
DIFF
SH);
        chmod($binary, 0o755);

        config()->set('atlas.ai.providers.claude_cli.binary', $binary);
        config()->set('atlas.ai.providers.claude_cli.args', [
            '-p',
            '--output-format',
            'stream-json',
            '--verbose',
            '--no-session-persistence',
            '--allowedTools',
            'Read',
        ]);

        $this->app->forgetInstance(ClaudeCliGateway::class);
        $this->app->singleton(ClaudeCliGateway::class, function ($app): SymfonyClaudeCliGateway {
            return new SymfonyClaudeCliGateway($app['config']);
        });
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->fakeBinDir);

        parent::tearDown();
    }

    public function test_real_smoke_requires_explicit_yes_before_provider_call(): void
    {
        $exit = Artisan::call('atlas:dev:desktop:real-smoke', ['--json' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires --yes', Artisan::output());
    }

    public function test_real_smoke_passes_through_confirmation_token_compact_hash_and_receipt(): void
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:real-smoke', [
            '--yes' => true,
            '--json' => true,
        ], $output);

        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);
        $this->assertSame(0, $exit, $rawOutput);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.desktop_real_smoke.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(true, $payload['external_provider_call']);
        $this->assertSame('atlas_desktop_ai', $payload['surface_id']);
        $this->assertSame('atlas_dev_fast_path', $payload['routing']['kind']);
        $this->assertSame(true, $payload['operator_confirmation']['token_issued']);
        $this->assertSame(true, $payload['operator_confirmation']['token_consumed']);
        $this->assertSame(true, $payload['operator_confirmation']['compact_sdd_hash_pinned']);
        $this->assertSame('passed', $payload['run']['completion_state']);
        $this->assertSame('passed', $payload['run']['scope_guard_status']);
        $this->assertSame('passed', $payload['run']['verification_status']);
        $this->assertSame('claude_cli', $payload['run']['provider']);
        $this->assertSame('sonnet', $payload['run']['model_family']);
        $this->assertSame(1, $payload['run']['provider_calls']);
        $this->assertSame('applied', $payload['run']['patch_apply_status']);
        $this->assertSame(true, $payload['receipt']['present']);
        $this->assertSame([], $payload['receipt']['honesty_flags']);
        $this->assertSame(true, $payload['workspace_assertion']['passed']);
        $this->assertSame('atlas.dev.desktop_acceptance_evidence.v1', $payload['acceptance_evidence']['schema_version']);
        $this->assertSame('passed', $payload['acceptance_evidence']['status']);
        $this->assertSame('desktop_acceptance/latest.json', $payload['acceptance_evidence']['latest_ref']);

        $latest = $this->tmpStorage.'/desktop_acceptance/latest.json';
        $this->assertFileExists($latest);
        $evidence = json_decode((string) file_get_contents($latest), true);
        $this->assertIsArray($evidence);
        $this->assertSame('atlas.dev.desktop_acceptance_evidence.v1', $evidence['schema_version']);
        $this->assertSame('passed', $evidence['status']);
        $this->assertSame($payload['run_id'], $evidence['run_id']);
        $this->assertSame('passed', $evidence['completion_state']);
        $this->assertSame('claude_cli', $evidence['provider']);
        $this->assertSame('sonnet', $evidence['model_family']);
        $this->assertSame('applied', $evidence['patch_apply_status']);
        $this->assertSame([], $evidence['honesty_flags']);
        $this->assertSame(true, $evidence['workspace_assertion_passed']);
    }
}
