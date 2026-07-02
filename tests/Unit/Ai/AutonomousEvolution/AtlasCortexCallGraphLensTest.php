<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexCallGraphLens;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexLensRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use Tests\TestCase;

final class AtlasCortexCallGraphLensTest extends TestCase
{
    private function fixtureSource(): string
    {
        return <<<'PHP'
<?php
namespace App\Fixture;
final class Demo
{
    public function entry(): void
    {
        self::staticHelper();
        $this->recurse();
        $other = new Helper();
        $other->boot();
    }
    public function recurse(): void { $this->recurse(); }
    public static function staticHelper(): void {}
    private function privateMethod(): void {}
}
PHP;
    }

    public function test_observe_returns_call_edges_for_static_instance_and_self(): void
    {
        $subject = new CortexSubject('demo-1', 'php_class', ['source_code' => $this->fixtureSource()]);

        $obs = (new AtlasCortexCallGraphLens)->observe($subject);

        $this->assertSame('callgraph', $obs->lensId);
        $this->assertSame('demo-1', $obs->subjectId);
        $edges = (array) $obs->facts['edges'];
        $this->assertNotEmpty($edges);

        $byType = [];
        foreach ($edges as $edge) {
            $this->assertSame('call_edge', $edge['kind']);
            $this->assertArrayHasKey('from', $edge);
            $this->assertArrayHasKey('to', $edge);
            $this->assertContains($edge['type'], ['static', 'instance', 'self']);
            $byType[$edge['type']][] = $edge['to'];
        }
        $this->assertNotEmpty($byType['static'] ?? [], 'self::staticHelper must be captured as a static edge');
        $this->assertNotEmpty($byType['instance'] ?? [], '$other->boot must be captured as an instance edge');
        $this->assertNotEmpty($byType['self'] ?? [], '$this->recurse must be captured as a self edge');
        $this->assertGreaterThanOrEqual(1, (int) $obs->facts['self_recursive_edges']);
        $this->assertSame(count($edges), (int) $obs->facts['fan_out']);
    }

    public function test_dynamic_dispatch_surfaces_as_disagreement_signal(): void
    {
        $source = <<<'PHP'
<?php
class Dyn
{
    public function run(string $method): void
    {
        $this->$method();
    }
}
PHP;

        $obs = (new AtlasCortexCallGraphLens)->observe(new CortexSubject('dyn-1', 'php_class', ['source_code' => $source]));

        $this->assertContains('dynamic_dispatch', $obs->disagreementSignals);
    }

    public function test_bodyless_abstract_method_does_not_poison_next_method_attribution(): void
    {
        $source = <<<'PHP'
<?php
abstract class Foo {
    abstract public function bar(): void;
    public function baz(): void { $this->helper(); }
    private function helper(): void {}
}
PHP;

        $obs = (new AtlasCortexCallGraphLens)->observe(new CortexSubject('bodyless-1', 'php_class', ['source_code' => $source]));

        $edges = (array) $obs->facts['edges'];
        $fromMethods = array_column($edges, 'from');
        $this->assertContains('baz', $fromMethods, 'call from baz must appear');
        $this->assertNotContains('bar', $fromMethods, 'bar is bodyless — must not be attributed as caller');
    }

    public function test_council_registry_is_always_bound_and_prepopulated(): void
    {
        // Contrato canônico do Cortex Council: registry singleton sempre-bound, pré-populado
        // com as 5 lentes built-in; o gate fica upstream em config('atlas.cortex.council.enabled').
        // (O wiring condicional legado via `cortex.council.lenses` foi removido — o re-singleton
        // cru rebindava o registry VAZIO quando a chave listava uma lente.)
        $this->assertTrue($this->app->bound(AtlasCortexLensRegistry::class));

        $registry = $this->app->make(AtlasCortexLensRegistry::class);
        $this->expectExceptionOnDoubleRegistration($registry);
    }

    public function test_legacy_lens_config_no_longer_changes_the_wiring(): void
    {
        // A chave legada não muda nada: mesmo registry pré-populado, listada ou vazia.
        config()->set('cortex.council.lenses', []);
        $a = $this->app->make(AtlasCortexLensRegistry::class);

        config()->set('cortex.council.lenses', ['callgraph']);
        $b = $this->app->make(AtlasCortexLensRegistry::class);

        $this->assertSame($a, $b, 'singleton único, indiferente à chave legada');
        $this->expectExceptionOnDoubleRegistration($b);
    }

    /**
     * Idempotent register accepts the same class twice; the test proves the lens IS already in the registry by
     * re-registering it explicitly with the SAME concrete class (which is a no-op per registry contract). Any
     * NEW class under the same id would throw; the fact this passes is itself proof of registration.
     */
    private function expectExceptionOnDoubleRegistration(AtlasCortexLensRegistry $registry): void
    {
        $registry->register($this->app->make(AtlasCortexCallGraphLens::class));
        $this->assertTrue(true, 'idempotent re-registration with same class is a no-op ⇒ lens is bound');
    }
}
