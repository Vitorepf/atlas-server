<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

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
        // The fast-path routing now targets the loop-native provider (hermes,
        // a WORKSPACE-MUTATING provider) — the old claude stub was never
        // invoked and every test run made a live ~60s hermes call. The stub
        // edits the file in place, exactly like the real hermes lane.
        $binary = $this->fakeBinDir.'/hermes';
        file_put_contents($binary, <<<'SH'
#!/usr/bin/env bash
if [[ "${1:-}" == "--version" ]]; then
  echo "hermes 1.0 (stub)"
  exit 0
fi

cat >/dev/null
if [[ -f src/SmokeSubject.php ]]; then
  /usr/bin/sed -i '' "s/helo atlas/hello atlas/" src/SmokeSubject.php
fi
echo "fixed greeting typo in src/SmokeSubject.php"
SH);
        chmod($binary, 0o755);
        config()->set('atlas.ai.providers.hermes_cli.binary', $binary);
        // The live .env pins the Dev lane to the ACP transport (persistent
        // session against the real hermes agent) which never touches the
        // binary — force the CLI transport so the stub is what runs.
        config()->set('atlas_dev.hermes_execution_transport', 'cli');
        // run_dispatch_mode=process spawns a FRESH artisan worker that re-boots
        // config from the environment — config()->set() is invisible there. A
        // real env var crosses the proc_open boundary and Dotenv (immutable)
        // never overwrites it, so the worker uses the stub too.
        putenv('ATLAS_AI_HERMES_BIN='.$binary);
        $_ENV['ATLAS_AI_HERMES_BIN'] = $binary;
        $_SERVER['ATLAS_AI_HERMES_BIN'] = $binary;
        // The live .env pins the Dev lane to the ACP transport (a persistent
        // session against the real hermes agent), which never touches the
        // binary. Force the CLI transport so the stub is what runs.
        putenv('ATLAS_DEV_HERMES_EXECUTION_TRANSPORT=cli');
        $_ENV['ATLAS_DEV_HERMES_EXECUTION_TRANSPORT'] = 'cli';
        $_SERVER['ATLAS_DEV_HERMES_EXECUTION_TRANSPORT'] = 'cli';
    }

    protected function tearDown(): void
    {
        putenv('ATLAS_AI_HERMES_BIN');
        putenv('ATLAS_DEV_HERMES_EXECUTION_TRANSPORT');
        unset(
            $_ENV['ATLAS_AI_HERMES_BIN'],
            $_SERVER['ATLAS_AI_HERMES_BIN'],
            $_ENV['ATLAS_DEV_HERMES_EXECUTION_TRANSPORT'],
            $_SERVER['ATLAS_DEV_HERMES_EXECUTION_TRANSPORT'],
        );
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
        $this->assertSame('hermes_cli', $payload['run']['provider']);
        $this->assertSame('hermes_cli_default', $payload['run']['model_family']);
        $this->assertSame(1, $payload['run']['provider_calls']);
        // hermes is a workspace-mutating provider: it edits in place, apply is skipped.
        $this->assertSame('skipped', $payload['run']['patch_apply_status']);
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
        $this->assertSame('hermes_cli', $evidence['provider']);
        $this->assertSame('hermes_cli_default', $evidence['model_family']);
        $this->assertSame('skipped', $evidence['patch_apply_status']);
        $this->assertSame([], $evidence['honesty_flags']);
        $this->assertSame(true, $evidence['workspace_assertion_passed']);
    }
}
