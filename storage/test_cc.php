<?php
require __DIR__.'/../vendor/autoload.php';
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

$code = <<<'PHP'
<?php
function f($x) {
    if ($x > 0) {
        return 1;
    } else {
        return 2;
    }
}
PHP;

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$ast = $parser->parse($code);

$visitor = new class extends NodeVisitorAbstract {
    public $decision = 0;
    public function enterNode($node) {
        if ($node instanceof Node\Stmt\Function_) {
            $this->decision = 1;
        } elseif ($node instanceof Node\Stmt\If_) {
            $this->decision += 1;
            if ($node->elseifs) $this->decision += count($node->elseifs);
        }
    }
    public function leaveNode($node) {
        if ($node instanceof Node\Stmt\Function_) {
            echo "CC = ".$this->decision."\n";
        }
    }
};

$tr = new NodeTraverser();
$tr->addVisitor($visitor);
$tr->traverse($ast);
