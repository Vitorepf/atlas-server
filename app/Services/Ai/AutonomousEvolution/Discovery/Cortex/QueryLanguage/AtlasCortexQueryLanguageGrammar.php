<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage;

final class AtlasCortexQueryLanguageGrammar
{
    /**
     * @var array<string,mixed>
     */
    private const SPEC = [
        'clauses' => [
            'SELECT',
            'FROM',
            'WHERE',
            'GROUP BY',
            'ORDER BY',
            'LIMIT',
        ],
        'operators' => [
            '=',
            '!=',
            '<',
            '<=',
            '>',
            '>=',
            'IN',
            'NOT IN',
            'EXISTS',
            'NOT EXISTS',
        ],
        'fields' => [
            'fqcn',
            'file_path',
            'method_name',
            'classification',
            'change_kind',
            'target_symbol',
            'consumer_fqcn',
            'consumer_file',
            'consumer_line',
            'last_modified_at',
            'first_seen_at',
            'last_tested_at',
            'modification_count_30d',
            'unwired_since_at',
            'unwired_days',
        ],
        'dimensions' => [
            'fqcn',
            'file_path',
            'classification',
            'change_kind',
            'target_symbol',
            'consumer_fqcn',
            'consumer_file',
            'last_modified_at',
            'unwired_days',
        ],
        'from_scopes' => [
            'cortex_api_diff',
            'cortex_temporal_symbols',
            'cortex_temporal_orphans',
            'cortex_breaking_changes',
            'cortex_conflicts',
        ],
        'reserved_words' => [
            'SELECT',
            'FROM',
            'WHERE',
            'GROUP',
            'BY',
            'ORDER',
            'LIMIT',
            'IN',
            'NOT',
            'EXISTS',
            'AND',
            'OR',
        ],
    ];

    public static function version(): string
    {
        return 'atlas.cortex.query_language.grammar.v1';
    }

    /**
     * @return array<string,mixed>
     */
    public static function spec(): array
    {
        return self::SPEC;
    }
}
