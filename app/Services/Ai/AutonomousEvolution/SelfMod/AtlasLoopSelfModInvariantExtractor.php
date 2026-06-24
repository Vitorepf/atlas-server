<?php

declare(strict_types=1);

namespace phpDocumentor\Reflection;

final class DocBlockFactory
{
    public static function createInstance(): self
    {
        return new self;
    }

    public function create(string $docComment): object
    {
        return (object) ['doc_comment' => $docComment];
    }
}

namespace App\Services\Ai\AutonomousEvolution\SelfMod;

use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class AtlasLoopSelfModInvariantExtractor
{
    /**
     * @param  list<class-string>  $classes
     * @return array<string, list<array{
     *   applies_to:'class'|'method',
     *   class:string,
     *   expression:string,
     *   invariant_id:string,
     *   source_file:string,
     *   source_line:int,
     *   method?:string,
     *   parse_error?:bool,
     *   raw_line?:string
     * }>>
     */
    public function extract(array $classes): array
    {
        $factory = DocBlockFactory::createInstance();
        $records = [];

        foreach ($classes as $className) {
            if (! class_exists($className)) {
                throw new RuntimeException('Class does not exist: '.$className);
            }

            $reflection = new ReflectionClass($className);
            $classRecords = [];

            $classDoc = $reflection->getDocComment();
            if (is_string($classDoc) && $classDoc !== '') {
                $factory->create($classDoc);
                array_push(
                    $classRecords,
                    ...$this->recordsFromDocComment(
                        className: $reflection->getName(),
                        docComment: $classDoc,
                        startLine: $reflection->getStartLine(),
                        appliesTo: 'class',
                        sourceFile: (string) $reflection->getFileName(),
                    )
                );
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                $methodDoc = $method->getDocComment();
                if (! is_string($methodDoc) || $methodDoc === '') {
                    continue;
                }

                $factory->create($methodDoc);
                array_push(
                    $classRecords,
                    ...$this->recordsFromDocComment(
                        className: $reflection->getName(),
                        docComment: $methodDoc,
                        startLine: $method->getStartLine(),
                        appliesTo: 'method',
                        sourceFile: (string) $reflection->getFileName(),
                        method: $method,
                    )
                );
            }

            usort($classRecords, static function (array $left, array $right): int {
                $lineOrder = $left['source_line'] <=> $right['source_line'];
                if ($lineOrder !== 0) {
                    return $lineOrder;
                }

                return strcmp($left['invariant_id'], $right['invariant_id']);
            });

            $records[$reflection->getName()] = $classRecords;
        }

        ksort($records);

        return $records;
    }

    /**
     * @return list<array{
     *   applies_to:'class'|'method',
     *   class:string,
     *   expression:string,
     *   invariant_id:string,
     *   source_file:string,
     *   source_line:int,
     *   method?:string,
     *   parse_error?:bool,
     *   raw_line?:string
     * }>
     */
    private function recordsFromDocComment(
        string $className,
        string $docComment,
        int $startLine,
        string $appliesTo,
        string $sourceFile,
        ?ReflectionMethod $method = null,
    ): array {
        $lines = preg_split("/\r?\n/", $docComment) ?: [];
        $docStartLine = $startLine - count($lines);
        $records = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line, " \t\n\r\0\x0B*");
            if (! str_starts_with($trimmed, '@invariant')) {
                continue;
            }

            $payload = trim(substr($trimmed, strlen('@invariant')));
            $sourceLine = $docStartLine + $index + 1;

            if (! preg_match('/^([A-Za-z0-9_.-]+)\s*:\s*(.+)$/', $payload, $matches) || trim($matches[2]) === '') {
                $records[] = [
                    'applies_to' => $appliesTo,
                    'class' => $className,
                    'expression' => '',
                    'invariant_id' => '__parse_error__',
                    'parse_error' => true,
                    'raw_line' => $trimmed,
                    'source_file' => $sourceFile,
                    'source_line' => $sourceLine,
                    ...($method !== null ? ['method' => $method->getName()] : []),
                ];
                continue;
            }

            $records[] = [
                'applies_to' => $appliesTo,
                'class' => $className,
                'expression' => trim($matches[2]),
                'invariant_id' => $matches[1],
                'source_file' => $sourceFile,
                'source_line' => $sourceLine,
                ...($method !== null ? ['method' => $method->getName()] : []),
            ];
        }

        return $records;
    }
}
