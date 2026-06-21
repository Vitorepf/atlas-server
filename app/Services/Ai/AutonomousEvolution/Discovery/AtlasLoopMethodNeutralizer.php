<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

/**
 * §5.6 · ORPHAN-WIRING — the NEUTRALIZER behind the meaningful-wiring proof.
 *
 * To prove a former orphan is GENUINELY load-bearing (not a cosmetic `new Orphan()` or a hardcoded test
 * value), the judge neutralizes the orphan's BEHAVIOR and re-runs the frozen test: it MUST go RED. This class
 * produces the neutralized source — every non-constructor method body gets a `throw` injected as its FIRST
 * statement, so ANY method that is actually CALLED diverges, while the CONSTRUCTOR is left intact (so a
 * cosmetic instantiation that never calls a method does NOT break — and is therefore correctly REJECTED as
 * non-load-bearing). Pure + deterministic (AST rewrite, no FS/git), so the proof is testable in isolation.
 */
final class AtlasLoopMethodNeutralizer
{
    /** The sentinel a neutralized method throws — unique so a test can confirm the neutralization fired. */
    public const SENTINEL = 'atlas_orphan_neutralized';

    /**
     * Return $source with every non-constructor method body neutralized (a throw injected first), or null when
     * the source cannot be parsed/printed (fail-closed — the caller treats null as "could not verify").
     */
    public function neutralize(string $source): ?string
    {
        try {
            $parser = (new ParserFactory)->createForHostVersion();
            $stmts = $parser->parse($source);
            if ($stmts === null) {
                return null;
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new class extends NodeVisitorAbstract
            {
                public bool $changed = false;

                public function enterNode(Node $node)
                {
                    if (! $node instanceof Node\Stmt\ClassMethod) {
                        return null;
                    }
                    if (strtolower($node->name->toString()) === '__construct') {
                        return null; // leave the constructor — a bare `new Orphan()` must NOT break
                    }
                    if (! is_array($node->stmts) || $node->stmts === []) {
                        return null; // abstract/interface — nothing to neutralize
                    }
                    $throw = new Node\Stmt\Expression(new Node\Expr\Throw_(
                        new Node\Expr\New_(
                            new Node\Name\FullyQualified('RuntimeException'),
                            [new Node\Arg(new Node\Scalar\String_(AtlasLoopMethodNeutralizer::SENTINEL))],
                        ),
                    ));
                    array_unshift($node->stmts, $throw);
                    $this->changed = true;

                    return null;
                }
            });

            $new = $traverser->traverse($stmts);

            return (new Standard)->prettyPrintFile($new);
        } catch (Throwable) {
            return null;
        }
    }
}
