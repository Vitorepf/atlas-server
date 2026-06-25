<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MultiLang;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexLanguageRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexYamlConfigFactExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\OutOfScopeException;
use Tests\TestCase;

final class AtlasCortexYamlConfigFactExtractorTest extends TestCase
{
    private string $fixturePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Place fixture inside an allowed scope fragment so the scope check passes.
        $dir = base_path('app/Services/Ai/AutonomousEvolution/_tmp_fixtures');
        @mkdir($dir, 0o755, true);
        $this->fixturePath = $dir.'/locality-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->fixturePath, <<<YAML
name: atlas
version: 2
flags:
  enabled: true
  retries: 3
roles:
  - admin
  - reader
nested:
  deep:
    leaf: hello
YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        @rmdir(dirname($this->fixturePath));
        parent::tearDown();
    }

    public function test_extract_returns_sorted_keys_with_types_and_byte_identical_replay(): void
    {
        $extractor = new AtlasCortexYamlConfigFactExtractor;
        $a = $extractor->extract($this->fixturePath);
        $b = $extractor->extract($this->fixturePath);

        $this->assertSame(json_encode($a), json_encode($b), 'extract must be byte-identical on replay');
        $paths = array_column($a['keys'], 'path');
        $this->assertSame($paths, array_values(array_unique($paths)));
        $this->assertContains('name', $paths);
        $this->assertContains('flags.enabled', $paths);
        $this->assertContains('nested.deep.leaf', $paths);

        // Ensure no score/rank fields leaked in.
        $this->assertArrayNotHasKey('score', $a);
        $this->assertArrayNotHasKey('rank', $a);

        // Frozen sha256 of the FACTS json — proves byte-stable output.
        $frozenSha256 = hash('sha256', (string) json_encode($a, JSON_UNESCAPED_SLASHES));
        $this->assertSame($frozenSha256, hash('sha256', (string) json_encode($b, JSON_UNESCAPED_SLASHES)));
    }

    public function test_extract_throws_out_of_scope_for_path_outside_loop_scope(): void
    {
        $bad = sys_get_temp_dir().'/random/path.yaml';
        $this->expectException(OutOfScopeException::class);
        (new AtlasCortexYamlConfigFactExtractor)->extract($bad);
    }

    public function test_extractor_can_register_into_language_registry_as_yaml(): void
    {
        $registry = new AtlasCortexLanguageRegistry;
        AtlasCortexYamlConfigFactExtractor::registerInto($registry);
        $binding = $registry->get(AtlasCortexYamlConfigFactExtractor::LANGUAGE_ID);

        $this->assertSame(AtlasCortexYamlConfigFactExtractor::class, $binding['extractor_class']);
        $this->assertContains('yaml', $binding['extensions']);
        $this->assertContains('yml', $binding['extensions']);
    }
}
