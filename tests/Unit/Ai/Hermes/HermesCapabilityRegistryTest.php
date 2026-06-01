<?php

namespace Tests\Unit\Ai\Hermes;

use App\Models\HermesCapabilityCandidate;
use App\Models\HermesCapabilityManifest;
use App\Services\Ai\Hermes\HermesCapabilityRegistry;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HermesCapabilityRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_02_000100_create_hermes_capability_manifests_table.php'))->up();
        (require database_path('migrations/2026_06_02_000200_create_hermes_capability_candidates_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hermes_capability_candidates');
        Schema::dropIfExists('hermes_capability_manifests');

        parent::tearDown();
    }

    public function test_diff_detects_added_toolset_between_v1_and_v2(): void
    {
        $diff = $this->registry()->diff(
            $this->manifest(1, [$this->flagEntry('checkpoints')]),
            $this->manifest(2, [$this->flagEntry('checkpoints'), $this->toolsetEntry('browser')]),
        );

        $this->assertCount(1, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(0, $diff['changed']);
        $this->assertSame('toolset:browser', data_get($diff, 'added.0.id'));
        $this->assertSame('toolset', data_get($diff, 'added.0.capability_class'));
    }

    public function test_diff_detects_removed_and_changed_entries(): void
    {
        $previous = $this->manifest(1, [
            $this->toolsetEntry('browser'),
            $this->mcpServerEntry('github', ['transport' => 'stdio']),
        ]);

        // browser detail changed; github mcp_server removed.
        $current = $this->manifest(2, [
            $this->toolsetEntry('browser', ['description' => 'changed surface']),
        ]);

        $diff = $this->registry()->diff($previous, $current);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(1, $diff['removed']);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('mcp_server:github', data_get($diff, 'removed.0.id'));
        $this->assertSame('toolset:browser', data_get($diff, 'changed.0.id'));
    }

    public function test_diff_detects_changed_when_supported_flips(): void
    {
        $diff = $this->registry()->diff(
            $this->manifest(1, [$this->toolsetEntry('browser', [], false)]),
            $this->manifest(2, [$this->toolsetEntry('browser', [], true)]),
        );

        $this->assertCount(1, $diff['changed']);
        $this->assertSame('toolset:browser', data_get($diff, 'changed.0.id'));
    }

    public function test_record_persists_manifest_row_and_quarantined_candidate_per_added(): void
    {
        $first = $this->registry()->record(
            $this->manifest(99, [$this->flagEntry('checkpoints')], 'manifest_hash_v1'),
        );

        $this->assertSame('persisted_for_review', data_get($first, 'status'));
        $this->assertSame(1, data_get($first, 'manifest_version'));
        $this->assertSame(1, data_get($first, 'added_count'));
        $this->assertSame(1, data_get($first, 'persisted_count'));
        $this->assertSame(1, HermesCapabilityManifest::query()->count());

        $manifestRow = HermesCapabilityManifest::query()->first();
        $this->assertSame(1, $manifestRow->manifest_version);
        $this->assertSame('manifest_hash_v1', $manifestRow->manifest_hash);

        $candidate = HermesCapabilityCandidate::query()->first();
        $this->assertNotNull($candidate);
        $this->assertSame('flag', $candidate->capability_class);
        $this->assertSame('checkpoints', $candidate->capability_key);
        $this->assertFalse($candidate->enabled);
        $this->assertTrue($candidate->review_required);
        $this->assertSame('quarantined_for_atlas_capability_review', $candidate->gate_status);
        $this->assertSame('quarantined_for_atlas_capability_review', data_get($candidate->payload_json, 'gate_status'));
        $this->assertFalse((bool) data_get($candidate->payload_json, 'enabled_now'));
        $this->assertSame('hermes_capability_manifest', data_get($candidate->evidence_refs_json, '0.kind'));
        $this->assertSame('manifest_hash_v1', data_get($candidate->evidence_refs_json, '0.manifest_hash'));
    }

    public function test_record_increments_manifest_version_across_recordings(): void
    {
        $this->registry()->record($this->manifest(1, [$this->flagEntry('checkpoints')], 'hash_a'));
        $second = $this->registry()->record(
            $this->manifest(1, [$this->flagEntry('checkpoints'), $this->toolsetEntry('browser')], 'hash_b'),
        );

        $this->assertSame(2, data_get($second, 'manifest_version'));
        $this->assertSame('hash_a', data_get($second, 'previous_manifest_hash'));
        $this->assertSame(1, data_get($second, 'added_count'));
        $this->assertSame(2, HermesCapabilityManifest::query()->count());
    }

    public function test_registry_never_enables_high_risk_mcp_server_candidate(): void
    {
        $receipt = $this->registry()->record(
            $this->manifest(1, [$this->mcpServerEntry('github', ['transport' => 'stdio'])], 'hash_mcp'),
        );

        $this->assertSame('persisted_for_review', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'enabled_now'));

        $candidate = HermesCapabilityCandidate::query()->where('capability_class', 'mcp_server')->first();
        $this->assertNotNull($candidate);
        $this->assertSame('high', $candidate->risk_level);
        $this->assertSame(false, $candidate->enabled, 'Registry must never set enabled=true.');
        $this->assertFalse($candidate->enabled);
        $this->assertTrue((bool) data_get($candidate->gate_json, 'always_quarantine'));
        $this->assertTrue((bool) data_get($candidate->gate_json, 'danger_requires_operator_authority'));

        // No candidate, of any class, is ever enabled.
        $this->assertSame(0, HermesCapabilityCandidate::query()->where('enabled', true)->count());
    }

    public function test_recording_the_same_manifest_twice_yields_no_new_candidates(): void
    {
        $manifest = $this->manifest(1, [$this->toolsetEntry('browser')], 'hash_same');

        $this->registry()->record($manifest);
        $countAfterFirst = HermesCapabilityCandidate::query()->count();

        $second = $this->registry()->record($manifest);

        $this->assertContains(data_get($second, 'status'), ['no_change', 'deduplicated']);
        $this->assertSame(0, data_get($second, 'persisted_count'));
        $this->assertSame($countAfterFirst, HermesCapabilityCandidate::query()->count());
        $this->assertSame(1, HermesCapabilityCandidate::query()->count());
        // Content-addressed manifest_hash: the identical probed surface does not
        // create a duplicate manifest row or bump the version.
        $this->assertSame(1, HermesCapabilityManifest::query()->count());
    }

    public function test_persist_candidates_dedups_identical_capability_by_hash(): void
    {
        $diff = $this->registry()->diff(
            $this->manifest(1, []),
            $this->manifest(2, [$this->toolsetEntry('browser')]),
        );

        $manifest = $this->manifest(2, [$this->toolsetEntry('browser')], 'hash_dd');

        $first = $this->registry()->persistCandidates($diff, $manifest);
        $second = $this->registry()->persistCandidates($diff, $manifest);

        $this->assertSame(1, $first['persisted_count']);
        $this->assertSame(0, $second['persisted_count']);
        $this->assertSame(1, $second['duplicate_count']);
        $this->assertSame(1, HermesCapabilityCandidate::query()->count());
    }

    public function test_latest_manifest_returns_most_recent_manifest_json(): void
    {
        $this->registry()->record($this->manifest(1, [$this->flagEntry('checkpoints')], 'hash_old'));
        $this->registry()->record($this->manifest(1, [$this->flagEntry('checkpoints'), $this->toolsetEntry('browser')], 'hash_new'));

        $latest = $this->registry()->latestManifest();

        $this->assertIsArray($latest);
        $this->assertSame('hash_new', data_get($latest, 'manifest_hash'));
        $this->assertSame(2, data_get($latest, 'manifest_version'));
    }

    public function test_receipt_is_sealed_with_schema_version_and_receipt_hash(): void
    {
        $receipt = $this->registry()->record(
            $this->manifest(1, [$this->toolsetEntry('browser')], 'hash_seal'),
        );

        $this->assertSame('atlas.hermes.capability_registry_receipt.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('hermes_capability_registry', data_get($receipt, 'adapter'));
        $this->assertSame('atlas', data_get($receipt, 'capability_authority'));
        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));

        $expected = hash('sha256', json_encode(
            collect($receipt)->except('receipt_hash')->all(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($expected, data_get($receipt, 'receipt_hash'));
    }

    public function test_returns_registry_unavailable_when_tables_missing(): void
    {
        Schema::dropIfExists('hermes_capability_candidates');
        Schema::dropIfExists('hermes_capability_manifests');

        $receipt = $this->registry()->record(
            $this->manifest(1, [$this->toolsetEntry('browser')], 'hash_missing'),
        );

        $this->assertSame('capability_registry_unavailable', data_get($receipt, 'status'));
        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));
        $this->assertFalse((bool) data_get($receipt, 'enabled_now'));
    }

    private function registry(): HermesCapabilityRegistry
    {
        return app(HermesCapabilityRegistry::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function manifest(int $version, array $entries, ?string $manifestHash = null): array
    {
        return [
            'schema_version' => 'atlas.hermes.capability_manifest.v1',
            'manifest_version' => $version,
            'hermes_version' => '0.15.1',
            'probed_at' => '2026-06-01T00:00:00+00:00',
            'probe_status' => 'ok',
            'binary_present' => true,
            'section_status' => [
                'chat' => 'ok',
                'subcommands' => 'ok',
                'toolsets' => 'ok',
                'mcp' => 'ok',
                'skills' => 'ok',
                'bundles' => 'ok',
                'hooks' => 'ok',
                'delegation' => 'ok',
                'providers' => 'ok',
                'config_yaml' => 'ok',
            ],
            'entries' => $entries,
            'manifest_hash' => $manifestHash ?? 'manifest_hash_'.$version,
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function toolsetEntry(string $key, array $detail = [], bool $supported = true): array
    {
        return [
            'id' => 'toolset:'.$key,
            'capability_class' => 'toolset',
            'capability_key' => $key,
            'hermes_token' => $key,
            'supported' => $supported,
            'requires_config' => false,
            'detail' => $detail,
            'source' => 'hermes chat --help',
            'first_seen' => '2026-06-01T00:00:00+00:00',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flagEntry(string $key): array
    {
        return [
            'id' => 'flag:'.$key,
            'capability_class' => 'flag',
            'capability_key' => $key,
            'hermes_token' => '--'.$key,
            'supported' => true,
            'requires_config' => false,
            'detail' => [],
            'source' => 'hermes chat --help',
            'first_seen' => '2026-06-01T00:00:00+00:00',
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function mcpServerEntry(string $key, array $detail = []): array
    {
        return [
            'id' => 'mcp_server:'.$key,
            'capability_class' => 'mcp_server',
            'capability_key' => $key,
            'hermes_token' => null,
            'supported' => true,
            'requires_config' => true,
            'detail' => $detail,
            'source' => 'hermes mcp list',
            'first_seen' => '2026-06-01T00:00:00+00:00',
        ];
    }
}
