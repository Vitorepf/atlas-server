<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Frozen;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractDriftDetector;
use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractRegistry;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopFrozenContractDriftDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $tmpDir) {
            $this->removeDirectory($tmpDir);
        }

        parent::tearDown();
    }

    public function test_detect_reports_matching_and_tampered_contract_facts(): void
    {
        $tmp = $this->makeTmpDir();
        $matchingBlock = "\n        \$this->assertTrue(true);\n";
        $expectedTamperedBlock = "\n        \$this->assertSame('expected', 'expected');\n";
        $actualTamperedBlock = "\n        \$this->assertSame('expected', 'actual');\n";

        $this->writeFrozenTest($tmp, 'tests/Frozen/MatchingContractTest.php', $matchingBlock);
        $this->writeFrozenTest($tmp, 'tests/Frozen/TamperedContractTest.php', $actualTamperedBlock);
        $manifestPath = $this->writeManifest($tmp, [
            'App\\Tests\\MatchingContract' => [
                'assertions_sha256' => hash('sha256', $matchingBlock),
                'registered_at' => '2026-06-24T05:00:00+00:00',
                'test_path' => 'tests/Frozen/MatchingContractTest.php',
            ],
            'App\\Tests\\TamperedContract' => [
                'assertions_sha256' => hash('sha256', $expectedTamperedBlock),
                'registered_at' => '2026-06-24T05:00:00+00:00',
                'test_path' => 'tests/Frozen/TamperedContractTest.php',
            ],
        ]);

        $result = (new AtlasLoopFrozenContractDriftDetector($tmp))
            ->detect(new AtlasLoopFrozenContractRegistry($manifestPath, $tmp));

        $this->assertSame([
            [
                'fqcn' => 'App\\Tests\\MatchingContract',
                'test_path' => 'tests/Frozen/MatchingContractTest.php',
                'expected_sha' => hash('sha256', $matchingBlock),
                'actual_sha' => hash('sha256', $matchingBlock),
                'drift' => false,
            ],
            [
                'fqcn' => 'App\\Tests\\TamperedContract',
                'test_path' => 'tests/Frozen/TamperedContractTest.php',
                'expected_sha' => hash('sha256', $expectedTamperedBlock),
                'actual_sha' => hash('sha256', $actualTamperedBlock),
                'drift' => true,
            ],
        ], $result);
        $this->assertNotSame($result[1]['expected_sha'], $result[1]['actual_sha']);
    }

    public function test_output_and_public_surface_are_facts_only(): void
    {
        $tmp = $this->makeTmpDir();
        $block = "\n        \$this->assertTrue(true);\n";
        $this->writeFrozenTest($tmp, 'tests/Frozen/FactsOnlyContractTest.php', $block);
        $manifestPath = $this->writeManifest($tmp, [
            'App\\Tests\\FactsOnlyContract' => [
                'assertions_sha256' => hash('sha256', $block),
                'registered_at' => '2026-06-24T05:00:00+00:00',
                'test_path' => 'tests/Frozen/FactsOnlyContractTest.php',
            ],
        ]);

        $result = (new AtlasLoopFrozenContractDriftDetector($tmp))
            ->detect(new AtlasLoopFrozenContractRegistry($manifestPath, $tmp));
        $reflection = new ReflectionClass(AtlasLoopFrozenContractDriftDetector::class);

        $this->assertSame(['fqcn', 'test_path', 'expected_sha', 'actual_sha', 'drift'], array_keys($result[0]));
        foreach (['score', 'grade', 'severity'] as $forbiddenField) {
            $this->assertArrayNotHasKey($forbiddenField, $result[0]);
            $this->assertFalse($reflection->hasProperty($forbiddenField));
            $this->assertFalse($reflection->hasMethod($forbiddenField));
            $this->assertFalse($reflection->hasConstant(strtoupper($forbiddenField)));
        }
    }

    /**
     * @param  array<string, array{assertions_sha256:string,registered_at:string,test_path:string}>  $manifest
     */
    private function writeManifest(string $tmp, array $manifest): string
    {
        $path = $tmp.'/contracts.manifest.json';
        file_put_contents(
            $path,
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        return $path;
    }

    private function writeFrozenTest(string $tmp, string $relativePath, string $assertionsBlock): void
    {
        $path = $tmp.'/'.$relativePath;
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            "<?php\n\n".
            "final class ExampleFrozenTest\n".
            "{\n".
            "    public function test_contract(): void\n".
            "    {\n".
            AtlasLoopFrozenContractDriftDetector::BEGIN_MARKER.
            $assertionsBlock.
            AtlasLoopFrozenContractDriftDetector::END_MARKER.
            "\n    }\n".
            "}\n"
        );
    }

    private function makeTmpDir(): string
    {
        $path = sys_get_temp_dir().'/atlas-frozen-drift-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);
        $this->tmpDirs[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
