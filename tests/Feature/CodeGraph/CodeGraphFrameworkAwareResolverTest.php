<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphFrameworkAwareResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AP-815 · P-7 — framework-aware edge extractor.
 *
 * Boots the app (the resolver reads the LIVE router + container and reflects
 * model relations — the "magic" generic AST cannot see). Proves the three
 * extracted edge families plus the resolver's house contract (deterministic,
 * fail-safe).
 */
class CodeGraphFrameworkAwareResolverTest extends TestCase
{
    private function resolver(): CodeGraphFrameworkAwareResolver
    {
        return new CodeGraphFrameworkAwareResolver;
    }

    /**
     * Happy path (the core target): a registered controller route is recovered as a
     * `route:<VERB> <uri> --route_handles--> sym:<Controller>::index` edge, graded
     * 'extracted', with the verb + action in metadata.
     */
    public function test_route_edges_extract_controller_action_handler(): void
    {
        Route::get('ap815-test', [Ap815TestController::class, 'index']);

        $edges = $this->resolver()->routeEdges();

        $match = $this->findEdge(
            $edges,
            'route:GET /ap815-test',
            'sym:'.Ap815TestController::class.'::index',
        );

        $this->assertNotNull($match, 'Expected a route_handles edge for the registered controller route.');
        $this->assertSame(CodeGraphFrameworkAwareResolver::EDGE_ROUTE_HANDLES, $match['edge_type']);
        $this->assertSame('extracted', $match['confidence']);
        $this->assertSame(['GET'], $match['metadata']['methods']);
        $this->assertSame('index', $match['metadata']['action']);
        $this->assertSame(Ap815TestController::class, $match['metadata']['controller']);
    }

    /**
     * Edge case: an invokable single-action controller resolves to ::__invoke (the
     * router reports the class name as the "method"), and a CLOSURE route is skipped
     * entirely (no symbol target exists to point at).
     */
    public function test_route_edges_handle_invokable_and_skip_closures(): void
    {
        Route::get('ap815-invoke', Ap815InvokableController::class);
        Route::get('ap815-closure', fn () => 'noop');

        $edges = $this->resolver()->routeEdges();

        $invoke = $this->findEdge(
            $edges,
            'route:GET /ap815-invoke',
            'sym:'.Ap815InvokableController::class.'::__invoke',
        );
        $this->assertNotNull($invoke, 'Invokable controller must resolve to ::__invoke.');

        $closureEdges = array_filter(
            $edges,
            static fn (array $e): bool => $e['from_node_id'] === 'route:GET /ap815-closure',
        );
        $this->assertSame([], array_values($closureEdges), 'Closure routes must produce no edge.');
    }

    /**
     * Container: an interface bound to a concrete implementation is recovered as a
     * `sym:<Iface> --di_binds--> sym:<Impl>` edge. Proven from the binding's wrapper
     * closure — the edge a static parser cannot follow.
     */
    public function test_container_edges_extract_interface_to_implementation_binding(): void
    {
        $this->app->bind(Ap815ServiceContract::class, Ap815ServiceImpl::class);

        $edges = $this->resolver()->containerEdges();

        $match = $this->findEdge(
            $edges,
            'sym:'.Ap815ServiceContract::class,
            'sym:'.Ap815ServiceImpl::class,
        );

        $this->assertNotNull($match, 'Expected a di_binds edge for the abstract→concrete binding.');
        $this->assertSame(CodeGraphFrameworkAwareResolver::EDGE_DI_BINDS, $match['edge_type']);
        $this->assertSame('extracted', $match['confidence']);
        $this->assertSame(Ap815ServiceImpl::class, $match['metadata']['concrete']);
    }

    /**
     * Container edge case: a binding registered with a genuine factory CLOSURE
     * (no embedded class string) is NOT claimed — the resolver only emits a
     * di_binds edge it can prove points at a concrete class.
     */
    public function test_container_edges_skip_factory_closure_bindings(): void
    {
        $this->app->bind('ap815.factory.binding', fn (): Ap815ServiceImpl => new Ap815ServiceImpl);

        $edges = $this->resolver()->containerEdges();

        $leaked = array_filter(
            $edges,
            static fn (array $e): bool => $e['from_node_id'] === 'sym:ap815.factory.binding',
        );
        $this->assertSame([], array_values($leaked), 'Factory-closure bindings must not produce a di_binds edge.');
    }

    /**
     * Eloquent: a model with a `: HasMany`-typed relation method yields a
     * `sym:<Model> --eloquent_relation--> sym:<Related>` edge. The related class is
     * recovered via DB-safe relation construction (no query issued).
     */
    public function test_eloquent_edges_extract_typed_relation(): void
    {
        $edges = $this->resolver()->eloquentEdges([Ap815ParentModel::class]);

        $match = $this->findEdge(
            $edges,
            'sym:'.Ap815ParentModel::class,
            'sym:'.Ap815ChildModel::class,
        );

        $this->assertNotNull($match, 'Expected an eloquent_relation edge model→related.');
        $this->assertSame(CodeGraphFrameworkAwareResolver::EDGE_ELOQUENT_RELATION, $match['edge_type']);
        $this->assertSame('extracted', $match['confidence']);
        $this->assertSame('children', $match['metadata']['relation']);
        $this->assertSame('HasMany', $match['metadata']['kind']);

        // Non-relation public methods (an accessor, a scope with a required param)
        // must NOT produce edges.
        $this->assertCount(1, $edges, 'Only the single relation method should yield an edge.');
    }

    /**
     * Eloquent edge cases: empty input is safe ([]), and a non-model class string is
     * ignored (best-effort, never throws). Documents the best-effort nature.
     */
    public function test_eloquent_edges_are_safe_for_empty_and_non_model_input(): void
    {
        $this->assertSame([], $this->resolver()->eloquentEdges([]));
        $this->assertSame([], $this->resolver()->eloquentEdges([self::class, 'NotARealClass\\Nope']));
    }

    /**
     * Determinism: two calls over identical framework state return byte-identical,
     * sorted, de-duplicated output — for each family and for the merged all().
     */
    public function test_output_is_deterministic_and_merged_by_all(): void
    {
        Route::get('ap815-determinism', [Ap815TestController::class, 'index']);
        $this->app->bind(Ap815ServiceContract::class, Ap815ServiceImpl::class);

        $models = [Ap815ParentModel::class];

        $this->assertSame($this->resolver()->routeEdges(), $this->resolver()->routeEdges());
        $this->assertSame($this->resolver()->containerEdges(), $this->resolver()->containerEdges());
        $this->assertSame(
            $this->resolver()->eloquentEdges($models),
            $this->resolver()->eloquentEdges($models),
        );

        $all = $this->resolver()->all($models);
        $this->assertSame($all, $this->resolver()->all($models), 'all() must be deterministic.');

        // Sorted ascending by from_node_id (total order).
        $froms = array_column($all, 'from_node_id');
        $sorted = $froms;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $froms, 'all() edges must be sorted by from_node_id.');

        // all() contains the union of the families.
        $this->assertNotNull($this->findEdge($all, 'route:GET /ap815-determinism', 'sym:'.Ap815TestController::class.'::index'));
        $this->assertNotNull($this->findEdge($all, 'sym:'.Ap815ServiceContract::class, 'sym:'.Ap815ServiceImpl::class));
        $this->assertNotNull($this->findEdge($all, 'sym:'.Ap815ParentModel::class, 'sym:'.Ap815ChildModel::class));
    }

    /**
     * Find the single edge matching (from,to), or null.
     *
     * @param  list<array<string,mixed>>  $edges
     * @return array<string,mixed>|null
     */
    private function findEdge(array $edges, string $from, string $to): ?array
    {
        foreach ($edges as $edge) {
            if (($edge['from_node_id'] ?? null) === $from && ($edge['to_node_id'] ?? null) === $to) {
                return $edge;
            }
        }

        return null;
    }
}

/**
 * Throwaway controller with a named action.
 */
class Ap815TestController
{
    public function index(Request $request): string
    {
        return 'ok';
    }
}

/**
 * Throwaway invokable single-action controller.
 */
class Ap815InvokableController
{
    public function __invoke(Request $request): string
    {
        return 'ok';
    }
}

interface Ap815ServiceContract {}

class Ap815ServiceImpl implements Ap815ServiceContract {}

/**
 * Tiny in-test parent model with a typed hasMany relation plus two non-relation
 * public methods that MUST be ignored by the extractor.
 */
class Ap815ParentModel extends Model
{
    protected $table = 'ap815_parents';

    public function children(): HasMany
    {
        return $this->hasMany(Ap815ChildModel::class);
    }

    /** A query scope: has a required param → not a relation accessor. */
    public function scopeActive($query)
    {
        return $query;
    }

    /** An accessor: no relation return type → ignored. */
    public function getDisplayNameAttribute(): string
    {
        return 'name';
    }
}

class Ap815ChildModel extends Model
{
    protected $table = 'ap815_children';
}
