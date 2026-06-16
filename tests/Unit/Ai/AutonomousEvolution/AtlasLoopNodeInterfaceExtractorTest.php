<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceExtractor;
use PHPUnit\Framework\TestCase;

/**
 * ACDE Leap 6 — the node-interface extractor reads a file's REAL exported surface via AST (decorrelated
 * from any provider LLM). It reports declared types' FQN + kind + PUBLIC methods (private/protected
 * excluded) + extends/implements, plus the file's use-imports. A parse failure degrades to an empty,
 * unparsed surface — never throws.
 */
final class AtlasLoopNodeInterfaceExtractorTest extends TestCase
{
    private function ex(): AtlasLoopNodeInterfaceExtractor
    {
        return new AtlasLoopNodeInterfaceExtractor;
    }

    public function test_extracts_fqn_public_api_extends_implements_and_imports(): void
    {
        $src = "<?php\nnamespace App\\Support;\nuse App\\Contracts\\Foo;\nuse Illuminate\\Support\\Str;\n"
            ."final class HubHelper extends Base implements Foo {\n"
            ."  public function stepA(): void {}\n"
            ."  private function secret(): void {}\n"
            ."  protected function helper(): void {}\n"
            ."  public function stepB(): int { return 1; }\n}\n";

        $out = $this->ex()->extract($src);

        $this->assertTrue($out['parsed']);
        $this->assertSame('App\\Support', $out['namespace']);
        $this->assertCount(1, $out['types']);
        $t = $out['types'][0];
        $this->assertSame('App\\Support\\HubHelper', $t['fqn']);
        $this->assertSame('class', $t['kind']);
        $this->assertSame(['stepA', 'stepB'], $t['public_methods'], 'only PUBLIC methods, sorted; private/protected excluded');
        $this->assertSame(['Foo'], $t['implements']);
        $this->assertSame(['Base'], $t['extends']);
        $this->assertSame(['App\\Contracts\\Foo', 'Illuminate\\Support\\Str'], $out['imports']);
    }

    public function test_interface_extending_multiple_parents(): void
    {
        $src = "<?php\nnamespace App\\Contracts;\ninterface Repo extends Readable, Writable {\n  public function find(int \$id): mixed;\n}\n";

        $out = $this->ex()->extract($src);

        $this->assertSame('interface', $out['types'][0]['kind']);
        $this->assertSame(['Readable', 'Writable'], $out['types'][0]['extends']);
        $this->assertSame(['find'], $out['types'][0]['public_methods']);
    }

    public function test_trait_and_enum_kinds(): void
    {
        $trait = $this->ex()->extract("<?php\nnamespace App;\ntrait T { public function m(): void {} }\n");
        $enum = $this->ex()->extract("<?php\nnamespace App;\nenum Status: string { case A = 'a'; public function label(): string { return 'x'; } }\n");

        $this->assertSame('trait', $trait['types'][0]['kind']);
        $this->assertSame('enum', $enum['types'][0]['kind']);
        $this->assertSame(['label'], $enum['types'][0]['public_methods']);
    }

    public function test_a_parse_failure_degrades_to_empty_unparsed_surface(): void
    {
        // Invalid PHP (extends after implements) — must NOT throw; reports parsed=false.
        $out = $this->ex()->extract("<?php\nclass X implements Y extends Z {}\n");

        $this->assertFalse($out['parsed']);
        $this->assertSame([], $out['types']);
    }

    public function test_blank_source_is_empty(): void
    {
        $out = $this->ex()->extract('   ');
        $this->assertFalse($out['parsed']);
        $this->assertSame([], $out['types']);
    }

    public function test_anonymous_class_has_no_stable_exported_identity(): void
    {
        $out = $this->ex()->extract("<?php\nnamespace App;\n\$x = new class { public function a(): void {} };\n");
        // The anonymous class carries no stable name → not reported as an exported type.
        $this->assertSame([], $out['types']);
        $this->assertTrue($out['parsed']);
    }

    public function test_extracts_service_locator_class_refs_the_import_census_misses(): void
    {
        // Dependency reached via the CONTAINER STRING, not a use-import — must be captured so a forbidden
        // edge hidden behind app()/resolve()/App::make is still AST-visible.
        $src = "<?php\nnamespace App\\Support;\nclass H {\n"
            ."  public function a() { return app(\\App\\Services\\Hub::class); }\n"
            ."  public function b() { return resolve('App\\\\Services\\\\Other'); }\n"
            ."  public function c() { return \\App::make(Thing::class); }\n}\n";

        $out = $this->ex()->extract($src);

        $this->assertContains('App\\Services\\Hub', $out['service_refs']);
        $this->assertContains('App\\Services\\Other', $out['service_refs']);
        $this->assertContains('Thing', $out['service_refs']);
        $this->assertSame([], $out['imports'], 'none of these are use-imports — purely container strings');
    }
}
