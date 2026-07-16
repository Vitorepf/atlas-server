<?php

declare(strict_types=1);

use App\Services\AtlasCode\AtlasCodeWeekService;
use Tests\TestCase;

final class AtlasCodeWeekServiceTest extends TestCase
{
    public function test_week_card_returns_real_window_and_only_agents_that_exist(): void
    {
        $result = app(AtlasCodeWeekService::class)->capture('atlas-server');

        self::assertSame(AtlasCodeWeekService::SCHEMA_VERSION, $result['schema_version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2}$/', $result['window']);
        self::assertIsInt($result['commits']);
        self::assertIsInt($result['heals']);
        self::assertIsInt($result['prevented']);
        self::assertFalse($result['notifications']['enabled']);

        // A versão anterior deste teste EXIGIA os quatro baldes fixos:
        //
        //   assertSame(['fable', 'codex', 'voce', 'autonomo:desconhecido'], …)
        //
        // Ele certificava a mentira em vez de pegá-la. `fable` e `codex` nunca
        // podiam sair de zero — `agentForAuthor()` só devolve `voce` ou
        // `autonomo:desconhecido` —, então a folha da semana publicava dois
        // zeros FABRICADOS com cara de medição, e o teste verde garantia que
        // continuassem lá. Teste que trava o defeito no lugar é pior que teste
        // nenhum: ele promove o bug a contrato.
        //
        // Zero medido e zero inventado se escrevem igual na tela. É por isso
        // que inventar zero é caro: some a diferença entre "não trabalhou" e
        // "não sei olhar".
        foreach ($result['by_agent'] as $agent => $count) {
            self::assertIsInt($count);
            self::assertGreaterThan(0, $count, "balde '{$agent}' zerado é fabricação: quem não commitou não aparece");
        }

        // A soma dos baldes É a contagem de commits: número que não fecha com o
        // número ao lado é a tela discutindo consigo mesma na frente do
        // operador.
        self::assertSame($result['commits'], array_sum($result['by_agent']));
    }
}
