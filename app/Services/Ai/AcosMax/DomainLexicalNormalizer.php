<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

final class DomainLexicalNormalizer
{
    public const SCHEMA_VERSION = 'atlas.memory.domain_lexical_normalizer.v1';

    public const FORMULA_VERSION = 'maxb10.domain_equivalence.v1';
    public const FIELD_APRENDIZADO = 'aprendizado';
    public const FIELD_BRAIN = 'brain';
    public const FIELD_CEREBRO = 'cerebro';
    public const FIELD_DECISAO = 'decisao';
    public const FIELD_DECISION = 'decision';
    public const FIELD_DETERMINISTIC = 'deterministic';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_EXECUTION = 'execution';
    public const FIELD_MEMORY = 'memory';
    public const FIELD_VERIFICATION = 'verification';
    public const FIELD_EQUIVALENCES = 'equivalences';
    public const FIELD_ESTEIRA = 'esteira';

    public const MAX_EXPANDED_TOKENS = 32;

    /** @var array<string,list<string>> */
    public const EQUIVALENCES = [
        'memoria' => [self::FIELD_MEMORY],
        'memória' => [self::FIELD_MEMORY],
        self::FIELD_CEREBRO => [self::FIELD_BRAIN],
        'cérebro' => [self::FIELD_BRAIN],
        self::FIELD_ESTEIRA => ['pipeline'],
        'execucao' => [self::FIELD_EXECUTION],
        'execução' => [self::FIELD_EXECUTION],
        self::FIELD_DECISAO => [self::FIELD_DECISION],
        'decisão' => [self::FIELD_DECISION],
        'evidencia' => [self::FIELD_EVIDENCE],
        'evidência' => [self::FIELD_EVIDENCE],
        'verificacao' => [self::FIELD_VERIFICATION],
        'verificação' => [self::FIELD_VERIFICATION],
        self::FIELD_APRENDIZADO => ['learning'],
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
            self::FIELD_EQUIVALENCES => self::EQUIVALENCES,
            'max_expanded_tokens' => self::MAX_EXPANDED_TOKENS,
            'provider_calls_made' => false,
            self::FIELD_DETERMINISTIC => true,
        ];
    }
}
