<?php
require __DIR__.'/../vendor/autoload.php';
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

$src = $argv[1];
$code = file_get_contents($src);
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$ast = $parser->parse($code);

$visitor = new class extends NodeVisitorAbstract {
    public $cc = [];
    public $funcName = '';
    public $decision = 0;
    public function enterNode($node) {
        if ($node instanceof Node\Stmt\Function_) {
            $this->funcName = $node->name->toString();
            $this->decision = 1;
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->funcName = $node->name->toString();
            $this->decision = 1;
        } elseif ($node instanceof Node\Stmt\If_) {
            $this->decision += 1;
            if ($node->elseifs) $this->decision += count($node->elseifs);
        } elseif ($node instanceof Node\Stmt\For_) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Stmt\Foreach_) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Stmt\While_) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Stmt\Do_) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Stmt\Case_) {
            if ($node->cond !== null) $this->decision += 1;
        } elseif ($node instanceof Node\Stmt\Catch_) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Expr\BinaryOp\BooleanAnd
               || $node instanceof Node\Expr\BinaryOp\LogicalAnd
               || $node instanceof Node\Expr\BinaryOp\Coalesce) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Expr\BinaryOp\BooleanOr
               || $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $this->decision += 1;
        } elseif ($node instanceof Node\Expr\Ternary) {
            if ($node->if !== null) $this->decision += 1;
        } elseif ($node instanceof Node\Expr\Match_) {
            $this->decision += count($node->arms);
        }
    }
    public function leaveNode($node) {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $this->cc[$this->funcName] = $this->decision;
        }
    }
};

$tr = new NodeTraverser();
$tr->addVisitor($visitor);
$tr->traverse($ast);
foreach ($visitor->cc as $name => $c) {
    if ($c >= 8) {
        printf("%3d  %s\n", $c, $name);
    }
}
echo "---\n";
foreach ($visitor->cc as $name => $c) {
    if (str_contains($name, 'explore') || str_contains($name, 'pick') || str_contains($name, 'runScenario') || str_contains($name, 'shouldBreak') || str_contains($name, 'applyAttempt') || str_contains($name, 'improves') || str_contains($name, 'explorationInput')) {
        printf("%3d  %s\n", $c, $name);
    }
}
echo "---\n";
$total = array_sum($visitor->cc);
echo "TOTAL branches (sum of CC across all methods): $total\n";
