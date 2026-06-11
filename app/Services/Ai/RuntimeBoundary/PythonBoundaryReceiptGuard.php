<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

use RuntimeException;

final class PythonBoundaryReceiptGuard
{
    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $requiredTrue
     * @param  list<string>  $requiredFalse
     * @return array<string,mixed>
     */
    public static function runReal(
        PythonManifestRuntimeClient $runtime,
        array $manifest,
        array $requiredTrue,
        array $requiredFalse,
        string $message,
    ): array {
        $result = $runtime->run($manifest);

        self::assertReal($result, $requiredTrue, $requiredFalse, $message);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  list<string>  $requiredTrue
     * @param  list<string>  $requiredFalse
     */
    public static function assertReal(array $result, array $requiredTrue, array $requiredFalse, string $message): void
    {
        $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];

        foreach ($requiredTrue as $key) {
            if (($boundary[$key] ?? false) !== true) {
                throw new RuntimeException($message);
            }
        }

        foreach ($requiredFalse as $key) {
            if (($boundary[$key] ?? true) !== false) {
                throw new RuntimeException($message);
            }
        }
    }
}
