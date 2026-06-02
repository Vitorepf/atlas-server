<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesProfileResolver;
use Tests\TestCase;

class HermesProfileResolverTest extends TestCase
{
    private function resolver(): HermesProfileResolver
    {
        return app(HermesProfileResolver::class);
    }

    /**
     * @param  array<string,mixed>  $profiles
     */
    private function withCatalog(array $profiles): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.profiles', $profiles);
    }

    /**
     * @param  array<int,string>  $supportedToolsets
     * @return array<int,array<string,mixed>>
     */
    private function toolsetManifest(array $supportedToolsets, bool $supported = true): array
    {
        return collect($supportedToolsets)
            ->map(fn (string $toolset): array => [
                'id' => 'toolset:'.$toolset,
                'capability_class' => 'toolset',
                'capability_key' => $toolset,
                'hermes_token' => $toolset,
                'supported' => $supported,
                'requires_config' => false,
                'detail' => $toolset.' toolset',
                'source' => 'probe',
            ])
            ->all();
    }

    public function test_resolves_known_role_with_stateless_manifest_keeps_configured_toolsets(): void
    {
        $this->withCatalog([
            'engineer' => [
                'toolsets' => ['file', 'shell', 'code_execution'],
                'provider' => 'codex',
                'model' => 'gpt-5.5',
                'skills' => ['refactor', 'test'],
            ],
        ]);

        $receipt = $this->resolver()->resolve('engineer');

        $this->assertSame('schema_version', array_key_first($receipt));
        $this->assertSame('atlas.hermes.profile_resolution.v1', $receipt['schema_version']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_profile_can_decide']);
        $this->assertTrue($receipt['role_known']);
        $this->assertSame('engineer', $receipt['role']);
        // Stateless manifest => filter skipped, configured toolsets kept verbatim.
        $this->assertFalse($receipt['manifest_filter_applied']);
        $this->assertSame(['file', 'shell', 'code_execution'], $receipt['resolved']['toolsets']);
        $this->assertSame('codex', $receipt['resolved']['provider']);
        $this->assertSame('gpt-5.5', $receipt['resolved']['model']);
        $this->assertSame(['refactor', 'test'], $receipt['resolved']['skills']);
        $this->assertSame([], $receipt['dropped_toolsets']);
        $this->assertSame('resolved_known_role', $receipt['status']);
        $this->assertArrayHasKey('receipt_hash', $receipt);
    }

    public function test_unknown_role_falls_closed_to_minimal_read_profile(): void
    {
        $this->withCatalog([
            'engineer' => ['toolsets' => ['file', 'shell'], 'provider' => 'codex', 'model' => 'gpt-5.5', 'skills' => ['x']],
        ]);

        $receipt = $this->resolver()->resolve('ghost-role');

        $this->assertFalse($receipt['role_known']);
        $this->assertSame('ghost-role', $receipt['role']);
        $this->assertSame(['file'], $receipt['resolved']['toolsets']);
        $this->assertNull($receipt['resolved']['provider']);
        $this->assertNull($receipt['resolved']['model']);
        $this->assertSame([], $receipt['resolved']['skills']);
        $this->assertSame([], $receipt['dropped_toolsets']);
        $this->assertSame('resolved_minimal_read_fallback', $receipt['status']);
        $this->assertArrayHasKey('receipt_hash', $receipt);
    }

    public function test_empty_catalog_fails_closed_to_minimal_read(): void
    {
        $this->withCatalog([]);

        $receipt = $this->resolver()->resolve('engineer');

        $this->assertFalse($receipt['role_known']);
        $this->assertSame(['file'], $receipt['resolved']['toolsets']);
        $this->assertNull($receipt['resolved']['provider']);
        $this->assertNull($receipt['resolved']['model']);
        $this->assertSame('resolved_minimal_read_fallback', $receipt['status']);
    }

    public function test_manifest_filters_unsupported_toolsets_and_records_dropped(): void
    {
        $this->withCatalog([
            'engineer' => [
                'toolsets' => ['file', 'shell', 'code_execution'],
                'provider' => 'codex',
                'model' => 'gpt-5.5',
                'skills' => [],
            ],
        ]);

        // Manifest supports only file + shell -> code_execution must be dropped.
        $manifest = $this->toolsetManifest(['file', 'shell']);

        $receipt = $this->resolver()->resolve('engineer', $manifest);

        $this->assertTrue($receipt['role_known']);
        $this->assertTrue($receipt['manifest_filter_applied']);
        $this->assertSame(['file', 'shell'], $receipt['resolved']['toolsets']);
        $this->assertSame(['code_execution'], $receipt['dropped_toolsets']);
    }

    public function test_manifest_with_unsupported_flag_drops_toolset(): void
    {
        $this->withCatalog([
            'engineer' => ['toolsets' => ['file', 'shell'], 'provider' => null, 'model' => null, 'skills' => []],
        ]);

        // 'shell' present in manifest but supported=false -> dropped.
        $manifest = array_merge(
            $this->toolsetManifest(['file']),
            $this->toolsetManifest(['shell'], supported: false),
        );

        $receipt = $this->resolver()->resolve('engineer', $manifest);

        $this->assertSame(['file'], $receipt['resolved']['toolsets']);
        $this->assertSame(['shell'], $receipt['dropped_toolsets']);
        $this->assertTrue($receipt['manifest_filter_applied']);
    }

    public function test_invalid_input_is_clamped_and_sanitized(): void
    {
        $this->withCatalog([
            'engineer' => [
                'toolsets' => ['file', 123, '', '  shell  ', 'file'],
                'provider' => '   ',
                'model' => null,
                'skills' => ['ok', null, false],
            ],
        ]);

        $receipt = $this->resolver()->resolve('  engineer  ');

        $this->assertTrue($receipt['role_known']);
        $this->assertSame('engineer', $receipt['role']);
        // 123 is numeric -> kept as '123'; blanks dropped; duplicates removed; trimmed.
        $this->assertSame(['file', '123', 'shell'], $receipt['resolved']['toolsets']);
        // Whitespace-only provider sanitizes to null; never invents a provider.
        $this->assertNull($receipt['resolved']['provider']);
        $this->assertSame(['ok'], $receipt['resolved']['skills']);
    }

    public function test_role_with_empty_toolsets_falls_back_to_minimal_read_toolsets(): void
    {
        $this->withCatalog([
            'observer' => ['toolsets' => [], 'provider' => 'gemini', 'model' => 'g', 'skills' => []],
        ]);

        $receipt = $this->resolver()->resolve('observer');

        $this->assertTrue($receipt['role_known']);
        $this->assertSame(['file'], $receipt['resolved']['toolsets']);
        // Provider/model from config are preserved even when toolsets empty.
        $this->assertSame('gemini', $receipt['resolved']['provider']);
        $this->assertSame('g', $receipt['resolved']['model']);
    }

    public function test_receipt_hash_is_deterministic_and_last_key(): void
    {
        $this->withCatalog([
            'engineer' => ['toolsets' => ['file', 'shell'], 'provider' => 'codex', 'model' => 'm', 'skills' => ['s']],
        ]);

        $a = $this->resolver()->resolve('engineer');
        $b = $this->resolver()->resolve('engineer');

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $keys = array_keys($a);
        $this->assertSame('receipt_hash', end($keys));

        // Differing inputs produce a different hash.
        $c = $this->resolver()->resolve('ghost');
        $this->assertNotSame($a['receipt_hash'], $c['receipt_hash']);
    }

    public function test_blank_role_fails_closed_and_records_null_role(): void
    {
        $this->withCatalog([
            'engineer' => ['toolsets' => ['file', 'shell'], 'provider' => 'codex', 'model' => 'm', 'skills' => []],
        ]);

        $receipt = $this->resolver()->resolve('   ');

        $this->assertFalse($receipt['role_known']);
        $this->assertNull($receipt['role']);
        $this->assertSame(['file'], $receipt['resolved']['toolsets']);
        $this->assertSame('resolved_minimal_read_fallback', $receipt['status']);
    }
}
