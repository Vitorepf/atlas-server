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

    public function test_detects_the_proven_truncation_shape(): void
    {
        // Caso real 20/07 (GAP-HERMES-01): modelo gerou ~2.000 tokens, stdout
        // entregou 856 bytes de prosa cortada — fisicamente impossível íntegro.
        $this->assertTrue($this->truncated(['output_tokens' => 2000], str_repeat('a', 856)));
    }

    public function test_complete_output_never_flags(): void
    {
        // ~4 chars/token reais: 100 tokens → 400+ chars. Limiar de 1 char/token
        // não pode dar falso-positivo em resposta íntegra.
        $this->assertFalse($this->truncated(['output_tokens' => 100], str_repeat('a', 400)));
        $this->assertFalse($this->truncated(['output_tokens' => 100], str_repeat('a', 100)));
    }

    public function test_no_usage_means_no_verdict(): void
    {
        // Sem verdade-terrestre não há como afirmar truncamento — nunca chutar.
        $this->assertFalse($this->truncated([], ''));
        $this->assertFalse($this->truncated(['output_tokens' => 0], ''));
    }
}
