<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Canonical shape/trim rules for AAEOS evidence_refs.
 *
 * Keeps authored ref shape stable for ledgers while exposing canonical kind/ref
 * helpers for resolvers and tier checks.
 */
final class AtlasEvidenceRefNormalizer
{
    public const FIELD_KIND = 'kind';
    public const FIELD_REF = 'ref';
    /**
     * Accept evidence_refs as a list of "kind: ref" strings or {kind,ref} maps.
     *
     * @return array<int,array{kind:string, ref:string}>
     */
    public function listFromRaw(mixed $raw): array
    {
        $refs = [];
        foreach (AiValueNormalizer::arrayOrEmpty($raw) as $entry) {
            if (is_array($entry)) {
                $kind = $this->ref($entry[self::FIELD_KIND] ?? '');
                $ref = $this->ref($entry[self::FIELD_REF] ?? '');
            } elseif (($entryString = AiValueNormalizer::trimmedStringOrNull($entry)) !== null && str_contains($entryString, ':')) {
                [$kind, $ref] = array_map(
                    fn (string $value): string => $this->ref($value),
                    explode(':', $entryString, 2),
                );
            } else {
                continue;
            }

            if ($kind !== '' && $ref !== '') {
                $refs[] = [self::FIELD_KIND => $kind, self::FIELD_REF => $ref];
            }
        }

        return $refs;
    }

    public function kind(mixed $kind): string
    {
        return AiValueNormalizer::lowerTrimmedString($kind);
    }

    public function ref(mixed $ref): string
    {
        return AiValueNormalizer::trimmedStringOrNull($ref) ?? '';
    }
}
