<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeadCodeRemover;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * The DETERMINISTIC dead-code author. It is the provider-LESS half that makes the dead-code work-type the
 * hermes-free path to a live certified delivery, so the tests pin the load-bearing guarantees: it removes
 * exactly the analyzer-confirmed dead private members (with their docblocks), preserves every live member,
 * yields valid PHP, and FAILS CLOSED rather than risk deleting a live sibling.
 */
final class AtlasLoopDeadCodeRemoverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-deadcode-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $php): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $php);

        return $path;
    }

    private function parses(string $php): bool
    {
        try {
            return (new ParserFactory)->createForHostVersion()->parse($php) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_removes_dead_method_and_const_while_preserving_live_members(): void
    {
        $path = $this->write('Sample.php', <<<'PHP'
<?php

class Sample
{
    public function entry(): int
    {
        return $this->liveHelper();
    }

    /** This whole docblock must go with the dead method. */
    private function deadHelper(): string
    {
        return 'never called from anywhere in-class';
    }

    private function liveHelper(): int
    {
        return 42;
    }

    private const DEAD_K = 'unused';
}
PHP);

        // The analyzer is the oracle; confirm it flags exactly the two dead private members.
        $report = (new AtlasDeadCodeAnalyzer)->analyzeFile($path);
        $names = array_map(static fn (array $m): string => $m['name'], $report['dead']);
        sort($names);
        $this->assertSame(['DEAD_K', 'deadHelper'], $names, 'analyzer flags exactly the dead private method + const');

        $result = (new AtlasLoopDeadCodeRemover)->remove($path);
        $this->assertNotNull($result, 'a file with provably-dead members yields a removal');
        $content = $result['content'];

        $this->assertStringNotContainsString('deadHelper', $content, 'the dead method is gone');
        $this->assertStringNotContainsString('This whole docblock must go', $content, 'the dead method docblock is gone too');
        $this->assertStringNotContainsString('DEAD_K', $content, 'the dead const is gone');
        $this->assertStringContainsString('liveHelper', $content, 'the live private helper is preserved');
        $this->assertStringContainsString('public function entry', $content, 'the public entry point is preserved');
        $this->assertTrue($this->parses($content), 'the cleaned source is valid PHP');
        $this->assertCount(2, $result['removed']);
    }

    public function test_fails_closed_when_nothing_is_provably_dead(): void
    {
        $path = $this->write('Clean.php', <<<'PHP'
<?php

class Clean
{
    public function entry(): int
    {
        return $this->helper();
    }

    private function helper(): int
    {
        return 1;
    }
}
PHP);

        $this->assertNull((new AtlasLoopDeadCodeRemover)->remove($path), 'no dead members => fail closed (a no-op is not a removal)');
    }

    public function test_fails_closed_on_a_multi_declaration_node_never_deleting_a_live_sibling(): void
    {
        $php = <<<'PHP'
<?php

class MultiDecl
{
    private int $alive = 1, $deadSibling = 2;

    public function use(): int
    {
        return $this->alive;
    }
}
PHP;
        $path = $this->write('MultiDecl.php', $php);
        $line = 0;
        foreach (explode("\n", $php) as $i => $text) {
            if (str_contains($text, '$alive = 1')) {
                $line = $i + 1;
                break;
            }
        }
        $this->assertGreaterThan(0, $line);

        // Hand the remover a dead-member finding pointing at the shared multi-declaration node. Even though
        // $deadSibling is genuinely unused, removing the node would delete the LIVE $alive — so it must fail closed.
        $result = (new AtlasLoopDeadCodeRemover)->remove($path, [
            ['kind' => 'property', 'name' => 'deadSibling', 'line' => $line, 'class' => 'MultiDecl'],
        ]);

        $this->assertNull($result, 'a flagged member sharing a node with a live sibling => fail closed (never delete the sibling)');
    }
}
