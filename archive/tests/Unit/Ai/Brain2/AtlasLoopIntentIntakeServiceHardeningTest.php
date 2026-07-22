<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentIntakeService;

final class AtlasLoopIntentIntakeServiceHardeningTest extends TestCase
{
    /**
     * readItems must throw on a corrupt/truncated JSON file instead of
     * silently returning an empty list.
     */
    public function test_corrupt_json_throws(): void
    {
        $tmpDir = sys_get_temp_dir().'/intent-intake-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        $manifestPath = $tmpDir.'/manifest.json';
        file_put_contents($manifestPath, '{truncated json!!!');

        $service = new AtlasLoopIntentIntakeService($manifestPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('corrupt intent manifest');

        // Access private readItems via reflection.
        $method = new \ReflectionMethod($service, 'readItems');
        $method->invoke($service);

        @unlink($manifestPath);
        @rmdir($tmpDir);
    }

    /**
     * A missing file returns an empty list (not an error).
     */
    public function test_missing_file_returns_empty(): void
    {
        $tmpDir = sys_get_temp_dir().'/intent-intake-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        $manifestPath = $tmpDir.'/manifest.json';
        // File does not exist.
        $service = new AtlasLoopIntentIntakeService($manifestPath);

        $method = new \ReflectionMethod($service, 'readItems');
        $result = $method->invoke($service);

        $this->assertSame([], $result);

        @rmdir($tmpDir);
    }

    /**
     * A valid JSON file with items returns them correctly.
     */
    public function test_valid_json_returns_items(): void
    {
        $tmpDir = sys_get_temp_dir().'/intent-intake-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        $manifestPath = $tmpDir.'/manifest.json';
        file_put_contents($manifestPath, json_encode([
            'items' => [
                ['id' => 'intent-1', 'description' => 'fix auth'],
                ['id' => 'intent-2', 'description' => 'add logging'],
            ],
        ]));

        $service = new AtlasLoopIntentIntakeService($manifestPath);

        $method = new \ReflectionMethod($service, 'readItems');
        $result = $method->invoke($service);

        $this->assertCount(2, $result);
        $this->assertSame('intent-1', $result[0]['id']);
        $this->assertSame('intent-2', $result[1]['id']);

        @unlink($manifestPath);
        @rmdir($tmpDir);
    }

    /**
     * Verify the source code has the fail-closed guard.
     */
    public function test_source_has_corrupt_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopIntentIntakeService.php');

        $this->assertStringContainsString('RuntimeException', $source, 'readItems must throw RuntimeException on corrupt manifest');
        $this->assertStringNotContainsString('(string) @file_get_contents', $source, 'readItems must not cast file_get_contents to string without checking');
    }
}
