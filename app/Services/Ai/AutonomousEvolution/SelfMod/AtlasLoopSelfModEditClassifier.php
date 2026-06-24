<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfMod;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

final class AtlasLoopSelfModEditClassifier
{
    /**
     * @return array{
     *   evidence:array{ast_node_kinds_changed:list<string>,parse_error:bool},
     *   file_path:string,
     *   kind:'COSMETIC'|'SEMANTIC'|'STRUCTURAL',
     *   symbol_id:string
     * }
     */
    public function classify(string $filePath, string $beforeSource, string $afterSource): array
    {
        $beforeAst = $this->parse($beforeSource);
        $afterAst = $this->parse($afterSource);

        if ($beforeAst === null || $afterAst === null) {
            return $this->record($filePath, $this->symbolId($filePath, $beforeAst ?? $afterAst), 'STRUCTURAL', [
                'ast_node_kinds_changed' => ['ParseError'],
                'parse_error' => true,
            ]);
        }

        $symbolId = $this->symbolId($filePath, $beforeAst);
        $beforeSurface = $this->publicSurface($beforeAst);
        $afterSurface = $this->publicSurface($afterAst);

        if ($beforeSurface !== $afterSurface) {
            return $this->record($filePath, $symbolId, 'STRUCTURAL', [
                'ast_node_kinds_changed' => ['Stmt_Class', 'Stmt_ClassMethod'],
                'parse_error' => false,
            ]);
        }

        $beforeNormalized = $this->normalizedPrint($beforeAst);
        $afterNormalized = $this->normalizedPrint($afterAst);

        if ($beforeNormalized === $afterNormalized) {
            return $this->record($filePath, $symbolId, 'COSMETIC', [
                'ast_node_kinds_changed' => [],
                'parse_error' => false,
            ]);
        }

        $changedKinds = $this->semanticEvidence($beforeAst, $afterAst);

        return $this->record($filePath, $symbolId, 'SEMANTIC', [
            'ast_node_kinds_changed' => $changedKinds,
            'parse_error' => false,
        ]);
    }

    /**
     * @param  list<Node>  $ast
     * @return array{classes:list<string>,public_methods:list<string>}
     */
    private function publicSurface(array $ast): array
    {
        $surface = [
            'classes' => [],
            'public_methods' => [],
        ];

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($surface) extends NodeVisitorAbstract
        {
            /** @var array{classes:list<string>,public_methods:list<string>} */
            private array $surface;

            /**
             * @param  array{classes:list<string>,public_methods:list<string>}  $surface
             */
            public function __construct(array &$surface)
            {
                $this->surface = &$surface;
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    $this->surface['classes'][] = $node->name->toString();
                }

                if ($node instanceof Node\Stmt\ClassMethod && $node->isPublic()) {
                    $params = [];
                    foreach ($node->params as $param) {
                        $params[] = $this->typeString($param->type).':'.($param->variadic ? 'variadic' : 'plain');
                    }

                    $this->surface['public_methods'][] = sprintf(
                        '%s(%s):%s',
                        $node->name->toString(),
                        implode(',', $params),
                        $this->typeString($node->returnType),
                    );
                }

                return null;
            }

            private function typeString(null|Node\Identifier|Node\Name|Node\ComplexType $type): string
            {
                if ($type === null) {
                    return 'mixed';
                }

                return $type->getType();
            }
        });
        $traverser->traverse($ast);

        sort($surface['classes']);
        sort($surface['public_methods']);

        return $surface;
    }

    /**
     * @param  list<Node>  $ast
     * @return list<string>
     */
    private function semanticEvidence(array $beforeAst, array $afterAst): array
    {
        $evidence = [];

        if ($this->nodeTypePrints($beforeAst, Node\Stmt\Return_::class) !== $this->nodeTypePrints($afterAst, Node\Stmt\Return_::class)) {
            $evidence[] = 'Stmt_Return';
        }

        if ($this->nodeTypePrints($beforeAst, Node\Stmt\If_::class) !== $this->nodeTypePrints($afterAst, Node\Stmt\If_::class)) {
            $evidence[] = 'Stmt_If';
        }

        if ($evidence === []) {
            $evidence[] = 'Expr_Change';
        }

        return $evidence;
    }

    /**
     * @param  list<Node>  $ast
     * @return list<string>
     */
    private function nodeTypePrints(array $ast, string $nodeClass): array
    {
        $items = [];
        $prettyPrinter = new Standard;
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($nodeClass, $items, $prettyPrinter) extends NodeVisitorAbstract
        {
            /** @var list<string> */
            private array $items;

            /**
             * @param  list<string>  $items
             */
            public function __construct(
                private readonly string $nodeClass,
                array &$items,
                private readonly Standard $prettyPrinter,
            ) {
                $this->items = &$items;
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof $this->nodeClass) {
                    $items = (new NodeTraverser)->traverse([$this->cloneWithoutAttributes($node)]);
                    $this->items[] = $this->prettyPrinter->prettyPrint($items);
                }

                return null;
            }

            private function cloneWithoutAttributes(Node $node): Node
            {
                $clone = clone $node;
                $clone->setAttributes([]);

                foreach ($clone->getSubNodeNames() as $name) {
                    $child = $clone->{$name};
                    if ($child instanceof Node) {
                        $clone->{$name} = $this->cloneWithoutAttributes($child);
                    } elseif (is_array($child)) {
                        foreach ($child as $index => $item) {
                            if ($item instanceof Node) {
                                $child[$index] = $this->cloneWithoutAttributes($item);
                            }
                        }
                        $clone->{$name} = $child;
                    }
                }

                return $clone;
            }
        });
        $traverser->traverse($this->normalizedAst($ast));

        sort($items);

        return $items;
    }

    /**
     * @param  list<Node>  $ast
     */
    private function normalizedPrint(array $ast): string
    {
        return (new Standard)->prettyPrintFile($this->normalizedAst($ast));
    }

    /**
     * @param  list<Node>  $ast
     * @return list<Node>
     */
    private function normalizedAst(array $ast): array
    {
        $cloned = unserialize(serialize($ast));
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class extends NodeVisitorAbstract
        {
            /** @var array<string,string> */
            private array $variables = [];
            private int $counter = 0;

            public function enterNode(Node $node): null
            {
                $node->setAttributes([]);

                if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                    $this->variables[$node->name] ??= 'var_'.$this->counter++;
                    $node->name = $this->variables[$node->name];
                }

                if ($node instanceof Node\Stmt\Use_) {
                    usort($node->uses, static fn (Node\UseItem $left, Node\UseItem $right): int => strcmp($left->name->toString(), $right->name->toString()));
                }

                return null;
            }
        });

        return $traverser->traverse($cloned);
    }

    /**
     * @return list<Node>|null
     */
    private function parse(string $source): ?array
    {
        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse($source);

            return is_array($ast) ? $ast : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<Node>  $ast
     */
    private function symbolId(string $filePath, ?array $ast): string
    {
        if ($ast === null) {
            return $filePath.'::unknown';
        }

        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Class_ && $stmt->name !== null) {
                        return trim($node->name?->toString().'\\'.$stmt->name->toString(), '\\');
                    }
                }
            }

            if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                return $node->name->toString();
            }
        }

        return $filePath.'::unknown';
    }

    /**
     * @param  array{ast_node_kinds_changed:list<string>,parse_error:bool}  $evidence
     * @return array{
     *   evidence:array{ast_node_kinds_changed:list<string>,parse_error:bool},
     *   file_path:string,
     *   kind:'COSMETIC'|'SEMANTIC'|'STRUCTURAL',
     *   symbol_id:string
     * }
     */
    private function record(string $filePath, string $symbolId, string $kind, array $evidence): array
    {
        return [
            'evidence' => $evidence,
            'file_path' => $filePath,
            'kind' => $kind,
            'symbol_id' => $symbolId,
        ];
    }
}
