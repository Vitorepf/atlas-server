<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cortex;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalConfigLoader;
use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalContract;
use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalFactsSchema;
use Tests\TestCase;

/**
 * EXISTENCE PROOF that Atlas Cortex v+infinity is portable to a FOREIGN repo. The test creates a
 * self-contained fixture repo on a sys_get_temp_dir() path (OUTSIDE the atlas-server tree), plants three
 * known artefacts (orphan / clone cluster / doc-stated gap), writes a per-repo cortex.yaml, then runs the
 * universal contract end-to-end and asserts the FACTS match the planted truth.
 */
final class AtlasCortexPortabilityProofTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/cortex-portability-fixture-'.bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot.'/src', 0775, true);
        mkdir($this->fixtureRoot.'/docs', 0775, true);

        // Plant 1: an ORPHAN — a class no other file references.
        file_put_contents($this->fixtureRoot.'/src/PortabilityOrphanClass.php', <<<'PHP'
<?php
namespace PortabilityFixture;

class PortabilityOrphanClass
{
    public function noOneCallsMe(): string
    {
        return 'orphan';
    }
}
PHP);

        // Plant 2: a CLONE CLUSTER — two files with the same duplicated method body.
        $cloneBody = <<<'PHP'

    public function duplicatedBehaviour(int $x): int
    {
        $a = $x + 1;
        $b = $a * 2;
        $c = $b - 3;
        $d = $c * $c;
        $e = $d + $x;
        $f = $e - 1;
        $g = $f * 2;
        $h = $g + 5;
        $i = $h - 2;
        $j = $i + $x;
        $k = $j * 3;
        $l = $k - 4;
        $m = $l + 7;
        $n = $m * 2;
        $o = $n - 1;
        $p = $o + $x;
        $q = $p * 2;
        $r = $q - 3;
        $s = $r + 1;
        $t = $s * 2;
        return $t;
    }

PHP;
        file_put_contents($this->fixtureRoot.'/src/PortabilityCloneA.php', "<?php\nnamespace PortabilityFixture;\nclass PortabilityCloneA {".$cloneBody."}\n");
        file_put_contents($this->fixtureRoot.'/src/PortabilityCloneB.php', "<?php\nnamespace PortabilityFixture;\nclass PortabilityCloneB {".$cloneBody."}\n");

        // Plant 3: a DOC-STATED GAP — docs mention a stub class that does not exist in source.
        file_put_contents($this->fixtureRoot.'/docs/intent.md', <<<'MD'
# Fixture intent

We plan to build `AtlasLoopPortabilityStub` to coordinate the demo flow. The stub will:
- accept input
- run validation
- emit a receipt

For now the stub class does not exist; this is a doc-stated gap.
MD);

        // cortex.yaml — the per-repo declarative recipe.
        file_put_contents($this->fixtureRoot.'/cortex.yaml', <<<'YAML'
schema_id: atlas.cortex.facts.v1
scope_roots:
  - src
doc_roots:
  - docs/intent.md
forbidden_globs:
  - vendor/**
clone_min_lines: 5
YAML);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureRoot)) {
            shell_exec('rm -rf '.escapeshellarg($this->fixtureRoot));
        }
        $this->assertDirectoryDoesNotExist($this->fixtureRoot, 'fixture must be cleaned up — no leak into atlas-server tree');
        parent::tearDown();
    }

    public function test_cortex_comprehends_a_foreign_fixture_repo_end_to_end(): void
    {
        // (1) Portability anchors: fixture lives OUTSIDE atlas-server, rooted under sys_get_temp_dir().
        $this->assertNotSame((string) base_path(), $this->fixtureRoot, 'fixture must not equal atlas-server base_path');
        $this->assertTrue(str_starts_with($this->fixtureRoot, sys_get_temp_dir()), 'fixture must be under sys_get_temp_dir');

        // (2) Resolve the universal contract + config loader (interface-only, no Atlas internals).
        $contract = $this->app->make(AtlasCortexUniversalContract::class);
        $loader = new AtlasCortexUniversalConfigLoader;
        $config = $loader->load($this->fixtureRoot);
        $this->assertSame('atlas.cortex.facts.v1', $config['schema_id']);

        // (3) Comprehend.
        $facts = $contract->comprehend($this->fixtureRoot, $config);

        // (4) Schema validates clean.
        $schema = new AtlasCortexUniversalFactsSchema;
        $errors = $schema->validate($facts);
        $this->assertSame([], $errors, 'schema validation must be clean: '.implode('; ', $errors));

        // (5) Planted orphan is present in the orphans list.
        $orphanText = json_encode($facts['orphans']);
        $this->assertStringContainsString('PortabilityOrphanClass', (string) $orphanText, 'planted orphan must appear in orphans');

        // (6) At least one clone cluster contains both planted clone files.
        $clusterText = json_encode($facts['clone_clusters']);
        $this->assertStringContainsString('PortabilityCloneA', (string) $clusterText, 'clone cluster must include PortabilityCloneA');
        $this->assertStringContainsString('PortabilityCloneB', (string) $clusterText, 'clone cluster must include PortabilityCloneB');

        // (7) Doc-stated gap captures the stub class mentioned only in docs.
        $gapsText = json_encode($facts['doc_stated_gaps']);
        $this->assertStringContainsString('AtlasLoopPortabilityStub', (string) $gapsText, 'doc-stated gap must list the planted stub class');
    }
}
