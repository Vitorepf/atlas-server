<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMethodNeutralizer;
use PHPUnit\Framework\TestCase;

/**
 * §5.6 · ORPHAN-WIRING — the neutralizer must make a CALLED method diverge while a bare instantiation does
 * NOT. That asymmetry is what lets the wiring proof reject a cosmetic `new Orphan()` (constructor intact =>
 * test stays green => non-load-bearing) while certifying a genuine wiring (a method is called => throws =>
 * test goes red).
 */
final class AtlasLoopMethodNeutralizerTest extends TestCase
{
    private function n(): AtlasLoopMethodNeutralizer
    {
        return new AtlasLoopMethodNeutralizer;
    }

    /** Load a neutralized source under a unique class name and return that name. */
    private function load(string $neutralized, string $className): string
    {
        $renamed = preg_replace('/\bclass\s+Orphan\b/', 'class '.$className, $neutralized, 1);
        $tmp = sys_get_temp_dir().'/atlas-neutralized-'.$className.'.php';
        file_put_contents($tmp, (string) $renamed);
        require $tmp;
        @unlink($tmp);

        return $className;
    }

    private function orphanSource(): string
    {
        return "<?php\nclass Orphan\n{\n    public int \$seen = 0;\n    public function __construct() { \$this->seen = 1; }\n    public function contribute(int \$n): int { return \$n * 10; }\n}\n";
    }

    public function test_a_called_method_diverges_but_the_constructor_does_not(): void
    {
        $neutralized = $this->n()->neutralize($this->orphanSource());
        $this->assertIsString($neutralized);
        $this->assertStringContainsString(AtlasLoopMethodNeutralizer::SENTINEL, $neutralized);

        $class = $this->load($neutralized, 'OrphanNeutA');

        // Constructor intact: a bare instantiation must NOT throw (cosmetic `new Orphan()` stays harmless).
        $obj = new $class;
        $this->assertSame(1, $obj->seen, 'the constructor was not neutralized');

        // A called method diverges: the wired path would break under neutralization.
        $threw = false;
        try {
            $obj->contribute(2);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), AtlasLoopMethodNeutralizer::SENTINEL);
        }
        $this->assertTrue($threw, 'a called method must diverge (the meaningful-wiring proof)');
    }

    public function test_unparseable_source_fails_closed_to_null(): void
    {
        $this->assertNull($this->n()->neutralize("<?php this is not ::: valid php {{{"));
    }

    public function test_constructor_only_class_is_untouched_behaviorally(): void
    {
        // A class whose ONLY method is the constructor: neutralization changes no callable behavior, so a
        // cosmetic instantiation can never be "killed" — exactly why such a wiring is rejected as non-load-bearing.
        $src = "<?php\nclass Orphan\n{\n    public int \$ok = 0;\n    public function __construct() { \$this->ok = 7; }\n}\n";
        $neutralized = $this->n()->neutralize($src);
        $this->assertIsString($neutralized);
        $class = $this->load((string) $neutralized, 'OrphanNeutC');
        $this->assertSame(7, (new $class)->ok, 'a constructor-only class is behaviorally untouched');
    }
}
