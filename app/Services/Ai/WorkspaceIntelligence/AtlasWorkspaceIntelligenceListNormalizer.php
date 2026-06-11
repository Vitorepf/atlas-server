<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\Ai\Support\AiStringListNormalizer;

final class AtlasWorkspaceIntelligenceListNormalizer
{
    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public function stringsFromArrayCast(mixed $values): array
    {
        return AiStringListNormalizer::stringsFromArrayCast($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public function uniqueStrings(mixed $values): array
    {
        return AiStringListNormalizer::uniqueTrimmedScalarValues($values);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return list<string>
     */
    public function uniqueMappedStrings(array $values, callable $map): array
    {
        return AiStringListNormalizer::uniqueMappedStrings($values, $map);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public function uniqueStringValues(mixed $values): array
    {
        return AiStringListNormalizer::uniqueTrimmedStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public function uniqueSingleLineStrings(mixed $values): array
    {
        return AiStringListNormalizer::uniqueSingleLineStrings($values);
    }
}
