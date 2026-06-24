<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Throwable;

final class AtlasLoopFormalInvariantSurvivalProver
{
    /**
     * @param  array<string,mixed>  $ast
     * @return array{
     *   invariant_id:string,
     *   verdict:'survives'|'broken'|'indeterminate',
     *   witness_nodes:list<array<string,mixed>>,
     *   unsupported_reason:?string
     * }
     */
    public function prove(string $invariantId, array $ast, string $preSource, string $postSource): array
    {
        if (! in_array((string) ($ast['type'] ?? ''), ['predicate', 'group', 'binary', 'unary', 'identifier', 'literal'], true)) {
            return $this->result($invariantId, 'indeterminate', [], 'unsupported_ast_node');
        }

        $preMap = $this->symbolMap($preSource);
        $postMap = $this->symbolMap($postSource);
        if ($preMap === null || $postMap === null) {
            return $this->result($invariantId, 'indeterminate', [], 'source_parse_error');
        }

        return $this->proveNode($invariantId, $ast, $preMap, $postMap);
    }

    /**
     * @param  array<string,mixed>  $ast
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $preMap
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $postMap
     * @return array{
     *   invariant_id:string,
     *   verdict:'survives'|'broken'|'indeterminate',
     *   witness_nodes:list<array<string,mixed>>,
     *   unsupported_reason:?string
     * }
     */
    private function proveNode(string $invariantId, array $ast, array $preMap, array $postMap): array
    {
        $type = (string) ($ast['type'] ?? '');
        if ($type === 'group') {
            $child = $ast['children'][0] ?? null;

            return is_array($child)
                ? $this->proveNode($invariantId, $child, $preMap, $postMap)
                : $this->result($invariantId, 'indeterminate', [], 'group_without_child');
        }

        if ($type !== 'predicate') {
            return $this->result($invariantId, 'indeterminate', [], 'unsupported_ast_node');
        }

        $predicate = (string) ($ast['op'] ?? '');
        $children = is_array($ast['children'] ?? null) ? $ast['children'] : [];

        return match ($predicate) {
            'always' => $this->proveAlways($invariantId, $children[0] ?? null, $preMap, $postMap),
            'never' => $this->proveNever($invariantId, $children[0] ?? null, $preMap, $postMap),
            'implies' => $this->proveImplies($invariantId, $children, $preMap, $postMap),
            default => $this->result($invariantId, 'indeterminate', [], 'unsupported_predicate'),
        };
    }

    /**
     * @param  array<string,mixed>|null  $child
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $preMap
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $postMap
     * @return array{
     *   invariant_id:string,
     *   verdict:'survives'|'broken'|'indeterminate',
     *   witness_nodes:list<array<string,mixed>>,
     *   unsupported_reason:?string
     * }
     */
    private function proveAlways(string $invariantId, ?array $child, array $preMap, array $postMap): array
    {
        $symbol = $this->guardedSymbol($child);
        if ($symbol === null) {
            return $this->result($invariantId, 'indeterminate', [], 'unsupported_always_shape');
        }

        $preAssignments = $preMap['assigned'][$symbol] ?? [];
        $postAssignments = $postMap['assigned'][$symbol] ?? [];

        if ($preAssignments === []) {
            return $this->result($invariantId, 'indeterminate', [], 'missing_pre_guard_assignment');
        }

        if ($postAssignments === []) {
            return $this->result($invariantId, 'broken', $preAssignments, null);
        }

        return $this->result($invariantId, 'survives', $postAssignments, null);
    }

    /**
     * @param  array<string,mixed>|null  $child
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $preMap
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $postMap
     * @return array{
     *   invariant_id:string,
     *   verdict:'survives'|'broken'|'indeterminate',
     *   witness_nodes:list<array<string,mixed>>,
     *   unsupported_reason:?string
     * }
     */
    private function proveNever(string $invariantId, ?array $child, array $preMap, array $postMap): array
    {
        $symbol = $this->identifierName($child);
        if ($symbol === null) {
            return $this->result($invariantId, 'indeterminate', [], 'unsupported_never_shape');
        }

        $introduced = $postMap['assigned'][$symbol] ?? [];
        if (($preMap['assigned'][$symbol] ?? []) === [] && $introduced !== []) {
            return $this->result($invariantId, 'broken', $introduced, null);
        }

        return $this->result($invariantId, 'survives', $introduced, null);
    }

    /**
     * @param  list<array<string,mixed>>  $children
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $preMap
     * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $postMap
     * @return array{
     *   invariant_id:string,
     *   verdict:'survives'|'broken'|'indeterminate',
     *   witness_nodes:list<array<string,mixed>>,
     *   unsupported_reason:?string
     * }
     */
    private function proveImplies(string $invariantId, array $children, array $preMap, array $postMap): array
    {
        if (count($children) !== 2) {
            return $this->result($invariantId, 'indeterminate', [], 'unsupported_implies_shape');
        }

        $antecedentSymbol = $this->identifierName($children[0]);
        $consequentSymbol = $this->guardedSymbol($children[1]) ?? $this->identifierName($children[1]);
        if ($antecedentSymbol === null || $consequentSymbol === null) {
            return $this->result($invariantId, 'indeterminate', [], 'unsupported_implies_shape');
        }

        $antecedentRemoved = ($preMap['assigned'][$antecedentSymbol] ?? []) !== [] && ($postMap['assigned'][$antecedentSymbol] ?? []) === [];
        $consequentStillPresent = ($postMap['assigned'][$consequentSymbol] ?? []) !== [];

        if ($antecedentRemoved && ! $consequentStillPresent) {
            return $this->result($invariantId, 'broken', $preMap['assigned'][$antecedentSymbol] ?? [], null);
        }

        $witness = $postMap['assigned'][$consequentSymbol] ?? [];

        return $this->result($invariantId, 'survives', $witness, null);
    }

    /**
     * @param  array<string,mixed>|null  $node
     */
    private function guardedSymbol(?array $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['type'] ?? null) === 'identifier') {
            return is_string($node['name'] ?? null) ? $node['name'] : null;
        }

        if (($node['type'] ?? null) === 'binary' && in_array((string) ($node['op'] ?? ''), ['==', '!=', '<', '<=', '>', '>='], true)) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];

            return $this->identifierName($children[0] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $node
     */
    private function identifierName(?array $node): ?string
    {
        return is_array($node) && ($node['type'] ?? null) === 'identifier' && is_string($node['name'] ?? null)
            ? $node['name']
            : null;
    }

    /**
     * @return array{
     *   assigned:array<string,list<array{symbol:string,start_line:int,end_line:int}>>
     * }|null
     */
    private function symbolMap(string $source): ?array
    {
        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse($source);
            if (! is_array($ast)) {
                return null;
            }

            $assigned = [];
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new class($assigned) extends NodeVisitorAbstract
            {
                /**
                 * @var array<string,list<array{symbol:string,start_line:int,end_line:int}>>
                 */
                private array $assigned;

                /**
                 * @param  array<string,list<array{symbol:string,start_line:int,end_line:int}>>  $assigned
                 */
                public function __construct(array &$assigned)
                {
                    $this->assigned = &$assigned;
                }

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Expr\Assign) {
                        $symbol = $this->symbolFromExpr($node->var);
                        if ($symbol !== null) {
                            $this->assigned[$symbol][] = [
                                'symbol' => $symbol,
                                'start_line' => (int) $node->getStartLine(),
                                'end_line' => (int) $node->getEndLine(),
                            ];
                        }
                    }

                    return null;
                }

                private function symbolFromExpr(Node\Expr $expr): ?string
                {
                    if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                        return '$'.$expr->name;
                    }

                    if ($expr instanceof Node\Expr\PropertyFetch
                        && $expr->var instanceof Node\Expr\Variable
                        && $expr->var->name === 'this'
                        && $expr->name instanceof Node\Identifier) {
                        return '$this->'.$expr->name->toString();
                    }

                    return null;
                }
            });
            $traverser->traverse($ast);

            return ['assigned' => $assigned];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $witnessNodes
     * @return array{
     *   invariant_id:string,
     *   verdict:'survives'|'broken'|'indeterminate',
     *   witness_nodes:list<array<string,mixed>>,
     *   unsupported_reason:?string
     * }
     */
    private function result(string $invariantId, string $verdict, array $witnessNodes, ?string $unsupportedReason): array
    {
        return [
            'invariant_id' => $invariantId,
            'verdict' => $verdict,
            'witness_nodes' => $witnessNodes,
            'unsupported_reason' => $unsupportedReason,
        ];
    }
}
