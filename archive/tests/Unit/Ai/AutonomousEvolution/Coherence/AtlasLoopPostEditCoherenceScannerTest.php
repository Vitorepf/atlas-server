<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Coherence;

use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditCoherenceScanner;
use PHPUnit\Framework\TestCase;

final class AtlasLoopPostEditCoherenceScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-coherence-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_reports_dangling_method_references_deterministically(): void
    {
        $a = $this->write('A.php', "<?php\nclass A {\n    public function bar(): void {}\n}\n");
        $b = $this->write('B.php', "<?php\nclass B {\n    public function run(): void {\n        A::foo();\n    }\n}\n");

        $scanner = new AtlasLoopPostEditCoherenceScanner;
        $first = $scanner->scan([$b, $a]);
        $second = $scanner->scan([$b, $a]);

        $this->assertSame($first, $second);
        $this->assertSame([
            [
                'file' => $b,
                'line' => 4,
                'reason' => 'dangling_method_reference',
                'target_symbol' => 'A::foo',
            ],
        ], $first);
    }

    public function test_it_reports_unresolved_uses_and_parse_errors_without_quality_fields(): void
    {
        $badUse = $this->write('BadUse.php', "<?php\nuse Missing\\Ghost;\nclass BadUse {}\n");
        $badPhp = $this->write('Broken.php', "<?php\nclass Broken {\n    public function oops( {\n}\n");

        $rows = (new AtlasLoopPostEditCoherenceScanner)->scan([$badPhp, $badUse]);

        $this->assertCount(2, $rows);
        $byReason = [];
        foreach ($rows as $row) {
            $byReason[$row['reason']] = $row;
        }

        $this->assertSame($badPhp, $byReason['parse_error']['file']);
        $this->assertSame('unresolved_use', $byReason['unresolved_use']['reason']);
        $this->assertSame(2, $byReason['unresolved_use']['line']);
        $this->assertSame('Missing\\Ghost', $byReason['unresolved_use']['target_symbol']);

        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('"score"', $json);
        $this->assertStringNotContainsString('"rank"', $json);
        $this->assertStringNotContainsString('"quality"', $json);
    }

    public function test_aliased_use_of_resolvable_class_does_not_emit_unresolved_use_finding(): void
    {
        // PHPUnit\Framework\TestCase is always resolvable in this suite.
        $file = $this->write('Aliased.php', "<?php\nuse PHPUnit\\Framework\\TestCase as TC;\nclass Aliased extends TC {}\n");

        $rows = (new AtlasLoopPostEditCoherenceScanner)->scan([$file]);

        $unresolvedUseRows = array_values(array_filter($rows, static fn ($r): bool => $r['reason'] === 'unresolved_use'));
        $this->assertSame([], $unresolvedUseRows, 'a resolvable aliased import must not produce an unresolved_use finding');
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
