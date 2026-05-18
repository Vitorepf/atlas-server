<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Programming\Kernel\ProgrammingDomainKernelCanon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgrammingAdapterDevRoutingTest extends TestCase
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function intentProvider(): array
    {
        return [
            'debug' => ['debug racing condition no worker', 'programming.repair'],
            'fix' => ['fix typo no login form', 'programming.repair'],
            'corrigir' => ['corrigir erro de validacao no controller', 'programming.repair'],
            'review' => ['review do PR #123', 'programming.review'],
            'revisar' => ['revisar mudanca no AuthGuard', 'programming.review'],
            'refactor' => ['refactor do UserService', 'programming.refactor'],
            'refatorar' => ['refatorar AtlasDevRuntimeService', 'programming.refactor'],
            'test' => ['rodar test suite phpunit', 'programming.qa'],
            'plan' => ['plan feature de notificacoes', 'programming.dev'],
            'security' => ['security review de auth flow', 'programming.security'],
            'database' => ['adicionar migration de billing', 'programming.database'],
            'visual' => ['ajustar visual responsivo da landing', 'programming.visual'],
            'default_dev' => ['implementar feature pequena', 'programming.dev'],
        ];
    }

    #[DataProvider('intentProvider')]
    public function test_dev_intent_classifies_to_expected_capability(string $prompt, string $expectedCapability): void
    {
        $this->assertSame(
            $expectedCapability,
            ProgrammingDomainKernelCanon::classifyDevCapability($prompt),
        );
    }
}
