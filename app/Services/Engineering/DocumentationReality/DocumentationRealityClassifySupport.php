<?php

declare(strict_types=1);

namespace App\Services\Engineering\DocumentationReality;

/**
 * Pure classification helpers for documentation reality (full-pass peel).
 */
final class DocumentationRealityClassifySupport
{
    /**
     * @param  list<string>  $executingKeys
     * @param  list<string>  $partialKeys
     */
    public static function executionFor(?string $evaluationKey, array $executingKeys, array $partialKeys): string
    {
        if ($evaluationKey === null) {
            return 'declared';
        }

        if (in_array($evaluationKey, $executingKeys, true)) {
            return 'executes';
        }

        if (in_array($evaluationKey, $partialKeys, true)) {
            return 'partial';
        }

        return 'declared';
    }

    /**
     * @return list<array{kind: string, ref: string}>
     */
    public static function declaredEvidenceRefs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $kind = trim((string) ($entry['kind'] ?? ''));
                $ref = trim((string) ($entry['ref'] ?? ''));
            } elseif (is_string($entry) && str_contains($entry, ':')) {
                [$kind, $ref] = array_map('trim', explode(':', $entry, 2));
            } else {
                continue;
            }
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }
}
