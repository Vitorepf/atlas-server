<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalConfigLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the per-repo Cortex config loader: parses the five canonical keys from a raw YAML string, refuses
 * missing/wrong schema_id, refuses empty scope_roots, and the loader source contains ZERO references to the
 * forbidden portability anti-patterns (base_path, storage_path, config, env, app).
 */
final class AtlasCortexUniversalConfigLoaderTest extends TestCase
{
    private function loader(): AtlasCortexUniversalConfigLoader
    {
        return new AtlasCortexUniversalConfigLoader;
    }

    public function test_loads_five_canonical_keys_from_raw_yaml(): void
    {
        $yaml = <<<'YAML'
        schema_id: atlas.cortex.facts.v1
        scope_roots:
          - src
          - lib
        doc_roots:
          - docs
        forbidden_globs:
          - vendor/**
          - node_modules/**
        clone_min_lines: 30
        YAML;

        $cfg = $this->loader()->load('/any/path', $yaml);

        $this->assertSame([
            'scope_roots' => ['src', 'lib'],
            'doc_roots' => ['docs'],
            'forbidden_globs' => ['vendor/**', 'node_modules/**'],
            'clone_min_lines' => 30,
            'schema_id' => 'atlas.cortex.facts.v1',
        ], $cfg);
    }

    public function test_accepts_json_payload_too(): void
    {
        $json = json_encode([
            'schema_id' => 'atlas.cortex.facts.v1',
            'scope_roots' => ['src'],
            'doc_roots' => [],
            'forbidden_globs' => [],
            'clone_min_lines' => 10,
        ]);

        $cfg = $this->loader()->load('/x', (string) $json);
        $this->assertSame(['src'], $cfg['scope_roots']);
        $this->assertSame(10, $cfg['clone_min_lines']);
    }

    public function test_throws_when_schema_id_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/schema_id/');
        $this->loader()->load('/x', "scope_roots:\n  - src\n");
    }

    public function test_throws_when_schema_id_is_wrong_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/schema_id/');
        $this->loader()->load('/x', "schema_id: atlas.cortex.facts.v9\nscope_roots:\n  - src\n");
    }

    public function test_throws_when_scope_roots_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scope_roots/');
        $this->loader()->load('/x', "schema_id: atlas.cortex.facts.v1\nscope_roots:\n");
    }

    public function test_throws_when_no_config_file_and_no_override(): void
    {
        $emptyDir = sys_get_temp_dir().'/atlas_cortex_cfg_'.bin2hex(random_bytes(4));
        mkdir($emptyDir, 0775, true);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->loader()->load($emptyDir);
        } finally {
            @rmdir($emptyDir);
        }
    }

    public function test_reads_cortex_yaml_from_disk_at_repo_root(): void
    {
        $dir = sys_get_temp_dir().'/atlas_cortex_cfg_'.bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        file_put_contents($dir.'/cortex.yaml', "schema_id: atlas.cortex.facts.v1\nscope_roots:\n  - src\n");
        try {
            $cfg = $this->loader()->load($dir);
            $this->assertSame(['src'], $cfg['scope_roots']);
        } finally {
            @unlink($dir.'/cortex.yaml');
            @rmdir($dir);
        }
    }

    public function test_loader_source_has_zero_portability_anti_patterns(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(AtlasCortexUniversalConfigLoader::class))->getFileName());

        foreach (['base_path(', 'storage_path(', 'config(', 'env(', 'app('] as $banned) {
            $this->assertStringNotContainsString(
                $banned,
                $source,
                "loader must NOT use {$banned} (must stay portable across non-Atlas repos)",
            );
        }
    }
}
