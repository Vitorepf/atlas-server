<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphTypeFlowResolver;
use Tests\TestCase;

/**
 * [native] P-1 — contract for type-flow resolution of dynamic method calls.
 *
 * Pure (no DB): the resolver parses a PHP source string with nikic/php-parser and emits
 * type-resolved 'calls' edges ONLY when the receiver type is statically certain. The
 * load-bearing invariants proven here:
 *   - a typed property receiver ($this->foo->bar() where `private Foo $foo`) -> EXTRACTED edge to Foo::bar
 *   - an unknown / untyped receiver ($x->bar()) -> counted unresolved, NO edge (anti-over-claim)
 *   - typed param + @var docblock are the other two recognised bases
 *   - a parse error never throws -> empty edges + a note (fail-safe)
 */
class CodeGraphTypeFlowResolverTest extends TestCase
{
    private function resolver(): CodeGraphTypeFlowResolver
    {
        return new CodeGraphTypeFlowResolver;
    }

    /**
     * Core case: a typed (and constructor-promoted) property receiver resolves to a concrete
     * type, while an untyped receiver in the same method is reported as unresolved with no edge.
     */
    public function test_typed_property_resolves_to_edge_and_unknown_receiver_is_unresolved(): void
    {
        $code = <<<'PHP'
        <?php

        namespace App\Demo;

        class Foo
        {
            public function bar(): void {}
        }

        class Service
        {
            private Foo $foo;

            public function handle($mystery): void
            {
                $this->foo->bar();   // resolvable: typed property -> App\Demo\Foo::bar
                $mystery->bar();     // NOT resolvable: untyped param -> unresolved, no edge
            }
        }
        PHP;

        $result = $this->resolver()->resolve($code);

        $this->assertSame(CodeGraphTypeFlowResolver::SCHEMA, $result['schema_version']);

        // Two named method calls seen; exactly one resolved, one unresolved.
        $this->assertSame(2, $result['stats']['calls']);
        $this->assertSame(1, $result['stats']['resolved']);
        $this->assertSame(1, $result['stats']['unresolved']);

        // Exactly one edge, and it is the property-based, certain one.
        $this->assertCount(1, $result['edges']);
        $this->assertSame([
            'from' => 'App\\Demo\\Service::handle',
            'to' => 'App\\Demo\\Foo::bar',
            'confidence' => 'extracted',
            'basis' => 'property',
        ], $result['edges'][0]);

        // Anti-over-claim: the unknown receiver minted NO edge to any ::bar.
        $unknownEdges = array_filter(
            $result['edges'],
            static fn (array $edge): bool => $edge['basis'] !== 'property'
        );
        $this->assertSame([], $unknownEdges);
    }

    /**
     * A constructor-promoted typed property is treated exactly like a declared typed property.
     */
    public function test_constructor_promoted_typed_property_resolves(): void
    {
        $code = <<<'PHP'
        <?php

        namespace App\Demo;

        class Repo
        {
            public function find(): void {}
        }

        class Controller
        {
            public function __construct(private Repo $repo) {}

            public function index(): void
            {
                $this->repo->find();
            }
        }
        PHP;

        $result = $this->resolver()->resolve($code);

        $this->assertSame(1, $result['stats']['resolved']);
        $this->assertSame(0, $result['stats']['unresolved']);
        $this->assertCount(1, $result['edges']);
        $this->assertSame('App\\Demo\\Controller::index', $result['edges'][0]['from']);
        $this->assertSame('App\\Demo\\Repo::find', $result['edges'][0]['to']);
        $this->assertSame('property', $result['edges'][0]['basis']);
        $this->assertSame('extracted', $result['edges'][0]['confidence']);
    }

    /**
     * A typed PARAMETER receiver resolves (basis=param), and import aliasing is honoured
     * so the resolved target is the imported FQN, not the local short name.
     */
    public function test_typed_parameter_receiver_resolves_with_import_fqn(): void
    {
        $code = <<<'PHP'
        <?php

        namespace App\Demo;

        use App\Other\Mailer;

        class Notifier
        {
            public function send(Mailer $mailer): void
            {
                $mailer->deliver();
            }
        }
        PHP;

        $result = $this->resolver()->resolve($code);

        $this->assertSame(1, $result['stats']['resolved']);
        $this->assertSame(0, $result['stats']['unresolved']);
        $this->assertCount(1, $result['edges']);
        $this->assertSame([
            'from' => 'App\\Demo\\Notifier::send',
            'to' => 'App\\Other\\Mailer::deliver',
            'confidence' => 'extracted',
            'basis' => 'param',
        ], $result['edges'][0]);
    }

    /**
     * An `@var` docblock on an inline assignment supplies the receiver type (basis=docblock).
     */
    public function test_var_docblock_receiver_resolves(): void
    {
        $code = <<<'PHP'
        <?php

        namespace App\Demo;

        class Widget
        {
            public function paint(): void {}
        }

        class Painter
        {
            public function run($factory): void
            {
                /** @var Widget $widget */
                $widget = $factory->make();
                $widget->paint();
            }
        }
        PHP;

        $result = $this->resolver()->resolve($code);

        // The @var-typed receiver resolves; $factory->make() stays unresolved (untyped param).
        $docblockEdges = array_values(array_filter(
            $result['edges'],
            static fn (array $edge): bool => $edge['basis'] === 'docblock'
        ));

        $this->assertCount(1, $docblockEdges);
        $this->assertSame([
            'from' => 'App\\Demo\\Painter::run',
            'to' => 'App\\Demo\\Widget::paint',
            'confidence' => 'extracted',
            'basis' => 'docblock',
        ], $docblockEdges[0]);

        // $factory->make() is the one unresolved call.
        $this->assertSame(1, $result['stats']['unresolved']);
    }

    /**
     * Fail-safe: malformed PHP never throws — it degrades to empty edges plus a note.
     */
    public function test_parse_error_is_fail_safe_with_note(): void
    {
        $result = $this->resolver()->resolve('<?php class Broken { public function x( { $this->y->z(); }');

        $this->assertSame([], $result['edges']);
        $this->assertSame(0, $result['stats']['calls']);
        $this->assertSame(0, $result['stats']['resolved']);
        $this->assertSame(0, $result['stats']['unresolved']);
        $this->assertArrayHasKey('note', $result);
        $this->assertNotSame('', $result['note']);
    }

    /**
     * Empty input is handled gracefully (no edges, note present), never a crash.
     */
    public function test_empty_input_returns_note(): void
    {
        $result = $this->resolver()->resolve('   ');

        $this->assertSame([], $result['edges']);
        $this->assertArrayHasKey('note', $result);
    }
}
