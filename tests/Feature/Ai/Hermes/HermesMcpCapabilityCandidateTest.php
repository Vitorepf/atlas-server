<?php

namespace Tests\Feature\Ai\Hermes;

use App\Models\AiJob;
use App\Models\HermesMcpCapabilityCandidate;
use App\Services\Ai\Hermes\HermesMcpCapabilityCandidateRecorder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature coverage for the quarantine recorder behind the Governed MCP adapter.
 *
 * A server Hermes wants but Atlas has not allowlisted (or one freshly installed
 * from a catalog) lands as a quarantined HermesMcpCapabilityCandidate — never
 * enabled, never promotable, deduped by content hash, and only ever holding
 * sha256 digests of the secret-bearing fields. The recorder must also fail
 * gracefully (null) when the table is absent.
 */
class HermesMcpCapabilityCandidateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_02_000300_create_hermes_mcp_capability_candidates_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hermes_mcp_capability_candidates');

        parent::tearDown();
    }

    public function test_records_quarantined_candidate_never_promotable(): void
    {
        $row = $this->recorder()->record(
            $this->descriptor(),
            $this->job(),
            $this->mission(),
            $this->invocation(),
        );

        $this->assertInstanceOf(HermesMcpCapabilityCandidate::class, $row);
        $this->assertSame('rogue', $row->server_name);
        $this->assertSame('persisted_for_review', $row->status);
        $this->assertSame('manifest_diff', $row->source);
        $this->assertSame('high', $row->risk_class);
        $this->assertFalse($row->promotion_allowed);
        $this->assertTrue($row->review_required);
        $this->assertNotNull($row->expires_at);

        $this->assertSame('quarantined_for_atlas_capability_review', data_get($row->payload_json, 'gate_status'));
        $this->assertFalse((bool) data_get($row->payload_json, 'enabled_now'));
        $this->assertFalse((bool) data_get($row->payload_json, 'promotion_allowed_now'));
        $this->assertTrue((bool) data_get($row->promotion_gate_json, 'always_quarantine'));
        $this->assertFalse((bool) data_get($row->promotion_gate_json, 'promotion_allowed_now'));

        $this->assertSame(1, HermesMcpCapabilityCandidate::query()->count());
    }

    public function test_dedupes_by_candidate_hash_on_second_record(): void
    {
        $descriptor = $this->descriptor();

        $first = $this->recorder()->record($descriptor, $this->job(), $this->mission(), $this->invocation());
        $second = $this->recorder()->record($descriptor, $this->job(), $this->mission(), $this->invocation());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, HermesMcpCapabilityCandidate::query()->count());
    }

    public function test_records_catalog_install_source(): void
    {
        $descriptor = $this->descriptor();
        $descriptor['source'] = 'catalog_install';

        $row = $this->recorder()->record($descriptor, $this->job(), $this->mission(), $this->invocation());

        $this->assertSame('catalog_install', $row->source);
        $this->assertFalse($row->promotion_allowed);
    }

    public function test_payload_only_carries_hashes_never_raw_secrets(): void
    {
        $row = $this->recorder()->record(
            $this->descriptor(),
            $this->job(),
            $this->mission(),
            $this->invocation(),
        );

        $json = json_encode($row->payload_json);

        $this->assertIsString($json);
        $this->assertStringNotContainsString('raw-env-secret-value', $json);
        $this->assertStringNotContainsString('raw-header-credential', $json);
        $this->assertStringNotContainsString('client-secret-raw', $json);

        $this->assertNotNull(data_get($row->payload_json, 'command_hash'));
        $this->assertNotNull(data_get($row->payload_json, 'env_keys_hash'));
        $this->assertNotNull(data_get($row->payload_json, 'headers_hash'));
        $this->assertNotNull(data_get($row->payload_json, 'oauth_hash'));
    }

    public function test_returns_null_when_table_absent(): void
    {
        Schema::dropIfExists('hermes_mcp_capability_candidates');

        $row = $this->recorder()->record(
            $this->descriptor(),
            $this->job(),
            $this->mission(),
            $this->invocation(),
        );

        $this->assertNull($row);
    }

    private function recorder(): HermesMcpCapabilityCandidateRecorder
    {
        return app(HermesMcpCapabilityCandidateRecorder::class);
    }

    private function job(): AiJob
    {
        return new AiJob([
            'trace_id' => (string) Str::uuid(),
            'payload' => ['workspace' => '/tmp/ws'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mission(): array
    {
        return [
            'mission_id' => 'hermes_mission_test',
            'mission_hash' => 'mission_hash_test',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function invocation(): array
    {
        return [
            'provider_cli' => 'hermes_cli',
            'command' => ['hermes', 'chat', '--quiet'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function descriptor(): array
    {
        return [
            'name' => 'rogue',
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@vendor/rogue-mcp'],
            'env' => [
                'ROGUE_TOKEN' => 'raw-env-secret-value',
            ],
            'headers' => [
                'Authorization' => 'raw-header-credential',
            ],
            'oauth' => [
                'client_id' => 'client-123',
                'client_secret' => 'client-secret-raw',
            ],
            'tools' => [
                'include' => ['do_thing'],
                'exclude' => ['danger_thing'],
            ],
            'source' => 'manifest_diff',
        ];
    }
}
