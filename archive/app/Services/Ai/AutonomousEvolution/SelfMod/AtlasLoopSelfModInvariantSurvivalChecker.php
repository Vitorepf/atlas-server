<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfMod;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class AtlasLoopSelfModInvariantSurvivalChecker
{
    public function __construct(
        private readonly ?AtlasLoopSelfModEditClassifier $classifier = null,
        private readonly ?AtlasLoopSelfModInvariantExtractor $extractor = null,
    ) {}

    /**
     * @param  array{kind:string}  $classification
     * @param  array<string, list<array<string,mixed>>>  $invariants
     * @param  array<string, mixed>  $fixtureInputs
     * @return array{
     *   results:list<array{class:string,invariant_id:string,status:'HOLDS'|'UNCHECKABLE'|'VIOLATED',evidence:array<string,mixed>}>,
     *   verdict:'APPROVED'|'REJECTED'
     * }
     */
    public function check(
        string $preSource,
        string $postSource,
        array $classification,
        array $invariants,
        array $fixtureInputs = [],
    ): array {
        $kind = (string) ($classification['kind'] ?? 'STRUCTURAL');
        $results = [];

        if ($kind === 'COSMETIC') {
            $sameNodeKinds = $this->nodeKinds($preSource) === $this->nodeKinds($postSource);

            foreach ($invariants as $class => $records) {
                foreach ($records as $record) {
                    $results[] = [
                        'class' => $class,
                        'evidence' => ['node_kind_equality' => $sameNodeKinds],
                        'invariant_id' => (string) $record['invariant_id'],
                        'status' => $sameNodeKinds ? 'HOLDS' : 'UNCHECKABLE',
                    ];
                }
            }

            return [
                'results' => $results,
                'verdict' => $this->verdict($results),
            ];
        }

        $this->loadPostSource($postSource);

        foreach ($invariants as $class => $records) {
            $object = class_exists($class) ? new $class : null;
            $classInputs = is_array($fixtureInputs[$class] ?? null) ? $fixtureInputs[$class] : [];

            if ($object !== null) {
                $this->runBootstrapCalls($object, $classInputs['bootstrap_calls'] ?? []);
            }

            foreach ($records as $record) {
                $results[] = $this->evaluateInvariant(
                    className: $class,
                    object: $object,
                    record: $record,
                    classificationKind: $kind,
                    fixtureInputs: $classInputs,
                );
            }
        }

        return [
            'results' => $results,
            'verdict' => $this->verdict($results),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $fixtureInputs
     * @return array{class:string,invariant_id:string,status:'HOLDS'|'UNCHECKABLE'|'VIOLATED',evidence:array<string,mixed>}
     */
    private function evaluateInvariant(
        string $className,
        ?object $object,
        array $record,
        string $classificationKind,
        array $fixtureInputs,
    ): array {
        $invariantId = (string) ($record['invariant_id'] ?? '');
        $expression = (string) ($record['expression'] ?? '');
        $appliesTo = (string) ($record['applies_to'] ?? 'class');

        if ($object === null) {
            return $this->result($className, $invariantId, 'UNCHECKABLE', ['missing_symbol' => $className]);
        }

        if ($missing = $this->missingSymbol($object, $expression)) {
            return $this->result($className, $invariantId, 'UNCHECKABLE', ['missing_symbol' => $missing]);
        }

        if ($classificationKind === 'STRUCTURAL' && $appliesTo !== 'class') {
            return $this->result($className, $invariantId, 'HOLDS', ['structural_scope' => 'class_only']);
        }

        $calls = [];
        if ($classificationKind === 'SEMANTIC' && $appliesTo === 'method') {
            $methodName = (string) ($record['method'] ?? '');
            $calls = is_array($fixtureInputs['method_calls'][$methodName] ?? null)
                ? $fixtureInputs['method_calls'][$methodName]
                : [];
        }

        if ($calls === []) {
            $calls = [[]];
        }

        foreach ($calls as $args) {
            $args = is_array($args) ? array_values($args) : [];
            $argMap = $this->argumentMap($object, (string) ($record['method'] ?? ''), $args);

            if (($record['method'] ?? '') !== '') {
                $method = (string) $record['method'];
                $object->{$method}(...$args);
            }

            try {
                $holds = $this->evaluateExpression($object, $expression, $argMap);
            } catch (Throwable) {
                return $this->result($className, $invariantId, 'UNCHECKABLE', ['missing_symbol' => $this->missingSymbol($object, $expression) ?? 'expression_error']);
            }

            if ($holds !== true) {
                return $this->result($className, $invariantId, 'VIOLATED', [
                    'failing_invariant_id' => $invariantId,
                    'offending_value' => $this->offendingValue($object, $expression),
                ]);
            }
        }

        return $this->result($className, $invariantId, 'HOLDS', ['classification' => $classificationKind]);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array{class:string,invariant_id:string,status:'HOLDS'|'UNCHECKABLE'|'VIOLATED',evidence:array<string,mixed>}
     */
    private function result(string $className, string $invariantId, string $status, array $evidence): array
    {
        return [
            'class' => $className,
            'evidence' => $evidence,
            'invariant_id' => $invariantId,
            'status' => $status,
        ];
    }

    /**
     * @param  list<array{class:string,invariant_id:string,status:'HOLDS'|'UNCHECKABLE'|'VIOLATED',evidence:array<string,mixed>}>  $results
     */
    private function verdict(array $results): string
    {
        foreach ($results as $result) {
            if ($result['status'] !== 'HOLDS') {
                return 'REJECTED';
            }
        }

        return 'APPROVED';
    }

    /**
     * @param  list<mixed>  $calls
     */
    private function runBootstrapCalls(object $object, array $calls): void
    {
        foreach ($calls as $call) {
            if (! is_array($call) || ! is_string($call['method'] ?? null)) {
                continue;
            }

            $args = is_array($call['args'] ?? null) ? array_values($call['args']) : [];
            $object->{$call['method']}(...$args);
        }
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string,mixed>
     */
    private function argumentMap(object $object, string $methodName, array $args): array
    {
        if ($methodName === '' || ! method_exists($object, $methodName)) {
            return [];
        }

        $reflection = new ReflectionMethod($object, $methodName);
        $map = [];
        foreach ($reflection->getParameters() as $index => $parameter) {
            $map[$parameter->getName()] = $args[$index] ?? null;
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $variables
     */
    private function evaluateExpression(object $object, string $expression, array $variables): bool
    {
        return (bool) (function () use ($expression, $variables) {
            extract($variables, EXTR_SKIP);

            return eval('return '.$expression.';');
        })->call($object);
    }

    private function offendingValue(object $object, string $expression): mixed
    {
        if (preg_match('/\$this->([A-Za-z_][A-Za-z0-9_]*)\(\)/', $expression, $matches) === 1) {
            $method = $matches[1];
            if (method_exists($object, $method)) {
                return $object->{$method}();
            }
        }

        return false;
    }

    private function missingSymbol(object $object, string $expression): ?string
    {
        if (preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)/', $expression, $matches) !== false) {
            foreach ($matches[1] as $symbol) {
                if (! property_exists($object, $symbol) && ! method_exists($object, $symbol)) {
                    return $symbol;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function nodeKinds(string $source): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $ast = $parser->parse($source) ?? [];
        $kinds = [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($kinds) extends NodeVisitorAbstract
        {
            /** @var list<string> */
            private array $kinds;

            /**
             * @param  list<string>  $kinds
             */
            public function __construct(array &$kinds)
            {
                $this->kinds = &$kinds;
            }

            public function enterNode(Node $node): null
            {
                $this->kinds[] = $node->getType();

                return null;
            }
        });
        $traverser->traverse(is_array($ast) ? $ast : []);
        sort($kinds);

        return $kinds;
    }

    private function loadPostSource(string $source): void
    {
        $code = preg_replace('/^\s*<\?php/', '', $source);
        if (! is_string($code)) {
            return;
        }

        eval($code);
    }
}
