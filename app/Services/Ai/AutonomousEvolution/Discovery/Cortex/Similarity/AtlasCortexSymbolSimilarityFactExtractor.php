<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;

final class AtlasCortexSymbolSimilarityFactExtractor
{
    private const CONTROL_FLOW_NODE_MAP = [
        'if' => If_::class,
        'elseif' => ElseIf_::class,
        'for' => For_::class,
        'foreach' => Foreach_::class,
        'while' => While_::class,
        'do' => Do_::class,
        'switch' => Switch_::class,
        'match' => Match_::class,
        'try' => TryCatch::class,
    ];

    public function __construct(
        private ?Parser $parser = null,
        private ?NodeFinder $nodeFinder = null,
    ) {
    }

    /**
     * @return list<AtlasCortexSymbolSimilarityPairFact>
     */
    public function extract(AtlasLoopScopeComprehensionModel $snapshot): array
    {
        $inventory = array_values(array_filter(
            $snapshot->inventory,
            static fn (mixed $item): bool => is_array($item) && is_string($item['fqcn'] ?? null) && $item['fqcn'] !== '',
        ));

        usort(
            $inventory,
            static fn (array $left, array $right): int => strcmp(ltrim((string) $left['fqcn'], '\\'), ltrim((string) $right['fqcn'], '\\')),
        );

        $profiles = [];
        foreach ($inventory as $item) {
            $profile = $this->profileFor($item);
            if ($profile === null) {
                continue;
            }
            $profiles[] = $profile;
        }

        $facts = [];
        $count = count($profiles);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                $facts[] = $this->factForPair($profiles[$left], $profiles[$right]);
            }
        }

        usort(
            $facts,
            static fn (AtlasCortexSymbolSimilarityPairFact $left, AtlasCortexSymbolSimilarityPairFact $right): int => [$left->pairA, $left->pairB] <=> [$right->pairA, $right->pairB],
        );

        return $facts;
    }

    /**
     * @param  array{fqcn:mixed, public_methods?:mixed}  $item
     * @return array{
     *     fqcn:string,
     *     method_names:list<string>,
     *     method_name_set:list<string>,
     *     token_counts:array<string,int>,
     *     shape_features:array<string,int>,
     *     fingerprints:array<string,string>
     * }|null
     */
    private function profileFor(array $item): ?array
    {
        $fqcn = ltrim((string) $item['fqcn'], '\\');
        if ($fqcn === '') {
            return null;
        }

        try {
            $reflection = new ReflectionClass($fqcn);
        } catch (Throwable) {
            return null;
        }

        $methodNames = $this->methodNames($item, $reflection);
        $methodNameSet = array_values(array_unique($methodNames));
        sort($methodNameSet, SORT_STRING);

        $tokenCounts = $this->identifierTokenCounts($reflection->getShortName(), $methodNames);
        $shapeFeatures = $this->shapeFeatures($reflection);

        return [
            'fqcn' => $fqcn,
            'method_names' => $methodNames,
            'method_name_set' => $methodNameSet,
            'token_counts' => $tokenCounts,
            'shape_features' => $shapeFeatures,
            'fingerprints' => [
                'method_names' => $this->hashFingerprint($methodNameSet),
                'token_counts' => $this->hashFingerprint($tokenCounts),
                'shape_features' => $this->hashFingerprint($shapeFeatures),
            ],
        ];
    }

    /**
     * @param  array{
     *     fqcn:string,
     *     method_names:list<string>,
     *     method_name_set:list<string>,
     *     token_counts:array<string,int>,
     *     shape_features:array<string,int>,
     *     fingerprints:array<string,string>
     * }  $left
     * @param  array{
     *     fqcn:string,
     *     method_names:list<string>,
     *     method_name_set:list<string>,
     *     token_counts:array<string,int>,
     *     shape_features:array<string,int>,
     *     fingerprints:array<string,string>
     * }  $right
     */
    private function factForPair(array $left, array $right): AtlasCortexSymbolSimilarityPairFact
    {
        $overlapNames = array_values(array_intersect($left['method_name_set'], $right['method_name_set']));
        sort($overlapNames, SORT_STRING);

        return new AtlasCortexSymbolSimilarityPairFact(
            pairA: $left['fqcn'],
            pairB: $right['fqcn'],
            tokenOverlapRatio: $this->roundedRatio($this->multisetJaccard($left['token_counts'], $right['token_counts'])),
            astShapeOverlapRatio: $this->roundedRatio($this->multisetJaccard($left['shape_features'], $right['shape_features'])),
            methodNameOverlapCount: count($overlapNames),
            methodNameOverlapRatio: $this->roundedRatio($this->setRatio($left['method_name_set'], $right['method_name_set'])),
            fingerprints: [
                'ast_shape_basis' => hash('sha256', $left['fingerprints']['shape_features'].'|'.$right['fingerprints']['shape_features']),
                'method_overlap_basis' => hash('sha256', implode('|', $overlapNames)),
                'pair' => hash('sha256', $left['fqcn'].'|'.$right['fqcn']),
                'token_basis' => hash('sha256', $left['fingerprints']['token_counts'].'|'.$right['fingerprints']['token_counts']),
            ],
        );
    }

    /**
     * @param  array{public_methods?:mixed}  $item
     * @return list<string>
     */
    private function methodNames(array $item, ReflectionClass $reflection): array
    {
        $inventoryNames = array_values(array_filter(
            array_map(static fn (mixed $name): string => trim((string) $name), is_array($item['public_methods'] ?? null) ? $item['public_methods'] : []),
            static fn (string $name): bool => $name !== '',
        ));

        if ($inventoryNames !== []) {
            sort($inventoryNames, SORT_STRING);

            return $inventoryNames;
        }

        $declared = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            $declared[] = $method->getName();
        }

        $declared = array_values(array_unique($declared));
        sort($declared, SORT_STRING);

        return $declared;
    }

    /**
     * @param  list<string>  $methodNames
     * @return array<string,int>
     */
    private function identifierTokenCounts(string $shortClassName, array $methodNames): array
    {
        $counts = [];
        foreach (array_merge([$shortClassName], $methodNames) as $identifier) {
            foreach ($this->identifierTokens($identifier) as $token) {
                $counts[$token] = ($counts[$token] ?? 0) + 1;
            }
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function identifierTokens(string $identifier): array
    {
        $normalized = preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', str_replace(['_', '-'], ' ', $identifier)) ?? $identifier;
        $parts = preg_split('/\s+/u', strtolower(trim($normalized))) ?: [];
        $tokens = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * @return array<string,int>
     */
    private function shapeFeatures(ReflectionClass $reflection): array
    {
        $classLike = $this->classLikeNode($reflection);
        $methodCount = count(array_filter(
            $reflection->getMethods(),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $reflection->getName(),
        ));
        $controlCounts = array_fill_keys(array_keys(self::CONTROL_FLOW_NODE_MAP), 0);
        $arityHistogram = [];

        if ($classLike !== null) {
            $methods = array_values(array_filter(
                $classLike->getMethods(),
                static fn (ClassMethod $method): bool => ! $method->isMagic(),
            ));
            $methodCount = count($methods);

            foreach ($methods as $method) {
                $arity = count($method->params);
                $arityHistogram[$arity] = ($arityHistogram[$arity] ?? 0) + 1;
            }

            foreach (self::CONTROL_FLOW_NODE_MAP as $key => $nodeClass) {
                $controlCounts[$key] = count($this->nodeFinder()->findInstanceOf([$classLike], $nodeClass));
            }
        } else {
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }
                $arity = $method->getNumberOfParameters();
                $arityHistogram[$arity] = ($arityHistogram[$arity] ?? 0) + 1;
            }
        }

        $features = [
            'method_bucket:'.$this->methodCountBucket($methodCount) => 1,
        ];

        foreach ($controlCounts as $key => $count) {
            if ($count > 0) {
                $features['control_flow:'.$key] = $count;
            }
        }

        ksort($arityHistogram, SORT_NUMERIC);
        foreach ($arityHistogram as $arity => $count) {
            $features['arity:'.$arity] = $count;
        }

        ksort($features, SORT_STRING);

        return $features;
    }

    private function classLikeNode(ReflectionClass $reflection): ?ClassLike
    {
        $path = $reflection->getFileName();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        try {
            $ast = $this->parser()->parse($contents);
        } catch (Throwable) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $shortName = $reflection->getShortName();
        $namespace = $reflection->getNamespaceName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $classLikes = $this->nodeFinder()->findInstanceOf($ast, ClassLike::class);
        foreach ($classLikes as $classLike) {
            if (! $classLike instanceof ClassLike) {
                continue;
            }

            if ($classLike->name?->toString() !== $shortName) {
                continue;
            }

            if ($this->classNamespace($ast, $classLike) !== $namespace) {
                continue;
            }

            if ($classLike->getStartLine() === $startLine && $classLike->getEndLine() === $endLine) {
                return $classLike;
            }
        }

        foreach ($classLikes as $classLike) {
            if ($classLike instanceof ClassLike && $classLike->name?->toString() === $shortName && $this->classNamespace($ast, $classLike) === $namespace) {
                return $classLike;
            }
        }

        return null;
    }

    /**
     * @param  list<Node>  $ast
     */
    private function classNamespace(array $ast, ClassLike $classLike): string
    {
        foreach ($ast as $node) {
            if ($node instanceof Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt === $classLike) {
                        return $node->name?->toString() ?? '';
                    }
                }
            } elseif ($node === $classLike) {
                return '';
            }
        }

        return '';
    }

    private function methodCountBucket(int $count): string
    {
        return match (true) {
            $count <= 0 => '0',
            $count === 1 => '1',
            $count <= 3 => '2-3',
            $count <= 6 => '4-6',
            $count <= 10 => '7-10',
            default => '11+',
        };
    }

    /**
     * @param  array<string,int>  $left
     * @param  array<string,int>  $right
     */
    private function multisetJaccard(array $left, array $right): float
    {
        if ($left === [] && $right === []) {
            return 1.0;
        }

        $intersection = 0;
        $union = 0;
        foreach (array_keys($left + $right) as $token) {
            $leftCount = $left[$token] ?? 0;
            $rightCount = $right[$token] ?? 0;
            $intersection += min($leftCount, $rightCount);
            $union += max($leftCount, $rightCount);
        }

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function setRatio(array $left, array $right): float
    {
        $left = array_values(array_unique($left));
        $right = array_values(array_unique($right));
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);

        if ($left === [] && $right === []) {
            return 1.0;
        }

        $intersection = count(array_intersect($left, $right));
        $union = count(array_unique(array_merge($left, $right)));
        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * @param  array<string,int>|list<string>  $payload
     */
    private function hashFingerprint(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function roundedRatio(float $ratio): float
    {
        return round($ratio, 6);
    }

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory)->createForHostVersion();
    }

    private function nodeFinder(): NodeFinder
    {
        return $this->nodeFinder ??= new NodeFinder;
    }
}
