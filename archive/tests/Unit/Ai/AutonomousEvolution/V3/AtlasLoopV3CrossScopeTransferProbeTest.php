<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3CrossScopeTransferProbe;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AtlasLoopV3CrossScopeTransferProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/atlas-cross-scope-transfer-probe-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_zero_matching_files_returns_no_material(): void
    {
        $result = $this->probe()->probe($this->fingerprint(), $this->root);

        $this->assertSame('no_material', $result['verdict']);
        $this->assertFalse($result['transferable']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame([], $result['already_wired']);
        $this->assertSame(0, $result['scanned']);
        $this->assertNull($result['note']);
    }

    public function test_all_matching_files_already_wired_returns_saturated(): void
    {
        $this->write('src/Wired.php', '<?php new AtlasLoopWiringMaterialGrader;');

        $result = $this->probe()->probe($this->fingerprint(), $this->root);

        $this->assertSame(AtlasLoopV3CrossScopeTransferProbe::SCHEMA, $result['schema']);
        $this->assertSame('saturated', $result['verdict']);
        $this->assertFalse($result['transferable']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame(['src/Wired.php'], $result['already_wired']);
    }

    public function test_mixed_matches_returns_transferable_with_candidate_and_already_wired_partitions(): void
    {
        $unwired = '<?php final class UnwiredConsumer {}';
        $this->write('src/Unwired.php', $unwired);
        $this->write('src/Wired.php', '<?php use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;');

        $result = $this->probe()->probe($this->fingerprint(), $this->root);

        $this->assertSame('transferable', $result['verdict']);
        $this->assertTrue($result['transferable']);
        $this->assertSame(['src/Unwired.php'], $result['candidates']);
        $this->assertSame(['src/Wired.php'], $result['already_wired']);
        $this->assertSame($unwired, file_get_contents($this->root.'/src/Unwired.php'), 'probe is read-only');
    }

    public function test_invalid_fingerprint_throws_documented_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_fingerprint');

        $this->probe()->probe(['path_patterns' => ['src/*.php']], $this->root);
    }

    public function test_unknown_target_root_throws_documented_prefix(): void
    {
        $missing = $this->root.'/missing';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown_target_root:'.$missing);

        $this->probe()->probe($this->fingerprint(), $missing);
    }

    public function test_max_scan_cap_is_honoured_and_reported(): void
    {
        foreach (range(1, 5) as $i) {
            $this->write(sprintf('src/Candidate%d.php', $i), '<?php final class Candidate'.$i.' {}');
        }

        $result = $this->probe()->probe($this->fingerprint(), $this->root, 2);

        $this->assertSame(2, $result['scanned']);
        $this->assertSame('scan_capped', $result['note']);
        $this->assertCount(2, $result['candidates']);
    }

    public function test_output_lists_are_sorted_and_deterministic(): void
    {
        $this->write('src/Zeta.php', '<?php final class Zeta {}');
        $this->write('src/Alpha.php', '<?php final class Alpha {}');
        $this->write('src/Middle.php', '<?php new AtlasLoopWiringMaterialGrader;');

        $first = $this->probe()->probe($this->fingerprint(), $this->root);
        $second = $this->probe()->probe($this->fingerprint(), $this->root);

        $this->assertSame($first, $second);
        $this->assertSame(['src/Alpha.php', 'src/Zeta.php'], $first['candidates']);
        $this->assertSame(['src/Middle.php'], $first['already_wired']);
    }

    private function probe(): AtlasLoopV3CrossScopeTransferProbe
    {
        return new AtlasLoopV3CrossScopeTransferProbe;
    }

    /**
     * @param  list<string>  $patterns
     * @return array{fqcn:string,path_patterns:list<string>}
     */
    private function fingerprint(array $patterns = ['src/*.php']): array
    {
        return [
            'fqcn' => AtlasLoopWiringMaterialGrader::class,
            'path_patterns' => $patterns,
        ];
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
