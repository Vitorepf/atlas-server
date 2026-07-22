<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Retrieval;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

final class DomainLexicalNormalizer
{
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_LEARNING = 'learning';
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
    public const FIELD_EVIDENCIA = 'evidencia';
    public const FIELD_EXECUCAO = 'execucao';

    public const MAX_EXPANDED_TOKENS = 32;
    public const FIELD_MEMORIA = 'memoria';
    public const FIELD_VERIFICACAO = 'verificacao';
    public const FIELD_MAX_EXPANDED_TOKENS = 'max_expanded_tokens';
    public const FIELD_OPERADOR = 'operador';
    public const FIELD_OPERATOR = 'operator';
    public const FIELD_PIPELINE = 'pipeline';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_C_REBRO = 'cérebro';
    public const FIELD_DECIS_O = 'decisão';
    public const FIELD_EVID_NCIA = 'evidência';
    public const FIELD_EXECU__O = 'execução';
    public const FIELD_MEM_RIA = 'memória';
    public const FIELD_VERIFICA__O = 'verificação';

    /** @var array<string,list<string>> */
    public const EQUIVALENCES = [
        self::FIELD_MEMORIA => [self::FIELD_MEMORY],
        self::FIELD_MEM_RIA => [self::FIELD_MEMORY],
        self::FIELD_CEREBRO => [self::FIELD_BRAIN],
        self::FIELD_C_REBRO => [self::FIELD_BRAIN],
        self::FIELD_ESTEIRA => [self::FIELD_PIPELINE],
        self::FIELD_EXECUCAO => [self::FIELD_EXECUTION],
        self::FIELD_EXECU__O => [self::FIELD_EXECUTION],
        self::FIELD_DECISAO => [self::FIELD_DECISION],
        self::FIELD_DECIS_O => [self::FIELD_DECISION],
        self::FIELD_EVIDENCIA => [self::FIELD_EVIDENCE],
        self::FIELD_EVID_NCIA => [self::FIELD_EVIDENCE],
        self::FIELD_VERIFICACAO => [self::FIELD_VERIFICATION],
        self::FIELD_VERIFICA__O => [self::FIELD_VERIFICATION],
        self::FIELD_APRENDIZADO => [self::FIELD_LEARNING],
        self::FIELD_OPERADOR => [self::FIELD_OPERATOR],
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_EQUIVALENCES => self::EQUIVALENCES,
            self::FIELD_MAX_EXPANDED_TOKENS => self::MAX_EXPANDED_TOKENS,
            self::FIELD_PROVIDER_CALLS_MADE => false,
            self::FIELD_DETERMINISTIC => true,
        ];
    }
}
