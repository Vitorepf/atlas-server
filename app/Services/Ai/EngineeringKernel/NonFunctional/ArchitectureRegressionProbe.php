<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\NonFunctional;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/**
 * Engineering Kernel probe (Obra #3): a PURE, deterministic detector of architectural regression —
 * a newly-added import edge that crosses a forbidden layer boundary.
 *
 * Owns: matching the diff's ADDED import edges (from-namespace => to-namespace) against a forbidden
 * ruleset and reporting the violating edges, so the sovereign floor can block a delivery that rots
 * the layering (e.g. the pure EngineeringKernel floor importing Eloquent).
 * Must never own: the verdict (SovereignHonestyFloor) or reading files (the adapter extracts edges).
 */
final class ArchitectureRegressionProbe
{
    /**
     * Sane defaults, dogfoodable: the sovereign kernel floor is pure by design, so an edge from it
     * into the database/HTTP/console layers is a real regression. Config may add more via
     * atlas.engineering_kernel.forbidden_layer_edges (a list of {from,to} namespace prefixes).
     *
     * @return list<array{from:string,to:string}>
     */
    public static function defaultRules(): array
    {
        return [
            // the pure kernel must stay free of framework I/O
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\', 'to' => 'Illuminate\\Database'],
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\', 'to' => 'App\\Models\\'],
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\', 'to' => 'Illuminate\\Http'],
        ];
    }

    /**
     * Parse raw PHP sources into dependency edges {from, to}. Syntax errors deliberately bubble so
     * callers cannot turn an unsupported program into an implicit clean result.
     *
     * @param  array<string,string>  $sources  map of path => raw PHP source
     * @return list<array{from:string,to:string}>
     */
    public static function edgesFromSources(array $sources): array
    {
        $edges = [];
        $parser = (new ParserFactory)->createForHostVersion();
        foreach ($sources as $source) {
            if (! is_string($source)) {
                continue;
            }
            $statements = $parser->parse($source) ?? [];
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor(new ParentConnectingVisitor);
            $statements = $traverser->traverse($statements);
            foreach ((new NodeFinder)->findInstanceOf($statements, Node\Name::class) as $name) {
                $parent = $name->getAttribute('parent');
                if ($parent instanceof Node\Stmt\Namespace_ && $parent->name === $name) {
                    continue;
                }
                if ($parent instanceof Node\Stmt\GroupUse && $parent->prefix === $name) {
                    continue;
                }
                $namespace = self::containingNamespace($name);
                $group = $parent instanceof Node\Stmt\UseUse ? $parent->getAttribute('parent') : null;
                if ($group instanceof Node\Stmt\GroupUse) {
                    $target = $group->prefix->toString().'\\'.$name->toString();
                } else {
                    $target = $name->getAttribute('resolvedName');
                    $target = $target instanceof Node\Name ? $target->toString() : $name->toString();
                }
                if ($namespace === '' || $target === '' || in_array(strtolower($target), ['self', 'static', 'parent'], true)) {
                    continue;
                }
                $edges[hash('sha256', $namespace."\0".$target)] = ['from' => $namespace, 'to' => ltrim($target, '\\')];
            }
        }

        return array_values($edges);
    }

    private static function containingNamespace(Node $node): string
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof Node\Stmt\Namespace_) {
                return $parent->name?->toString() ?? '';
            }
            $parent = $parent->getAttribute('parent');
        }

        return '';
    }

    /**
     * @param  list<array{from:string,to:string}>  $addedEdges  import edges the diff ADDS
     * @param  list<array{from:string,to:string}>|null  $forbiddenRules  null => defaults
     * @return list<string> human-readable violating edges (empty = clean)
     */
    public static function violations(array $addedEdges, ?array $forbiddenRules = null): array
    {
        $rules = $forbiddenRules ?? self::defaultRules();
        $out = [];
        foreach ($addedEdges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            foreach ($rules as $rule) {
                $rf = (string) ($rule['from'] ?? '');
                $rt = (string) ($rule['to'] ?? '');
                if ($rf !== '' && $rt !== '' && self::namespaceMatches($from, $rf) && self::namespaceMatches($to, $rt)) {
                    $out[] = $from.' -> '.$to.' [forbidden: '.$rf.'* -> '.$rt.'*]';
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function namespaceMatches(string $namespace, string $configuredRoot): bool
    {
        $root = rtrim($configuredRoot, '\\');

        return $root !== '' && ($namespace === $root || str_starts_with($namespace, $root.'\\'));
    }
}
