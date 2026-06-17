<?php
require __DIR__.'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$parser = (new ParserFactory)->createForHostVersion();
$finder = new NodeFinder;

function countScore(NodeFinder $finder, $stmts): int {
    $score = 1;
    $finder->find($stmts ?? [], function (Node $node) use (&$score): bool {
        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Case_
            || $node instanceof Node\Stmt\Catch_
            || $node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
        ) {
            $score++;
        }
        return false;
    });
    return $score;
}

function fileSummary(string $path, ParserFactory $pf, NodeFinder $finder): array {
    $src = file_get_contents($path);
    $parser = $pf->createForHostVersion();
    $stmts = $parser->parse($src);
    $units = $finder->find($stmts, fn(Node $n) =>
        $n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Stmt\Function_
    );
    $max = 0; $maxName = ''; $total = 0; $methodScores = [];
    foreach ($units as $u) {
        $score = countScore($finder, $u->getStmts() ?? []);
        $name = $u->name?->toString() ?? 'anon';
        $methodScores[$name] = $score;
        if ($score > $max) { $max = $score; $maxName = $name; }
        $total += $score;
    }
    $methods = count($units);
    return [
        'path' => $path,
        'max' => $max,
        'max_method' => $maxName,
        'total' => $total,
        'methods' => $methods,
        'decisions' => $total - $methods,
        'per_method' => $methodScores,
    ];
}

$pf = new ParserFactory;
$f = new NodeFinder;
$file = $argv[1] ?? '/Users/vitorepf/develop/Atlas/atlas-server/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FinalDeliveryQualityGateService.php';
$s = fileSummary($file, $pf, $f);
echo json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
