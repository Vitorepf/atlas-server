<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage\AtlasCortexQueryLanguageParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CortexQueryLanguageParserTest extends TestCase
{
    public function test_it_parses_a_valid_query_deterministically(): void
    {
        $query = "SELECT fqcn, classification FROM cortex_api_diff WHERE change_kind = 'changed' GROUP BY classification ORDER BY fqcn LIMIT 10";
        $parser = new AtlasCortexQueryLanguageParser;

        $first = $parser->parse($query);
        $second = $parser->parse($query);

        $this->assertSame(['SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'LIMIT'], array_keys($first));
        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function test_it_rejects_unknown_clause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed token');

        (new AtlasCortexQueryLanguageParser)->parse('UPSERT fqcn FROM cortex_api_diff');
    }

    public function test_it_rejects_unknown_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mystery_field');

        (new AtlasCortexQueryLanguageParser)->parse('SELECT mystery_field FROM cortex_api_diff');
    }

    public function test_it_rejects_forbidden_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LIKE');

        (new AtlasCortexQueryLanguageParser)->parse("SELECT fqcn FROM cortex_api_diff WHERE fqcn LIKE 'App\\\\%'");
    }

    public function test_it_rejects_malformed_tokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed token');

        (new AtlasCortexQueryLanguageParser)->parse('SELECT fqcn FROM cortex_api_diff WHERE');
    }

    public function test_in_list_with_bare_digits_returns_integers(): void
    {
        $result = (new AtlasCortexQueryLanguageParser)->parse(
            'SELECT fqcn FROM cortex_api_diff WHERE unwired_days IN (5, 20)'
        );

        $where = $result['WHERE'];
        $this->assertSame('IN', $where['operator']);
        $this->assertSame([5, 20], $where['value']);
    }
}
