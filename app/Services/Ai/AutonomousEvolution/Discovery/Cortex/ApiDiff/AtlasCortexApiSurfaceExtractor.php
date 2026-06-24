<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

final class AtlasCortexApiSurfaceExtractor
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function extractForClass(string $fqcn): array
    {
        $reflection = new ReflectionClass($fqcn);
        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $fqcn,
        );

        usort(
            $methods,
            static fn (ReflectionMethod $left, ReflectionMethod $right): int => $left->getStartLine() <=> $right->getStartLine(),
        );

        $records = [];
        foreach ($methods as $method) {
            $record = [
                'method_name' => $method->getName(),
                'visibility' => 'public',
                'static' => $method->isStatic(),
                'parameters' => array_map(
                    fn (ReflectionParameter $parameter): array => $this->parameterRecord($parameter),
                    $method->getParameters(),
                ),
                'return_type_hint' => $this->typeHint($method->getReturnType()),
                'declaring_file_path' => (string) $method->getFileName(),
                'start_line' => $method->getStartLine(),
                'end_line' => $method->getEndLine(),
            ];
            $record['signature_hash'] = hash('sha256', $this->canonicalSignature($record));

            $records[$method->getName()] = $record;
        }

        return $records;
    }

    /**
     * @return array{name:string,type_hint:string,by_ref:bool,variadic:bool,default_literal:string}
     */
    private function parameterRecord(ReflectionParameter $parameter): array
    {
        return [
            'name' => $parameter->getName(),
            'type_hint' => $this->typeHint($parameter->getType()),
            'by_ref' => $parameter->isPassedByReference(),
            'variadic' => $parameter->isVariadic(),
            'default_literal' => $parameter->isDefaultValueAvailable()
                ? $this->defaultLiteral($parameter->getDefaultValue())
                : 'NO_DEFAULT',
        ];
    }

    private function typeHint(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionNamedType) {
            $prefix = $type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '';

            return $prefix.$type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = array_map(
                function (ReflectionType $part): string {
                    if ($part instanceof ReflectionNamedType) {
                        return $part->getName();
                    }

                    return (string) $part;
                },
                $type->getTypes(),
            );

            return implode('|', $parts);
        }

        return (string) $type;
    }

    private function defaultLiteral(mixed $value): string
    {
        if (is_string($value)) {
            return "'".$value."'";
        }

        return var_export($value, true);
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function canonicalSignature(array $record): string
    {
        return (string) json_encode($this->sortRecursive($record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}
