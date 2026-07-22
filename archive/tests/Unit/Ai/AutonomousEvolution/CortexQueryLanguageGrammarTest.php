<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage\AtlasCortexQueryLanguageGrammar;
use PHPUnit\Framework\TestCase;

final class CortexQueryLanguageGrammarTest extends TestCase
{
    public function test_spec_exposes_only_whitelisted_clauses_operators_and_fields(): void
    {
        $spec = AtlasCortexQueryLanguageGrammar::spec();

        $this->assertSame(['SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'LIMIT'], $spec['clauses']);
        $this->assertSame(['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS'], $spec['operators']);
        $this->assertContains('fqcn', $spec['fields']);
        $this->assertContains('classification', $spec['dimensions']);
        $this->assertNotContains('LIKE', $spec['operators']);
        $this->assertNotContains('REGEX', $spec['operators']);
        $this->assertNotContains('FREE_TEXT', $spec['operators']);
    }

    public function test_version_is_non_empty_and_spec_is_deterministic(): void
    {
        $this->assertNotSame('', AtlasCortexQueryLanguageGrammar::version());

        $first = AtlasCortexQueryLanguageGrammar::spec();
        $second = AtlasCortexQueryLanguageGrammar::spec();

        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
