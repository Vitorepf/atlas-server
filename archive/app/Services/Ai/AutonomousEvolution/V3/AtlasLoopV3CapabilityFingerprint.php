<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

final class AtlasLoopV3CapabilityFingerprint
{
    public const SCHEMA = 'atlas.loop.v3.capability_fingerprint.v1';

    /**
     * @param  list<mixed>  $atomKinds
     * @param  list<mixed>  $pathPatterns
     * @return array{fqcn:string,public_methods:list<string>,atom_kinds:list<string>,path_patterns:list<string>,hash:string,schema:string}
     */
    public static function of(string $primitiveFqcn, array $atomKinds, array $pathPatterns): array
    {
        if (! class_exists($primitiveFqcn, false) && ! class_exists($primitiveFqcn)) {
            throw new InvalidArgumentException('unknown_class:'.$primitiveFqcn);
        }

        try {
            $rc = new ReflectionClass($primitiveFqcn);
        } catch (ReflectionException) {
            throw new InvalidArgumentException('unknown_class:'.$primitiveFqcn);
        }

        $publicMethods = [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $rc->getName()) {
                $publicMethods[] = $method->getName();
            }
        }
        sort($publicMethods, SORT_STRING);

        $atomKinds = self::normalizedList($atomKinds);
        $pathPatterns = self::normalizedList($pathPatterns);
        $hash = sha1((string) json_encode(
            [$primitiveFqcn, $publicMethods, $atomKinds, $pathPatterns],
            JSON_UNESCAPED_SLASHES,
        ));

        return [
            'fqcn' => $primitiveFqcn,
            'public_methods' => $publicMethods,
            'atom_kinds' => $atomKinds,
            'path_patterns' => $pathPatterns,
            'hash' => $hash,
            'schema' => self::SCHEMA,
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public static function equivalent(array $a, array $b): bool
    {
        return ($a['hash'] ?? null) !== null
            && ($b['hash'] ?? null) !== null
            && $a['hash'] === $b['hash'];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function normalizedList(array $values): array
    {
        $normalized = array_values(array_unique(array_map('strval', $values)));
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
