<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

final class DomainLexicalNormalizer
{
    public const SCHEMA_VERSION = 'atlas.memory.domain_lexical_normalizer.v1';

    public const FORMULA_VERSION = 'maxb10.domain_equivalence.v1';

    public const MAX_EXPANDED_TOKENS = 32;

    /** @var array<string,list<string>> */
    private const EQUIVALENCES = [
        'memoria' => ['memory'],
        'memória' => ['memory'],
        'cerebro' => ['brain'],
        'cérebro' => ['brain'],
        'esteira' => ['pipeline'],
        'execucao' => ['execution'],
        'execução' => ['execution'],
        'decisao' => ['decision'],
        'decisão' => ['decision'],
        'evidencia' => ['evidence'],
        'evidência' => ['evidence'],
        'verificacao' => ['verification'],
        'verificação' => ['verification'],
        'aprendizado' => ['learning'],
        'operador' => ['operator'],
    ];

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        preg_match_all('/[\pL\pN]{3,}/u', Str::lower($text), $matches);

        $tokens = [];
        foreach (array_values(array_unique($matches[0] ?? [])) as $token) {
            $tokens[] = $token;
            foreach (self::EQUIVALENCES[$token] ?? [] as $equivalent) {
                $tokens[] = $equivalent;
            }
            if (count($tokens) >= self::MAX_EXPANDED_TOKENS) {
                break;
            }
        }

        return array_values(array_slice(array_unique($tokens), 0, self::MAX_EXPANDED_TOKENS));
    }

    /**
     * @param  array<int,mixed>  $fields
     */
    public static function score(string $query, array $fields): float
    {
        $queryTokens = self::tokens($query);
        if ($queryTokens === []) {
            return 0.0;
        }

        $haystack = implode(' ', array_filter(array_map(
            static fn (mixed $field): string => AiValueNormalizer::trimmedScalarStringOrNull($field) ?? '',
            $fields,
        )));
        $haystackTokens = self::tokens($haystack);
        if ($haystackTokens === []) {
            return 0.0;
        }

        $matches = count(array_intersect($queryTokens, $haystackTokens));

        return round($matches / count($queryTokens), 3);
    }

    /**
     * @return array<string,mixed>
     */
    public static function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'equivalences' => self::EQUIVALENCES,
            'max_expanded_tokens' => self::MAX_EXPANDED_TOKENS,
            'provider_calls_made' => false,
            'deterministic' => true,
        ];
    }
}
