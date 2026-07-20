<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\HermesCliProvider;
use PHPUnit\Framework\TestCase;

class HermesOutputCompletenessGuardTest extends TestCase
{
    private function truncated(array $usage, string $output): bool
    {
        $provider = (new \ReflectionClass(HermesCliProvider::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(HermesCliProvider::class, 'outputLooksTruncated');

        return (bool) $method->invoke($provider, $usage, $output);
    }

    public function test_incomplete_session_flags_truncation(): void
    {
        // GAP-HERMES-01: sessão que o próprio hermes declara não-completada =
        // resposta parcial → retry. É o veredito explícito, não heurística.
        $this->assertTrue($this->truncated(['completed' => false, 'failed' => false], 'prosa cortada'));
        $this->assertTrue($this->truncated(['failed' => true], ''));
    }

    public function test_completed_session_never_flags(): void
    {
        // Resposta curta legítima ("OK" com 22 output_tokens de agente) NÃO
        // pode virar retry — falso-positivo provado ao vivo 20/07 quando a
        // guarda usava bytes-vs-tokens.
        $this->assertFalse($this->truncated(['completed' => true, 'failed' => false, 'output_tokens' => 22], 'OK'));
    }

    public function test_no_usage_fields_means_no_verdict(): void
    {
        // Sem verdade-terrestre (hermes antigo, sem campos) — nunca chutar.
        $this->assertFalse($this->truncated([], ''));
        $this->assertFalse($this->truncated(['output_tokens' => 2000], str_repeat('a', 10)));
    }
}
