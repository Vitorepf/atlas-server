<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexTestCoverageLens;

final class AtlasCortexTestCoverageLensHardeningTest extends TestCase
{
    /**
     * extractFactsForFile must skip line nodes missing the num attribute.
     * Use reflection to call the private method directly.
     */
    public function test_missing_num_attribute_skipped(): void
    {
        $xml = <<<XML
<?xml version="1.0"?>
<clover>
  <file name="/test.php">
    <package name="root">
      <class name="Test" filename="/test.php">
        <metrics statements="1" loc="1"/>
        <line num="1" count="5" type="stmt"/>
        <line type="stmt"/>
      </class>
    </package>
  </file>
</clover>
XML;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $lens = new AtlasCortexTestCoverageLens();
        $method = new \ReflectionMethod($lens, 'extractFactsForFile');
        $entries = $method->invoke($lens, $dom, '/test.php');

        // All entries should have line > 0 (no line-0 pollution).
        foreach ($entries as $entry) {
            if (isset($entry['line'])) {
                $this->assertGreaterThan(0, $entry['line'], 'No line-0 entries from missing num attribute');
            }
        }
    }

    /**
     * Verify the source has the hasAttribute guard.
     */
    public function test_source_has_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Council/AtlasCortexTestCoverageLens.php');

        $this->assertStringContainsString("hasAttribute('num')", $source, 'must check num attribute presence');
    }
}
