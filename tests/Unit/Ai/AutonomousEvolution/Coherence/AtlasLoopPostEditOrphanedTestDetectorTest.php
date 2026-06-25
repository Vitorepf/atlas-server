<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Coherence;

use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditOrphanedTestDetector;
use Tests\TestCase;

final class AtlasLoopPostEditOrphanedTestDetectorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-orphaned-test-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function writeTest(string $name, string $contents): string
    {
        $path = $this->root.'/'.$name.'.php';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_detects_orphaned_test_via_covers_annotation_when_target_removed(): void
    {
        $path = $this->writeTest('SomethingTest', <<<'PHP'
<?php

/**
 * @covers \App\Demo\Something::doIt
 */
final class SomethingTest extends \PHPUnit\Framework\TestCase {}
PHP);

        $detector = new AtlasLoopPostEditOrphanedTestDetector();
        $findings = $detector->detect([$path], ['App\\Demo\\Something::doIt']);

        $this->assertCount(1, $findings);
        $this->assertSame('App\\Demo\\Something', $findings[0]['target_fqcn']);
        $this->assertSame('App\\Demo\\Something::doIt', $findings[0]['missing_symbol']);
        $this->assertSame('covers_annotation', $findings[0]['detection_rule']);
    }

    public function test_detects_orphaned_test_via_class_name_convention(): void
    {
        $path = $this->writeTest('GhostTest', <<<'PHP'
<?php

namespace Tests\Demo;

final class GhostTest extends \PHPUnit\Framework\TestCase {}
PHP);

        $detector = new AtlasLoopPostEditOrphanedTestDetector();
        // Target App\Demo\Ghost does not exist (not removed but never autoloadable).
        $findings = $detector->detect([$path], []);

        $this->assertCount(1, $findings);
        $this->assertStringEndsWith('Ghost', $findings[0]['target_fqcn']);
        $this->assertSame('class_name_convention', $findings[0]['detection_rule']);
    }

    public function test_does_not_flag_test_whose_target_still_exists(): void
    {
        $path = $this->writeTest('ExistingTest', <<<'PHP'
<?php

/**
 * @covers \Tests\TestCase
 */
final class ExistingTest extends \PHPUnit\Framework\TestCase {}
PHP);

        $detector = new AtlasLoopPostEditOrphanedTestDetector();
        $findings = $detector->detect([$path], []);

        $this->assertSame([], $findings, 'test for an existing class must not be flagged');
    }

    public function test_findings_are_sorted_byte_stably(): void
    {
        $a = $this->writeTest('AlphaTest', <<<'PHP'
<?php
/** @covers \App\Demo\Alpha */
final class AlphaTest extends \PHPUnit\Framework\TestCase {}
PHP);
        $b = $this->writeTest('BetaTest', <<<'PHP'
<?php
/** @covers \App\Demo\Beta */
final class BetaTest extends \PHPUnit\Framework\TestCase {}
PHP);

        $detector = new AtlasLoopPostEditOrphanedTestDetector();
        $findings = $detector->detect([$b, $a], ['App\\Demo\\Alpha', 'App\\Demo\\Beta']);
        $this->assertSame('AlphaTest', basename($findings[0]['file'], '.php'));
        $this->assertSame('BetaTest', basename($findings[1]['file'], '.php'));
    }
}
