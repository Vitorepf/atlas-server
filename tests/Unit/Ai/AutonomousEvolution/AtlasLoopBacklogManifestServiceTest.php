<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogManifestService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopBacklogManifestServiceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/atlas-backlog-manifest-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }

        parent::tearDown();
    }

    public function test_append_treats_matching_source_path_and_reason_as_duplicate_even_with_different_objective(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        $this->writeManifest([
            [
                'source' => 'operator',
                'path' => 'docs/guide.md',
                'objective' => 'Existing objective',
                'reason' => 'same rationale',
                'priority' => 0.4,
                'source_key' => 'existing-key',
            ],
        ]);

        $result = $service->append($manifestPath, [
            'source' => 'operator',
            'path' => '/docs/guide.md',
            'objective' => 'New objective',
            'reason' => 'same rationale',
            'priority' => 0.9,
            'source_key' => 'incoming-key',
        ], write: false);

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('incoming-key', $result['source_key']);
        $this->assertSame('docs/guide.md', $result['item']['path']);
        $this->assertSame('New objective', $result['item']['objective']);
    }

    public function test_append_treats_matching_source_key_as_duplicate_even_when_other_fields_differ(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        $this->writeManifest([
            [
                'source' => 'operator',
                'path' => 'docs/original.md',
                'objective' => 'Existing objective',
                'reason' => 'existing rationale',
                'priority' => 0.4,
                'source_key' => 'shared-key',
            ],
        ]);

        $result = $service->append($manifestPath, [
            'source' => 'agent',
            'path' => '/docs/new.md',
            'objective' => 'Different objective',
            'reason' => 'different rationale',
            'priority' => 0.9,
            'source_key' => 'shared-key',
        ], write: false);

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('shared-key', $result['source_key']);
        $this->assertSame('docs/new.md', $result['item']['path']);
        $this->assertSame('Different objective', $result['item']['objective']);
        $this->assertSame('different rationale', $result['item']['reason']);
    }

    public function test_append_does_not_treat_different_source_key_as_duplicate_when_other_duplicate_conditions_do_not_match(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        $this->writeManifest([
            [
                'source' => 'operator',
                'path' => 'docs/original.md',
                'objective' => 'Existing objective',
                'reason' => 'existing rationale',
                'priority' => 0.4,
                'source_key' => 'existing-key',
            ],
        ]);

        $result = $service->append($manifestPath, [
            'source' => 'agent',
            'path' => '/docs/new.md',
            'objective' => 'Different objective',
            'reason' => 'different rationale',
            'priority' => 0.9,
            'source_key' => 'incoming-key',
        ], write: false);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame('incoming-key', $result['source_key']);
        $this->assertSame('docs/new.md', $result['item']['path']);
        $this->assertSame('Different objective', $result['item']['objective']);
        $this->assertSame('different rationale', $result['item']['reason']);
    }

    public function test_append_does_not_treat_matching_source_and_path_without_reason_as_duplicate_when_objective_differs(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        $this->writeManifest([
            [
                'source' => 'operator',
                'path' => 'docs/guide.md',
                'objective' => 'Existing objective',
                'reason' => '',
                'priority' => 0.4,
                'source_key' => 'existing-key',
            ],
        ]);

        $result = $service->append($manifestPath, [
            'source' => 'operator',
            'path' => '/docs/guide.md',
            'objective' => 'New objective',
            'reason' => '',
            'priority' => 0.9,
            'source_key' => 'incoming-key',
        ], write: false);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame('incoming-key', $result['source_key']);
        $this->assertSame('docs/guide.md', $result['item']['path']);
        $this->assertSame('New objective', $result['item']['objective']);
        $this->assertSame('', $result['item']['reason']);
    }

    public function test_append_treats_matching_source_path_and_objective_as_duplicate_even_without_reason(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        $this->writeManifest([
            [
                'source' => 'operator',
                'path' => 'docs/guide.md',
                'objective' => 'Keep same objective',
                'reason' => '',
                'priority' => 0.4,
                'source_key' => 'existing-key',
            ],
        ]);

        $result = $service->append($manifestPath, [
            'source' => 'operator',
            'path' => '/docs/guide.md',
            'objective' => 'Keep same objective',
            'reason' => 'different rationale',
            'priority' => 0.9,
            'source_key' => 'incoming-key',
        ], write: false);

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('incoming-key', $result['source_key']);
        $this->assertSame('docs/guide.md', $result['item']['path']);
        $this->assertSame('Keep same objective', $result['item']['objective']);
        $this->assertSame('different rationale', $result['item']['reason']);
    }

    public function test_read_returns_structured_manifest_when_items_key_exists(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        file_put_contents($manifestPath, json_encode([
            'schema_version' => AtlasLoopBacklogManifestService::SCHEMA_VERSION,
            'updated_at' => '2026-06-21T00:00:00Z',
            'items' => [
                [
                    'source' => 'operator',
                    'path' => 'docs/guide.md',
                    'objective' => 'Keep structured payload',
                    'reason' => 'fresh entry',
                    'priority' => 0.7,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $manifest = $service->read($manifestPath);

        $this->assertSame(AtlasLoopBacklogManifestService::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertSame('2026-06-21T00:00:00Z', $manifest['updated_at']);
        $this->assertCount(1, $manifest['items']);
        $this->assertSame('Keep structured payload', $manifest['items'][0]['objective']);
    }

    public function test_read_wraps_legacy_top_level_item_list_into_manifest_items(): void
    {
        $service = app(AtlasLoopBacklogManifestService::class);
        $manifestPath = $this->manifestPath();
        file_put_contents($manifestPath, json_encode([
            [
                'source' => 'operator',
                'path' => 'docs/legacy.md',
                'objective' => 'Legacy entry',
                'reason' => 'pre-schema format',
                'priority' => 0.5,
            ],
            'ignore-me',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $manifest = $service->read($manifestPath);

        $this->assertSame(AtlasLoopBacklogManifestService::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertArrayNotHasKey('0', $manifest);
        $this->assertCount(1, $manifest['items']);
        $this->assertSame('docs/legacy.md', $manifest['items'][0]['path']);
        $this->assertSame('Legacy entry', $manifest['items'][0]['objective']);
    }

    private function manifestPath(): string
    {
        return $this->dir.'/backlog-intents.json';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeManifest(array $items): void
    {
        file_put_contents($this->manifestPath(), json_encode([
            'schema_version' => AtlasLoopBacklogManifestService::SCHEMA_VERSION,
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
