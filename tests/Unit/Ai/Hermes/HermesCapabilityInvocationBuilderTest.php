<?php

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\Hermes\HermesCapabilityInvocationBuilder;
use Tests\TestCase;

class HermesCapabilityInvocationBuilderTest extends TestCase
{
    private function builder(): HermesCapabilityInvocationBuilder
    {
        return app(HermesCapabilityInvocationBuilder::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function manifest(array $entries, int $manifestVersion = 7): array
    {
        return [
            'schema_version' => 'atlas.hermes.capability_manifest.v1',
            'manifest_version' => $manifestVersion,
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function toolsetEntry(string $key, array $overrides = []): array
    {
        return array_merge([
            'id' => 'toolset:'.$key,
            'capability_class' => 'toolset',
            'capability_key' => $key,
            'hermes_token' => $key,
            'supported' => true,
            'requires_config' => false,
            'detail' => [],
            'source' => 'hermes chat --help',
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function flagEntry(string $key, string $token, array $overrides = []): array
    {
        return array_merge([
            'id' => 'flag:'.$key,
            'capability_class' => 'flag',
            'capability_key' => $key,
            'hermes_token' => $token,
            'supported' => true,
            'requires_config' => false,
            'detail' => [],
            'source' => 'hermes chat --help',
        ], $overrides);
    }

    /**
     * @param  array<int,string>  $requestedIds
     * @return array<string,mixed>
     */
    private function mission(array $requestedIds): array
    {
        return [
            'schema_version' => 'atlas.hermes.mission_capabilities.v1',
            'requested_capability_ids' => $requestedIds,
        ];
    }

    public function test_supported_and_allowed_toolset_is_emitted_to_toolsets_csv(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser']),
            $this->manifest([$this->toolsetEntry('browser')]),
            ['enabled' => true, 'allow' => ['toolset:browser']],
            'write',
        );

        $this->assertContains('--toolsets', $result['args']);
        $index = array_search('--toolsets', $result['args'], true);
        $this->assertSame('browser', $result['args'][$index + 1]);

        $receipt = $result['receipt'];
        $this->assertSame('resolved', $receipt['status']);
        $this->assertSame(1, $receipt['resolved_count']);
        $this->assertSame(0, $receipt['dropped_count']);
        $this->assertTrue($receipt['any_capability_enabled_now']);
        $this->assertSame(['browser'], $receipt['emitted_toolsets']);
        $this->assertSame('toolset:browser', $receipt['resolved'][0]['id']);
        $this->assertSame('toolset', $receipt['resolved'][0]['kind']);
        $this->assertSame('browser', $receipt['resolved'][0]['emitted_token']);
        $this->assertSame('--toolsets', $receipt['resolved'][0]['emit_target']);
        $this->assertSame(7, $receipt['manifest_version']);
        $this->assertSame('atlas', $receipt['capability_authority']);
    }

    public function test_unknown_capability_is_dropped(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:ghost']),
            $this->manifest([$this->toolsetEntry('browser')]),
            ['enabled' => true, 'allow' => ['toolset:ghost']],
            'write',
        );

        $this->assertSame(['chat', '--quiet'], $result['args']);
        $this->assertSame('all_dropped', $result['receipt']['status']);
        $this->assertSame('toolset:ghost', $result['receipt']['dropped'][0]['id']);
        $this->assertSame('unknown_capability', $result['receipt']['dropped'][0]['reason']);
        $this->assertFalse($result['receipt']['any_capability_enabled_now']);
    }

    public function test_unsupported_capability_is_dropped(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser']),
            $this->manifest([$this->toolsetEntry('browser', ['supported' => false])]),
            ['enabled' => true, 'allow' => ['toolset:browser']],
            'write',
        );

        $this->assertSame(['chat', '--quiet'], $result['args']);
        $this->assertSame('unsupported_by_hermes_manifest', $result['receipt']['dropped'][0]['reason']);
    }

    public function test_capability_not_in_policy_allowlist_is_dropped(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser']),
            $this->manifest([$this->toolsetEntry('browser')]),
            ['enabled' => true, 'allow' => []],
            'write',
        );

        $this->assertSame('not_in_policy_allowlist', $result['receipt']['dropped'][0]['reason']);
        $this->assertSame([], $result['receipt']['emitted_toolsets']);
    }

    public function test_capability_blocked_by_permission_mode_is_dropped(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:shell']),
            $this->manifest([$this->toolsetEntry('shell')]),
            [
                'enabled' => true,
                'allow' => ['toolset:shell'],
                'allow_by_mode' => ['danger' => ['toolset:shell']],
            ],
            'read',
        );

        $this->assertSame('blocked_by_permission_mode', $result['receipt']['dropped'][0]['reason']);
        $this->assertSame([], $result['receipt']['emitted_toolsets']);
    }

    public function test_capability_requiring_config_yaml_not_present_is_dropped(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser']),
            $this->manifest([$this->toolsetEntry('browser', ['requires_config' => true])]),
            ['enabled' => true, 'allow' => ['toolset:browser']],
            'write',
        );

        $this->assertSame('requires_config_yaml_not_present', $result['receipt']['dropped'][0]['reason']);
        $this->assertSame([], $result['receipt']['emitted_toolsets']);
    }

    public function test_capability_requiring_config_is_resolved_when_confirmed(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser']),
            $this->manifest([$this->toolsetEntry('browser', ['requires_config' => true])]),
            [
                'enabled' => true,
                'allow' => ['toolset:browser'],
                'config_confirmed' => ['toolset:browser'],
            ],
            'write',
        );

        $this->assertSame('resolved', $result['receipt']['status']);
        $this->assertSame(['browser'], $result['receipt']['emitted_toolsets']);
    }

    public function test_checkpoints_flag_emits_once_in_danger_mode_and_is_dropped_in_read_mode(): void
    {
        $manifest = $this->manifest([$this->flagEntry('checkpoints', '--checkpoints')]);
        $policy = [
            'enabled' => true,
            'allow' => ['flag:checkpoints'],
            'allow_by_mode' => ['write' => ['flag:checkpoints'], 'danger' => ['flag:checkpoints']],
        ];

        $danger = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['flag:checkpoints']),
            $manifest,
            $policy,
            'danger',
        );

        $this->assertSame(1, count(array_keys($danger['args'], '--checkpoints', true)));
        $this->assertSame(['--checkpoints'], $danger['receipt']['emitted_flags']);
        $this->assertSame('flag', $danger['receipt']['resolved'][0]['emit_target']);
        $this->assertSame('resolved', $danger['receipt']['status']);

        $read = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['flag:checkpoints']),
            $manifest,
            $policy,
            'read',
        );

        $this->assertNotContains('--checkpoints', $read['args']);
        $this->assertSame('blocked_by_permission_mode', $read['receipt']['dropped'][0]['reason']);
    }

    public function test_standalone_flag_is_not_duplicated_when_already_present(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet', '--checkpoints'],
            $this->mission(['flag:checkpoints']),
            $this->manifest([$this->flagEntry('checkpoints', '--checkpoints')]),
            ['enabled' => true, 'allow' => ['flag:checkpoints']],
            'danger',
        );

        $this->assertSame(1, count(array_keys($result['args'], '--checkpoints', true)));
    }

    public function test_policy_disabled_returns_args_unchanged_with_policy_disabled_status(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser']),
            $this->manifest([$this->toolsetEntry('browser')]),
            ['enabled' => false, 'allow' => ['toolset:browser']],
            'danger',
        );

        $this->assertSame(['chat', '--quiet'], $result['args']);
        $this->assertSame([], $result['prompt_context_refs']);
        $this->assertSame('policy_disabled', $result['receipt']['status']);
        $this->assertFalse($result['receipt']['policy_enabled']);
        $this->assertFalse($result['receipt']['any_capability_enabled_now']);
        $this->assertSame(0, $result['receipt']['resolved_count']);
    }

    public function test_multiple_toolsets_merge_into_single_deduped_toolsets_csv(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet', '--toolsets', 'files'],
            $this->mission(['toolset:browser', 'toolset:shell', 'toolset:files']),
            $this->manifest([
                $this->toolsetEntry('browser'),
                $this->toolsetEntry('shell'),
                $this->toolsetEntry('files'),
            ]),
            ['enabled' => true, 'allow' => ['toolset:browser', 'toolset:shell', 'toolset:files']],
            'write',
        );

        $this->assertSame(1, count(array_keys($result['args'], '--toolsets', true)));
        $index = array_search('--toolsets', $result['args'], true);
        $csv = $result['args'][$index + 1];
        $parts = explode(',', $csv);

        $this->assertSame($parts, array_values(array_unique($parts)));
        $this->assertContains('files', $parts);
        $this->assertContains('browser', $parts);
        $this->assertContains('shell', $parts);
        $this->assertSame(3, $result['receipt']['resolved_count']);
        $this->assertSame('resolved', $result['receipt']['status']);
    }

    public function test_context_ref_inside_allowed_paths_is_emitted_as_prompt_at_reference(): void
    {
        $entry = [
            'id' => 'context_ref:/repo/docs/spec.md',
            'capability_class' => 'context_ref',
            'capability_key' => '/repo/docs/spec.md',
            'hermes_token' => '/repo/docs/spec.md',
            'supported' => true,
            'requires_config' => false,
            'detail' => [],
            'source' => 'mission',
        ];

        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['context_ref:/repo/docs/spec.md']),
            $this->manifest([$entry]),
            [
                'enabled' => true,
                'allow' => ['context_ref:/repo/docs/spec.md'],
                'allowed_paths' => ['/repo/'],
            ],
            'write',
        );

        $this->assertSame(['@/repo/docs/spec.md'], $result['prompt_context_refs']);
        $this->assertNotContains('@/repo/docs/spec.md', $result['args']);
        $this->assertSame(1, $result['receipt']['emitted_context_ref_count']);
        $this->assertSame('prompt_context_ref', $result['receipt']['resolved'][0]['emit_target']);
    }

    public function test_context_ref_outside_allowed_paths_is_dropped(): void
    {
        $entry = [
            'id' => 'context_ref:/etc/passwd',
            'capability_class' => 'context_ref',
            'capability_key' => '/etc/passwd',
            'hermes_token' => '/etc/passwd',
            'supported' => true,
            'requires_config' => false,
            'detail' => [],
            'source' => 'mission',
        ];

        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['context_ref:/etc/passwd']),
            $this->manifest([$entry]),
            [
                'enabled' => true,
                'allow' => ['context_ref:/etc/passwd'],
                'allowed_paths' => ['/repo/'],
            ],
            'write',
        );

        $this->assertSame([], $result['prompt_context_refs']);
        $this->assertSame('context_ref_not_in_allowed_paths', $result['receipt']['dropped'][0]['reason']);
    }

    public function test_no_requested_capabilities_yields_no_capabilities_status(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission([]),
            $this->manifest([$this->toolsetEntry('browser')]),
            ['enabled' => true, 'allow' => ['toolset:browser']],
            'write',
        );

        $this->assertSame(['chat', '--quiet'], $result['args']);
        $this->assertSame('no_capabilities', $result['receipt']['status']);
        $this->assertSame(0, $result['receipt']['requested_count']);
    }

    public function test_partially_dropped_status_when_some_resolve_and_some_drop(): void
    {
        $result = $this->builder()->apply(
            ['chat', '--quiet'],
            $this->mission(['toolset:browser', 'toolset:ghost']),
            $this->manifest([$this->toolsetEntry('browser')]),
            ['enabled' => true, 'allow' => ['toolset:browser', 'toolset:ghost']],
            'write',
        );

        $this->assertSame('partially_dropped', $result['receipt']['status']);
        $this->assertSame(1, $result['receipt']['resolved_count']);
        $this->assertSame(1, $result['receipt']['dropped_count']);
    }

    public function test_receipt_hash_is_deterministic_and_sealed_last(): void
    {
        $args = ['chat', '--quiet'];
        $mission = $this->mission(['toolset:browser']);
        $manifest = $this->manifest([$this->toolsetEntry('browser')]);
        $policy = ['enabled' => true, 'allow' => ['toolset:browser']];

        $first = $this->builder()->apply($args, $mission, $manifest, $policy, 'write');
        $second = $this->builder()->apply($args, $mission, $manifest, $policy, 'write');

        $this->assertSame('atlas.hermes.capability_invocation_receipt.v1', $first['receipt']['schema_version']);
        $this->assertSame('hermes_capability_invocation_builder', $first['receipt']['builder']);
        $this->assertNotEmpty($first['receipt']['receipt_hash']);
        $this->assertSame($first['receipt']['receipt_hash'], $second['receipt']['receipt_hash']);

        $keys = array_keys($first['receipt']);
        $this->assertSame('receipt_hash', end($keys));
    }
}
