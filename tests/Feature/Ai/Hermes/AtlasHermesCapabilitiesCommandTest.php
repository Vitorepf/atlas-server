<?php

namespace Tests\Feature\Ai\Hermes;

use App\Models\HermesCapabilityCandidate;
use App\Models\HermesCapabilityManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature coverage for the read-only Hermes Capability Registry operator surface.
 *
 * The command must: emit the pinned atlas.hermes.capability_manifest.v1 on probe,
 * persist a manifest + quarantined (never enabled) CapabilityCandidates on --write,
 * list those candidates, and return FAILURE only when the Hermes binary is offline
 * so health pipelines can gate. It must never enable a capability.
 */
class AtlasHermesCapabilitiesCommandTest extends TestCase
{
    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The registry needs the capability tables; create them from the canonical
        // migrations (no factories, no RefreshDatabase — match repo convention).
        (require database_path('migrations/2026_06_02_000100_create_hermes_capability_manifests_table.php'))->up();
        (require database_path('migrations/2026_06_02_000200_create_hermes_capability_candidates_table.php'))->up();

        $this->binDir = sys_get_temp_dir().'/atlas-hermes-capabilities-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->binDir);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hermes_capability_candidates');
        Schema::dropIfExists('hermes_capability_manifests');
        File::deleteDirectory($this->binDir);

        parent::tearDown();
    }

    public function test_probe_json_emits_capability_manifest_v1(): void
    {
        config(['atlas.ai.providers.hermes_cli.binary' => $this->fakeHermesBinary()]);

        $exitCode = Artisan::call('atlas:hermes:capabilities', ['action' => 'probe', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame('probe', $payload['action']);
        $this->assertFalse($payload['written']);

        $manifest = $payload['manifest'];
        $this->assertIsArray($manifest);
        $this->assertSame('atlas.hermes.capability_manifest.v1', $manifest['schema_version']);
        $this->assertTrue((bool) $manifest['binary_present']);
        $this->assertNotSame('binary_offline', $manifest['probe_status']);
        $this->assertArrayHasKey('manifest_hash', $manifest);
        $this->assertArrayHasKey('section_status', $manifest);
        $this->assertNotEmpty($manifest['entries']);

        // The probe surfaced the toolsets/flags from the stub help text.
        $classes = collect($manifest['entries'])->pluck('capability_class')->unique()->all();
        $this->assertContains('toolset', $classes);
        $this->assertContains('flag', $classes);
    }

    public function test_probe_write_persists_manifest_and_only_quarantined_candidates(): void
    {
        config(['atlas.ai.providers.hermes_cli.binary' => $this->fakeHermesBinary()]);

        $exitCode = Artisan::call('atlas:hermes:capabilities', ['action' => 'probe', '--write' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['written']);
        $this->assertIsArray($payload['registry_receipt']);
        $this->assertSame('atlas.hermes.capability_registry_receipt.v1', $payload['registry_receipt']['schema_version']);
        $this->assertSame('atlas', $payload['registry_receipt']['capability_authority']);
        $this->assertFalse((bool) $payload['registry_receipt']['enabled_now']);

        $this->assertGreaterThan(0, HermesCapabilityManifest::count());
        $this->assertGreaterThan(0, HermesCapabilityCandidate::count());

        // ATLS sovereignty: NOTHING the registry records is ever enabled automatically.
        $this->assertSame(0, HermesCapabilityCandidate::query()->where('enabled', true)->count());
        foreach (HermesCapabilityCandidate::all() as $candidate) {
            $this->assertFalse((bool) $candidate->enabled, 'capability candidate must never be enabled by the registry');
            $this->assertSame('quarantined_for_atlas_capability_review', $candidate->gate_status);
        }
    }

    public function test_candidates_json_lists_quarantined_rows(): void
    {
        config(['atlas.ai.providers.hermes_cli.binary' => $this->fakeHermesBinary()]);

        // Seed candidates via the governed --write path first.
        Artisan::call('atlas:hermes:capabilities', ['action' => 'probe', '--write' => true]);
        $this->assertGreaterThan(0, HermesCapabilityCandidate::count());

        $exitCode = Artisan::call('atlas:hermes:capabilities', ['action' => 'candidates', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame('candidates', $payload['action']);
        $this->assertTrue($payload['available']);
        $this->assertSame('atlas', $payload['capability_authority']);
        $this->assertFalse((bool) $payload['enabled_now']);
        $this->assertSame(0, $payload['enabled_count']);
        $this->assertSame(HermesCapabilityCandidate::count(), $payload['count']);
        $this->assertNotEmpty($payload['candidates']);
        $this->assertFalse((bool) $payload['candidates'][0]['enabled']);
    }

    public function test_diff_json_reports_no_drift_against_recorded_manifest(): void
    {
        config(['atlas.ai.providers.hermes_cli.binary' => $this->fakeHermesBinary()]);

        // Record a manifest, then diff the same stable surface against it.
        Artisan::call('atlas:hermes:capabilities', ['action' => 'probe', '--write' => true]);

        $exitCode = Artisan::call('atlas:hermes:capabilities', ['action' => 'diff', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame('diff', $payload['action']);
        $this->assertTrue($payload['has_previous_manifest']);
        $this->assertSame(0, $payload['added_count']);
        $this->assertSame(0, $payload['removed_count']);
        $this->assertSame(0, $payload['changed_count']);
    }

    public function test_probe_returns_failure_when_binary_offline(): void
    {
        config(['atlas.ai.providers.hermes_cli.binary' => '/nonexistent/hermes-binary-xyz']);

        $exitCode = Artisan::call('atlas:hermes:capabilities', ['action' => 'probe', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame('binary_offline', $payload['manifest']['probe_status']);
        $this->assertFalse((bool) $payload['manifest']['binary_present']);

        // Default-safe: offline never persists anything.
        $this->assertSame(0, HermesCapabilityManifest::count());
        $this->assertSame(0, HermesCapabilityCandidate::count());
    }

    public function test_candidates_reports_when_tables_not_migrated(): void
    {
        Schema::dropIfExists('hermes_capability_candidates');

        $exitCode = Artisan::call('atlas:hermes:capabilities', ['action' => 'candidates', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['available']);
        $this->assertSame('capability tables not migrated', $payload['reason']);
        $this->assertSame([], $payload['candidates']);
    }

    /**
     * A read-only Hermes stub shaped like the v0.15.1 introspection surface the
     * probe parses: `--version`, `--help` (argparse choices token), `chat --help`
     * (toolsets enum + flags), and best-effort `<sub> list` / `tools`. It calls no
     * model and mutates nothing.
     */
    private function fakeHermesBinary(): string
    {
        $path = $this->binDir.'/hermes';
        File::put($path, <<<'SH'
#!/usr/bin/env bash
if [ "$1" = "--version" ]; then
  printf 'Hermes Agent v0.15.1 (2026.5.29)'
  exit 0
fi
if [ "$1" = "--help" ]; then
  cat <<'HELP'
Usage: hermes [-h] {chat,mcp,skills,bundles,hooks,tools,config,model,status} ...
HELP
  exit 0
fi
if [ "$1" = "chat" ] && [ "$2" = "--help" ]; then
  cat <<'HELP'
Usage: hermes chat [options]
  -q, --query QUERY
  -m, --model MODEL
  -t, --toolsets {shell,filesystem,browser,delegation}
  -s, --skills SKILLS
  --provider {openrouter,anthropic,openai}
  --checkpoints
  --max-turns N
  --ignore-rules
HELP
  exit 0
fi
if [ "$1" = "mcp" ] && [ "$2" = "list" ]; then
  printf '%s\n' 'github' 'filesystem'
  exit 0
fi
if [ "$1" = "skills" ] && [ "$2" = "list" ]; then
  printf '%s\n' 'pdf'
  exit 0
fi
if [ "$1" = "tools" ]; then
  printf '%s\n' 'shell' 'filesystem'
  exit 0
fi
exit 1
SH);
        chmod($path, 0755);

        return $path;
    }
}
