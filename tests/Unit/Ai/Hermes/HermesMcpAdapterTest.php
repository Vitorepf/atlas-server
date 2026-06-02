<?php

namespace Tests\Unit\Ai\Hermes;

use App\Models\AiJob;
use App\Models\HermesMcpCapabilityCandidate;
use App\Services\Ai\Hermes\HermesMcpAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit coverage for the Governed MCP adapter.
 *
 * Mirrors HermesGatewayAdapterTest's no-secret-leak discipline: the adapter may
 * hand Hermes a managed config containing REAL secrets, but the sealed receipt
 * must only ever carry sha256 digests. Atlas — not Hermes — is the canonical
 * tool authority; absence from the allowlist OR the manifest is fail-closed.
 */
class HermesMcpAdapterTest extends TestCase
{
    private const SECRET = 'sk-MCPSECRET0123456789';

    private const RAW_ENV_VALUE = 'super-secret-token-value';

    private const RAW_HEADER_VALUE = 'Bearer raw-header-credential-xyz';

    /** @var array<int,string> */
    private array $writtenPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_02_000300_create_hermes_mcp_capability_candidates_table.php'))->up();

        config(['atlas.ai.providers.hermes_cli.mcp.allowed_servers' => ['github', 'filesystem']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenPaths as $path) {
            if (is_string($path) && File::exists($path)) {
                File::delete($path);
            }
        }
        $this->writtenPaths = [];

        Schema::dropIfExists('hermes_mcp_capability_candidates');

        parent::tearDown();
    }

    public function test_allowlisted_present_enabled_server_is_allowed_and_provisioned(): void
    {
        $result = $this->resolve(
            [$this->githubServer()],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('github', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $receipt = $result['receipt'];

        $this->assertSame('provisioned_for_invocation', data_get($receipt, 'status'));
        $this->assertTrue((bool) data_get($receipt, 'mcp_enabled_now'));
        $this->assertSame('allow', data_get($receipt, 'servers.0.verdict'));
        $this->assertSame(1, data_get($receipt, 'servers_allowed'));
        $this->assertSame('atlas', data_get($receipt, 'canonical_tool_authority'));
        $this->assertSame('managed_config_via_HERMES_CONFIG', data_get($receipt, 'config_delivery'));

        $this->assertNotNull($result['managed_config_path']);
        $this->assertNotNull(data_get($receipt, 'managed_config_path_hash'));
        $this->assertSame(
            hash('sha256', (string) $result['managed_config_path']),
            data_get($receipt, 'managed_config_path_hash'),
        );

        // The managed config (outside the receipt) MUST carry the real secret so Hermes works.
        $body = File::get((string) $result['managed_config_path']);
        $this->assertStringContainsString(self::RAW_ENV_VALUE, $body);
    }

    public function test_server_not_in_allowlist_is_blocked_and_quarantined_not_provisioned(): void
    {
        $result = $this->resolve(
            [$this->server('rogue', enabled: true)],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('rogue', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $receipt = $result['receipt'];

        $this->assertSame('all_blocked_not_allowlisted', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'mcp_enabled_now'));
        $this->assertSame('block', data_get($receipt, 'servers.0.verdict'));
        $this->assertSame('not_in_atlas_allowlist', data_get($receipt, 'servers.0.reason'));
        $this->assertNull($result['managed_config_path']);
        $this->assertNull(data_get($receipt, 'managed_config_path_hash'));

        // Candidate recorded, quarantined, never promotable.
        $this->assertSame(1, HermesMcpCapabilityCandidate::query()->count());
        $candidateId = data_get($receipt, 'servers.0.capability_candidate_id');
        $this->assertNotNull($candidateId);
        $this->assertContains($candidateId, data_get($receipt, 'capability_candidates_recorded'));

        $row = HermesMcpCapabilityCandidate::query()->first();
        $this->assertSame('rogue', $row->server_name);
        $this->assertFalse($row->promotion_allowed);
        $this->assertSame('persisted_for_review', $row->status);
    }

    public function test_policy_not_atlas_adapter_skips_all(): void
    {
        $result = $this->resolve(
            [$this->githubServer()],
            'atlas_canonical',
            $this->manifest([$this->manifestEntry('github', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $receipt = $result['receipt'];

        $this->assertSame('skipped_by_policy', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'mcp_enabled_now'));
        $this->assertSame('skip_by_policy', data_get($receipt, 'servers.0.verdict'));
        $this->assertSame('mcp_policy_not_atlas_adapter', data_get($receipt, 'servers.0.reason'));
        $this->assertNull($result['managed_config_path']);
        $this->assertSame(0, HermesMcpCapabilityCandidate::query()->count());
    }

    public function test_disabled_server_is_skipped(): void
    {
        $result = $this->resolve(
            [$this->githubServer(enabled: false)],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('github', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $receipt = $result['receipt'];

        $this->assertSame('skip_disabled', data_get($receipt, 'servers.0.verdict'));
        $this->assertSame(1, data_get($receipt, 'servers_skipped_disabled'));
        $this->assertFalse((bool) data_get($receipt, 'mcp_enabled_now'));
        $this->assertNull($result['managed_config_path']);
    }

    public function test_allowlisted_but_absent_from_manifest_is_fail_closed(): void
    {
        // github IS in the allowlist and the mission, but the manifest has no such server.
        $result = $this->resolve(
            [$this->githubServer()],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('filesystem', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $receipt = $result['receipt'];

        $this->assertSame('block', data_get($receipt, 'servers.0.verdict'));
        $this->assertSame('not_present_in_manifest', data_get($receipt, 'servers.0.reason'));
        $this->assertFalse((bool) data_get($receipt, 'servers.0.present_in_manifest'));
        $this->assertTrue((bool) data_get($receipt, 'servers.0.in_atlas_allowlist'));
        $this->assertSame('mcp_capability_unavailable', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'mcp_enabled_now'));
        $this->assertNull($result['managed_config_path']);
        // Absence from the manifest is fail-closed but NOT a quarantine candidate (allowlisted).
        $this->assertNull(data_get($receipt, 'servers.0.capability_candidate_id'));
    }

    public function test_tools_exclude_wins_over_include_in_provisioned_config(): void
    {
        $server = $this->githubServer();
        $server['tools'] = [
            'include' => ['repo_read', 'repo_write', 'issues'],
            'exclude' => ['repo_write'],
        ];

        $result = $this->resolve(
            [$server],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('github', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $receipt = $result['receipt'];
        $include = data_get($receipt, 'servers.0.tool_filters.include');
        $exclude = data_get($receipt, 'servers.0.tool_filters.exclude');

        $this->assertContains('repo_write', $exclude);
        $this->assertContains('repo_read', $include);

        // In the materialized managed config, the excluded tool must NOT survive in include.
        $config = json_decode(File::get((string) $result['managed_config_path']), true);
        $provisionedInclude = data_get($config, 'mcp_servers.github.tools.include', []);
        $provisionedExclude = data_get($config, 'mcp_servers.github.tools.exclude', []);
        $this->assertNotContains('repo_write', $provisionedInclude);
        $this->assertContains('repo_write', $provisionedExclude);
    }

    public function test_secrets_are_never_stored_raw_in_receipt(): void
    {
        $result = $this->resolve(
            [$this->githubServer()],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('github', true, true)]),
        );
        $this->trackPath($result['managed_config_path']);

        $json = json_encode($result['receipt']);

        $this->assertIsString($json);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::RAW_ENV_VALUE, $json);
        $this->assertStringNotContainsString(self::RAW_HEADER_VALUE, $json);
        $this->assertStringNotContainsString('client-secret-raw', $json);

        // But the hashes ARE present.
        $this->assertNotNull(data_get($result['receipt'], 'servers.0.command_hash'));
        $this->assertNotNull(data_get($result['receipt'], 'servers.0.env_keys_hash'));
        $this->assertNotNull(data_get($result['receipt'], 'servers.0.oauth_hash'));
    }

    public function test_quarantined_candidate_payload_never_contains_raw_secret(): void
    {
        $this->resolve(
            [$this->server('rogue', enabled: true)],
            'atlas_adapter',
            $this->manifest([$this->manifestEntry('rogue', true, true)]),
        );

        $row = HermesMcpCapabilityCandidate::query()->first();
        $json = json_encode($row->payload_json);

        $this->assertIsString($json);
        $this->assertStringNotContainsString(self::RAW_ENV_VALUE, $json);
        $this->assertStringNotContainsString(self::RAW_HEADER_VALUE, $json);
        $this->assertStringNotContainsString('client-secret-raw', $json);
        $this->assertSame('high', data_get($row->payload_json, 'risk_class'));
    }

    public function test_receipt_hash_is_deterministic_and_sealed(): void
    {
        $traceId = (string) Str::uuid();
        $manifest = $this->manifest([$this->manifestEntry('github', true, true)]);

        $first = $this->resolve([$this->githubServer()], 'atlas_adapter', $manifest, $traceId);
        $this->trackPath($first['managed_config_path']);
        $second = $this->resolve([$this->githubServer()], 'atlas_adapter', $manifest, $traceId);
        $this->trackPath($second['managed_config_path']);

        $this->assertSame('atlas.hermes.mcp_adapter_receipt.v1', data_get($first['receipt'], 'schema_version'));
        $this->assertSame('hermes_mcp_adapter', data_get($first['receipt'], 'adapter'));
        $this->assertNotEmpty(data_get($first['receipt'], 'receipt_hash'));
        $this->assertSame(
            data_get($first['receipt'], 'receipt_hash'),
            data_get($second['receipt'], 'receipt_hash'),
        );

        $expected = hash('sha256', json_encode(
            collect($first['receipt'])->except('receipt_hash')->all(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($expected, data_get($first['receipt'], 'receipt_hash'));
    }

    public function test_catalog_install_server_is_quarantined_not_enabled(): void
    {
        $server = $this->server('catalog-thing', enabled: true);
        $server['source'] = 'catalog_install';

        $result = $this->resolve(
            [$server],
            'atlas_adapter',
            // Not in the manifest yet (a fresh catalog install) and not allowlisted.
            $this->manifest([]),
        );
        $this->trackPath($result['managed_config_path']);

        $this->assertSame('block', data_get($result['receipt'], 'servers.0.verdict'));
        $this->assertFalse((bool) data_get($result['receipt'], 'mcp_enabled_now'));
        $this->assertNull($result['managed_config_path']);

        $row = HermesMcpCapabilityCandidate::query()->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->promotion_allowed);
        $this->assertSame('catalog_install', $row->source);
    }

    public function test_no_mcp_requested_yields_no_mcp_requested_status(): void
    {
        $result = $this->resolve([], 'atlas_adapter', $this->manifest([]));

        $this->assertSame('no_mcp_requested', data_get($result['receipt'], 'status'));
        $this->assertSame(0, data_get($result['receipt'], 'servers_requested'));
        $this->assertFalse((bool) data_get($result['receipt'], 'mcp_enabled_now'));
        $this->assertNull($result['managed_config_path']);
        $this->assertSame([], data_get($result['receipt'], 'servers'));
    }

    private function adapter(): HermesMcpAdapter
    {
        return app(HermesMcpAdapter::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $servers
     * @param  array<string,mixed>  $manifest
     * @return array{receipt:array<string,mixed>,managed_config_path:?string}
     */
    private function resolve(array $servers, string $mcpPolicy, array $manifest, ?string $traceId = null): array
    {
        $job = new AiJob([
            'trace_id' => $traceId ?? (string) Str::uuid(),
            'payload' => [
                'hermes' => [
                    'mcp' => ['servers' => $servers],
                ],
            ],
        ]);

        return $this->adapter()->resolve(
            $job,
            ['mission_id' => 'hermes_mission_test', 'mission_hash' => 'mission_hash_test'],
            ['provider_cli' => 'hermes_cli', 'command' => ['hermes', 'chat', '--quiet']],
            $mcpPolicy,
            $manifest,
        );
    }

    private function trackPath(?string $path): void
    {
        if (is_string($path)) {
            $this->writtenPaths[] = $path;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function githubServer(bool $enabled = true): array
    {
        return $this->server('github', $enabled);
    }

    /**
     * @return array<string,mixed>
     */
    private function server(string $name, bool $enabled): array
    {
        return [
            'name' => $name,
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-'.$name, '--key='.self::SECRET],
            'env' => [
                'GITHUB_TOKEN' => self::RAW_ENV_VALUE,
            ],
            'headers' => [
                'Authorization' => self::RAW_HEADER_VALUE,
            ],
            'oauth' => [
                'client_id' => 'client-123',
                'client_secret' => 'client-secret-raw',
            ],
            'enabled' => $enabled,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function manifest(array $entries): array
    {
        return [
            'schema_version' => 'atlas.hermes.capability_manifest.v1',
            'manifest_hash' => hash('sha256', 'mcp-manifest-test'),
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function manifestEntry(string $key, bool $supported, bool $enabled): array
    {
        return [
            'id' => 'mcp_server:'.$key,
            'capability_class' => 'mcp_server',
            'capability_key' => $key,
            'hermes_token' => 'mcp-'.$key,
            'supported' => $supported,
            'detail' => [
                'enabled' => $enabled,
                'transport' => 'stdio',
            ],
        ];
    }
}
