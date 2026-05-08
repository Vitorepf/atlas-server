<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApDependencyMap;
use Tests\TestCase;

final class AtlasApDependencyMapTest extends TestCase
{
    public function test_current_dependency_map_is_read_only(): void
    {
        $payload = app(AtlasApDependencyMap::class)->map();

        $this->assertSame('atlas.ap_dependency_map.v1', $payload['schema_version']);
        $this->assertSame('read_only_dependency_map', $payload['mode']);
        $this->assertSame('ap_dependency_map_only_no_file_writes', $payload['authority']);
        $this->assertGreaterThanOrEqual(90, $payload['ap_count']);
        $this->assertGreaterThanOrEqual(1, $payload['edge_count']);
        $this->assertSame('ok', data_get($payload, 'governance.status'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.infers_semantic_dependencies'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_explicit_ap_references'));
    }

    public function test_dependency_map_extracts_depends_on_and_body_references(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-dependency-map-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-020-base.md', "# AP-020 - Base\n");
            file_put_contents($dir.'/AP-021-child.md', implode("\n", [
                '---',
                'title: Child',
                'status: implemented',
                'depends_on:',
                '  - AP-020',
                '---',
                '# AP-021 - Child',
                '',
                'This body also mentions AP-020 explicitly.',
                '',
            ]));
            file_put_contents($dir.'/AP-022-reader.md', implode("\n", [
                '# AP-022 - Reader',
                '',
                'This AP consumes AP-021.',
                '',
            ]));

            $payload = app(AtlasApDependencyMap::class)->map($dir);

            $this->assertSame('ok', $payload['status']);
            $this->assertSame(3, $payload['ap_count']);
            $this->assertSame(2, $payload['edge_count']);
            $this->assertSame('AP-21', data_get($payload, 'edges.0.from_ap'));
            $this->assertSame('AP-20', data_get($payload, 'edges.0.to_ap'));
            $this->assertEqualsCanonicalizing(['depends_on', 'body_reference'], data_get($payload, 'edges.0.sources'));
            $this->assertSame(['AP-21'], data_get($payload, 'dependents_by_ap.AP-20'));
            $this->assertSame(['AP-20'], data_get($payload, 'dependencies_by_ap.AP-21'));
            $this->assertSame(['AP-21'], data_get($payload, 'dependencies_by_ap.AP-22'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_dependency_map_surfaces_missing_ap_references(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-dependency-map-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-030-lonely.md', implode("\n", [
                '# AP-030 - Lonely',
                '',
                'This AP references AP-999 which is not present.',
                '',
            ]));

            $payload = app(AtlasApDependencyMap::class)->map($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['missing_reference_count']);
            $this->assertSame('AP-30', data_get($payload, 'missing_references.0.from_ap'));
            $this->assertSame('AP-999', data_get($payload, 'missing_references.0.to_ap'));
            $this->assertSame('review_missing_ap_references_before_relying_on_dependency_map', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
