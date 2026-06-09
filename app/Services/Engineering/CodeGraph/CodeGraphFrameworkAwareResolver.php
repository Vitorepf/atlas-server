<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * AP-815 · P-7 (precision keystone) — framework-aware edge extractor.
 *
 * Generic AST/import parsing reconstructs the STATIC skeleton of a codebase
 * (a literal `use`, a `new Foo`, a method call). It is blind to the edges a
 * framework wires at RUNTIME by convention rather than by literal reference —
 * the "magic":
 *
 *   - A route maps an HTTP verb+URI to a controller action. The binding lives in
 *     a fluent `Route::get(...)` call (often an array `[Controller::class,'m']`)
 *     and is only fully resolved by the router; a generic parser sees a string
 *     literal, not the verb→action edge.
 *   - A container binding maps an abstract (interface) to a concrete (impl). The
 *     edge `interface -> impl` exists only in the service provider's `bind()`
 *     call and is realised by the container; static analysis cannot follow it.
 *   - An Eloquent relation maps a model to a related model. The related class is
 *     an argument to `$this->hasMany(Related::class)`; the method's *type* says
 *     only "this returns a HasMany", so the model→related edge is a runtime fact.
 *
 * This resolver recovers those three edge families using the framework's OWN
 * runtime APIs + reflection. That is why P-7 is [native], not [py]: introspecting
 * a PHP framework's router/container/ORM is only reliable IN PHP. NEVER re-parse
 * PHP in Python to guess DI bindings, routes, or relations — you would re-derive,
 * with lower fidelity, what the framework already knows authoritatively.
 *
 * Output contract — every method returns a list of edges shaped:
 *
 *   [
 *     'from_node_id' => string,   // e.g. 'route:GET /users'  or  'sym:App\Foo'
 *     'to_node_id'   => string,   // e.g. 'sym:App\UserController::index'
 *     'edge_type'    => string,   // 'route_handles' | 'di_binds' | 'eloquent_relation'
 *     'confidence'   => 'extracted', // these are PROVEN by the framework, never guessed
 *     'metadata'     => array<string,mixed>,
 *   ]
 *
 * Determinism & fail-safety (house contract — mirrors {@see CodeGraphInferredGuard}):
 *   - Pure of DB WRITES and of the clock/random. It only READS framework metadata
 *     (the route table, container bindings) and reflects class structure. Eloquent
 *     relation extraction is type-reflection first and, when a relation must be
 *     materialised to learn its related model, constructs the relation object only
 *     (which provably does NOT touch the DB) — it never calls ->get()/->first() or
 *     anything that issues a query.
 *   - NEVER throws. Every framework probe and every reflection is wrapped so a
 *     missing router, an unbootstrapped container, a malformed binding, or a model
 *     whose method blows up degrades to "skip this row", never to an exception.
 *     A resolver that cannot reason about something emits nothing for it.
 *   - Deterministic output: every method sorts its edges by a TOTAL key
 *     (from_node_id, then to_node_id, then edge_type) and de-duplicates, so the
 *     same framework state always yields byte-identical output regardless of the
 *     iteration order the router/container hand back.
 *
 * Confidence is always 'extracted': these edges are observed from the framework's
 * authoritative runtime model, so the anti-over-claim guard treats them as sacred.
 */
class CodeGraphFrameworkAwareResolver
{
    public const SCHEMA = 'atlas.code_graph.framework_aware.v1';

    public const EDGE_ROUTE_HANDLES = 'route_handles';

    public const EDGE_DI_BINDS = 'di_binds';

    public const EDGE_ELOQUENT_RELATION = 'eloquent_relation';

    /** Confidence grade for every framework-observed edge (proven, not guessed). */
    public const CONFIDENCE = 'extracted';

    /**
     * The world-model node_id varchar limit the rest of the graph honours. Long
     * symbol ids keep a readable tail plus a stable hash so they stay unique.
     */
    private const NODE_ID_MAX = 160;

    /**
     * Route → controller-action edges, read from the live router's route table.
     *
     * For every route bound to a controller action (NOT a closure), emits one edge
     * per distinct HTTP method:
     *
     *   route:<METHOD> <uri>  --route_handles-->  sym:<FQCN>::<method>
     *
     * Invokable single-action controllers (`Route::get($uri, Controller::class)`)
     * resolve to `sym:<FQCN>::__invoke`. Closure routes carry no symbol target and
     * are skipped. HEAD (auto-added alongside GET) is dropped so the edge set is the
     * meaningful verb surface, not framework bookkeeping.
     *
     * This is the reliable core of P-7 and the primary test target.
     *
     * @return list<array<string,mixed>>
     */
    public function routeEdges(): array
    {
        $routes = $this->routeTable();
        if ($routes === []) {
            return [];
        }

        $edges = [];

        foreach ($routes as $route) {
            $edge = $this->edgeForRoute($route);
            if ($edge !== null) {
                $edges[] = $edge;
            }
        }

        return $this->finalize($edges);
    }

    /**
     * Container binding edges (abstract → concrete), best-effort.
     *
     * When code binds a class-string concrete — `$app->bind(Iface::class, Impl::class)`
     * or `$app->singleton(...)` — Laravel wraps the concrete in a generated closure
     * whose static variables expose the original `abstract` and `concrete` strings.
     * We recover those, and emit:
     *
     *   sym:<abstract>  --di_binds-->  sym:<concrete>
     *
     * only when the concrete is a resolvable class string. Bindings registered with
     * a real factory closure — `$app->bind(Foo::class, fn () => new Foo(...))` —
     * expose no such strings (the factory is opaque), so they are skipped: the
     * resolver only claims an edge it can prove points at a concrete class. Self
     * bindings (abstract === concrete) are dropped as non-edges.
     *
     * Defensive throughout: an unbootstrapped/absent container, a binding without
     * the expected shape, or a reflection failure all degrade to "skip", never throw.
     *
     * @return list<array<string,mixed>>
     */
    public function containerEdges(): array
    {
        $bindings = $this->containerBindings();
        if ($bindings === []) {
            return [];
        }

        $edges = [];

        foreach ($bindings as $abstract => $binding) {
            $concrete = $this->concreteClassForBinding($binding);
            if ($concrete === null) {
                continue;
            }

            $abstractName = $this->normalizeClassName((string) $abstract);
            if ($abstractName === '' || $abstractName === $concrete) {
                // Empty/degenerate abstract, or a self-binding (no real edge).
                continue;
            }

            $edges[] = $this->edge(
                $this->symbolNodeId($abstractName),
                $this->symbolNodeId($concrete),
                self::EDGE_DI_BINDS,
                [
                    'abstract' => $abstractName,
                    'concrete' => $concrete,
                ],
            );
        }

        return $this->finalize($edges);
    }

    /**
     * Eloquent relation edges (model → related model), best-effort.
     *
     * For each given Eloquent model FQCN, reflects its public methods and keeps
     * those that look like a relation accessor: no required parameters and a
     * declared return TYPE that is an
     * {@see \Illuminate\Database\Eloquent\Relations\Relation} subclass
     * (HasMany, BelongsTo, MorphMany, …). For each, emits:
     *
     *   sym:<Model>  --eloquent_relation-->  sym:<Related>
     *
     * with the relation kind (the short type name) in metadata.
     *
     * Resolving the RELATED class is done type-first: if the method docblock
     * carries a generic return (`@return HasMany<App\Models\Post>`) the related
     * class is read statically with zero invocation. Real models, however, usually
     * type only the relation (`public function posts(): HasMany`), so the related
     * class lives in the method body's `hasMany(Post::class)` argument. To recover
     * it we construct the relation object on a FRESH, unsaved model instance and
     * read its related model — relation CONSTRUCTION provably does not issue a
     * query (only ->get()/->first()/lazy access would), so this honours
     * "never hit the DB". Any method that cannot be safely introspected — an
     * abstract model, a constructor that needs arguments, a method that throws, a
     * related class that cannot be resolved — is skipped. Passing no models (or a
     * non-model class) returns [] safely.
     *
     * @param  array<int,mixed>  $modelClasses  Eloquent model FQCNs to introspect.
     * @return list<array<string,mixed>>
     */
    public function eloquentEdges(array $modelClasses): array
    {
        $edges = [];

        foreach ($modelClasses as $modelClass) {
            foreach ($this->relationEdgesForModel($modelClass) as $edge) {
                $edges[] = $edge;
            }
        }

        return $this->finalize($edges);
    }

    /**
     * Merge of every framework edge family: routes ∪ container ∪ eloquent.
     *
     * The combined set is sorted + de-duplicated by the same total key, so a single
     * call yields the complete, deterministic framework-magic edge surface.
     *
     * @param  array<int,mixed>  $modelClasses  Eloquent model FQCNs (default none).
     * @return list<array<string,mixed>>
     */
    public function all(array $modelClasses = []): array
    {
        $edges = array_merge(
            $this->routeEdges(),
            $this->containerEdges(),
            $this->eloquentEdges($modelClasses),
        );

        return $this->finalize($edges);
    }

    // ---------------------------------------------------------------------
    // Route extraction
    // ---------------------------------------------------------------------

    /**
     * The live route collection as a plain list, or [] if the router is absent.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    private function routeTable(): array
    {
        try {
            if (! function_exists('app') || ! app()->bound('router')) {
                return [];
            }

            $router = app('router');
            if (! is_object($router) || ! method_exists($router, 'getRoutes')) {
                return [];
            }

            $collection = $router->getRoutes();
            if (! is_iterable($collection)) {
                return [];
            }

            $routes = [];
            foreach ($collection as $route) {
                if (is_object($route)) {
                    $routes[] = $route;
                }
            }

            return $routes;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Build the single edge for one route, or null when it is a closure / has no
     * resolvable controller action.
     *
     * @param  object  $route  an \Illuminate\Routing\Route
     * @return array<string,mixed>|null
     */
    private function edgeForRoute(object $route): ?array
    {
        try {
            $controller = $this->routeControllerClass($route);
            if ($controller === null) {
                return null; // closure route or unresolvable target
            }

            $method = $this->routeActionMethod($route, $controller);
            $uri = $this->routeUri($route);
            if ($uri === '') {
                return null;
            }

            $verbs = $this->routeVerbs($route);
            if ($verbs === []) {
                return null;
            }

            // One route can answer several verbs (e.g. GET|POST). Emit a node id
            // covering the full verb set, joined deterministically; per-verb fan-out
            // would duplicate the identical handler edge under sibling node ids.
            $verbLabel = implode('|', $verbs);

            $target = $controller.'::'.$method;

            return $this->edge(
                $this->routeNodeId($verbLabel, $uri),
                $this->symbolNodeId($target),
                self::EDGE_ROUTE_HANDLES,
                [
                    'methods' => $verbs,
                    'uri' => $uri,
                    'controller' => $controller,
                    'action' => $method,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * FQCN of the controller a route targets, or null for closures / no controller.
     *
     * @param  object  $route  an \Illuminate\Routing\Route
     */
    private function routeControllerClass(object $route): ?string
    {
        // Primary: the router's own resolution (null for closures).
        if (method_exists($route, 'getControllerClass')) {
            $class = $route->getControllerClass();
            if (is_string($class)) {
                $class = $this->normalizeClassName($class);
                if ($class !== '' && class_exists($class)) {
                    return $class;
                }
            }
        }

        // Fallback: parse the 'Class@method' / 'Class' action name; 'Closure' → null.
        if (method_exists($route, 'getActionName')) {
            $action = $route->getActionName();
            if (is_string($action) && $action !== '' && $action !== 'Closure') {
                $class = $this->normalizeClassName(explode('@', $action, 2)[0]);
                if ($class !== '' && class_exists($class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * The controller method a route invokes. Defaults to '__invoke' for invokable
     * single-action controllers (where the router reports the class name itself as
     * the "method").
     *
     * @param  object  $route  an \Illuminate\Routing\Route
     */
    private function routeActionMethod(object $route, string $controller): string
    {
        $method = '';
        if (method_exists($route, 'getActionMethod')) {
            $raw = $route->getActionMethod();
            if (is_string($raw)) {
                $method = trim($raw);
            }
        }

        // Invokable controllers: the router echoes the FQCN as the action method.
        if ($method === '' || $this->normalizeClassName($method) === $controller) {
            return '__invoke';
        }

        return $method;
    }

    /**
     * @param  object  $route  an \Illuminate\Routing\Route
     */
    private function routeUri(object $route): string
    {
        if (! method_exists($route, 'uri')) {
            return '';
        }
        $uri = $route->uri();
        if (! is_string($uri)) {
            return '';
        }
        $uri = trim($uri);
        // Canonical leading slash; collapse the framework's root '/' marker.
        $uri = '/'.ltrim($uri, '/');

        return $uri;
    }

    /**
     * The route's HTTP verbs, HEAD dropped, upper-cased, de-duplicated, sorted.
     *
     * @param  object  $route  an \Illuminate\Routing\Route
     * @return list<string>
     */
    private function routeVerbs(object $route): array
    {
        if (! method_exists($route, 'methods')) {
            return [];
        }
        $methods = $route->methods();
        if (! is_array($methods)) {
            return [];
        }

        $verbs = [];
        foreach ($methods as $method) {
            if (! is_string($method)) {
                continue;
            }
            $verb = strtoupper(trim($method));
            if ($verb === '' || $verb === 'HEAD') {
                continue;
            }
            $verbs[$verb] = true;
        }

        $verbs = array_keys($verbs);
        sort($verbs, SORT_STRING);

        return $verbs;
    }

    // ---------------------------------------------------------------------
    // Container extraction
    // ---------------------------------------------------------------------

    /**
     * The container's raw bindings map, or [] when the container is unavailable.
     *
     * @return array<string,mixed>
     */
    private function containerBindings(): array
    {
        try {
            if (! function_exists('app')) {
                return [];
            }
            $container = app();
            if (! is_object($container) || ! method_exists($container, 'getBindings')) {
                return [];
            }
            $bindings = $container->getBindings();

            return is_array($bindings) ? $bindings : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Resolve the concrete class string for one binding, or null when it is not a
     * class-string binding we can prove (factory closures, unresolvable strings).
     *
     * @param  mixed  $binding  the container's binding entry (expected ['concrete'=>Closure,...])
     */
    private function concreteClassForBinding(mixed $binding): ?string
    {
        try {
            if (! is_array($binding) || ! array_key_exists('concrete', $binding)) {
                return null;
            }

            $concrete = $binding['concrete'];

            // Laravel wraps a class-string concrete in a closure carrying the
            // original 'abstract'/'concrete' strings as static variables.
            if ($concrete instanceof \Closure) {
                $statics = (new ReflectionFunction($concrete))->getStaticVariables();
                $candidate = $statics['concrete'] ?? null;
                if (! is_string($candidate)) {
                    // A genuine factory closure (no embedded class string) — skip.
                    return null;
                }
                $concrete = $candidate;
            }

            if (! is_string($concrete)) {
                return null;
            }

            $class = $this->normalizeClassName($concrete);

            return ($class !== '' && class_exists($class)) ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Eloquent extraction
    // ---------------------------------------------------------------------

    /**
     * Relation edges for one model FQCN. Returns [] for anything that is not a
     * concrete, instantiable Eloquent model, or when nothing can be introspected.
     *
     * @param  mixed  $modelClass
     * @return list<array<string,mixed>>
     */
    private function relationEdgesForModel(mixed $modelClass): array
    {
        try {
            if (! is_string($modelClass)) {
                return [];
            }
            $class = $this->normalizeClassName($modelClass);
            if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                return [];
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                return [];
            }

            $edges = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $edge = $this->relationEdgeForMethod($class, $reflection, $method);
                if ($edge !== null) {
                    $edges[] = $edge;
                }
            }

            return $edges;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Build the relation edge for a single candidate method, or null if it is not a
     * (safely introspectable) relation accessor.
     *
     * @return array<string,mixed>|null
     */
    private function relationEdgeForMethod(string $modelClass, ReflectionClass $reflection, ReflectionMethod $method): ?array
    {
        try {
            // Only methods DECLARED on the model itself (skip inherited Eloquent
            // internals like ->newQuery()) and only true instance accessors.
            if ($method->getDeclaringClass()->getName() !== $modelClass) {
                return null;
            }
            if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfRequiredParameters() > 0) {
                return null;
            }
            if (str_starts_with($method->getName(), '__')) {
                return null; // magic methods are never relations
            }

            $returnType = $method->getReturnType();
            if (! $returnType instanceof ReflectionNamedType
                || $returnType->isBuiltin()
                || ! $this->isRelationType($returnType->getName())) {
                return null;
            }

            $relationKind = $this->shortName($returnType->getName());

            $related = $this->relatedModelClass($modelClass, $reflection, $method);
            if ($related === null) {
                return null; // could not learn the related class safely → skip
            }

            return $this->edge(
                $this->symbolNodeId($modelClass),
                $this->symbolNodeId($related),
                self::EDGE_ELOQUENT_RELATION,
                [
                    'relation' => $method->getName(),
                    'kind' => $relationKind,
                    'related' => $related,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the related model FQCN for a relation method, type-first then by
     * DB-safe relation construction. Returns null when it cannot be learnt safely.
     */
    private function relatedModelClass(string $modelClass, ReflectionClass $reflection, ReflectionMethod $method): ?string
    {
        // 1) Zero-invocation: a generic return docblock — @return HasMany<App\Post>.
        $fromDoc = $this->relatedFromDocblock($method);
        if ($fromDoc !== null) {
            return $fromDoc;
        }

        // 2) DB-safe construction: build the relation on a fresh, unsaved instance
        //    and read its related model. Constructing a relation does NOT issue a
        //    query; only materialising it (->get()/->first()) would. Wrapped so any
        //    side-effecting method body degrades to "skip".
        return $this->relatedFromRelationConstruction($modelClass, $reflection, $method);
    }

    /**
     * Parse a `@return Relation<Related>` generic from the method docblock, if any.
     * Pure text — never invokes the method.
     */
    private function relatedFromDocblock(ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();
        if (! is_string($doc) || $doc === '') {
            return null;
        }

        // Match the first generic argument of a @return ...<...>; tolerate leading
        // backslashes and extra generic args (HasManyThrough<A, B>).
        if (preg_match('/@return\s+[^<\s]+<\s*([^,>\s]+)/', $doc, $m) !== 1) {
            return null;
        }

        $candidate = $this->normalizeClassName($m[1]);
        if ($candidate === '' || ! class_exists($candidate) || ! is_subclass_of($candidate, Model::class)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Construct the relation on a fresh model instance and read its related model.
     * Returns null on any failure. DB-safe: relation construction issues no query.
     */
    private function relatedFromRelationConstruction(string $modelClass, ReflectionClass $reflection, ReflectionMethod $method): ?string
    {
        try {
            // Only instantiate when the constructor needs no required arguments;
            // a model with a mandatory-arg constructor is not a safe target.
            $ctor = $reflection->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            /** @var Model $instance */
            $instance = $reflection->newInstance();

            $relation = $method->invoke($instance);
            if (! $relation instanceof Relation) {
                return null;
            }

            $related = $relation->getRelated();
            if (! $related instanceof Model) {
                return null;
            }

            $class = $this->normalizeClassName($related::class);

            return $class !== '' ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a class name is an Eloquent Relation subclass (or the base itself).
     */
    private function isRelationType(string $class): bool
    {
        $class = $this->normalizeClassName($class);
        if ($class === '' || ! class_exists($class)) {
            return false;
        }

        return $class === Relation::class || is_subclass_of($class, Relation::class);
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

    /**
     * Assemble one edge in the canonical shape.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function edge(string $from, string $to, string $edgeType, array $metadata): array
    {
        ksort($metadata);

        return [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $edgeType,
            'confidence' => self::CONFIDENCE,
            'metadata' => $metadata,
        ];
    }

    /**
     * De-duplicate by the full edge identity (from, to, type) and sort by a TOTAL
     * key so output is byte-identical regardless of framework iteration order.
     *
     * @param  list<array<string,mixed>>  $edges
     * @return list<array<string,mixed>>
     */
    private function finalize(array $edges): array
    {
        $byKey = [];
        foreach ($edges as $edge) {
            $key = ((string) ($edge['from_node_id'] ?? ''))
                ."\0".((string) ($edge['to_node_id'] ?? ''))
                ."\0".((string) ($edge['edge_type'] ?? ''));
            // First write wins; identical (from,to,type) edges are the same fact.
            $byKey[$key] ??= $edge;
        }

        $unique = array_values($byKey);

        usort($unique, static function (array $a, array $b): int {
            return ((string) ($a['from_node_id'] ?? '')) <=> ((string) ($b['from_node_id'] ?? ''))
                ?: ((string) ($a['to_node_id'] ?? '')) <=> ((string) ($b['to_node_id'] ?? ''))
                ?: ((string) ($a['edge_type'] ?? '')) <=> ((string) ($b['edge_type'] ?? ''));
        });

        return $unique;
    }

    /**
     * Deterministic node id for a route, e.g. "route:GET /users". Verb+uri together
     * uniquely identify the route surface.
     */
    private function routeNodeId(string $verbLabel, string $uri): string
    {
        return 'route:'.$verbLabel.' '.$uri;
    }

    /**
     * Deterministic node id for a symbol FQN/target, kept within the world-model
     * node_id varchar(160) limit. Long ids keep their readable tail plus a stable
     * hash so they remain unique and collision-safe (mirrors the symbol resolver).
     */
    private function symbolNodeId(string $fqn): string
    {
        $fqn = $this->normalizeClassName($fqn);
        $id = 'sym:'.$fqn;
        if (strlen($id) <= self::NODE_ID_MAX) {
            return $id;
        }

        return 'sym:'.substr($fqn, -130).'#'.substr(sha1($fqn), 0, 12);
    }

    /**
     * Normalize a class/abstract string: drop a leading backslash, trim. Keeps the
     * namespace + class (+ ::method for action targets). Non-strings → ''.
     */
    private function normalizeClassName(string $value): string
    {
        return ltrim(trim($value), '\\');
    }

    /** The short (unqualified) name of a class, e.g. HasMany from the FQCN. */
    private function shortName(string $class): string
    {
        $class = $this->normalizeClassName($class);
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
