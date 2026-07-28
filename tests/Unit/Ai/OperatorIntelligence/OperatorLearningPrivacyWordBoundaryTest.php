<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningClassifySupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A rede do bug que destruía o corpus de aprendizado em silêncio.
 *
 * `inferPrivacy` usava `str_contains`, então o termo `rg` (o documento) casava dentro
 * de "la**rg**a", "ca**rg**a", "ene**rg**ia", "ma**rg**em", "ta**rg**et". E o efeito
 * não era um rótulo errado: privacidade `sensitive` dispara `redactIfSensitive`, que
 * substitui o texto durável por um hash. **Todo princípio em português com essas três
 * letras perdia o conteúdo no banco** — e o primeiro registro real do operador caiu
 * nisso, com "range larga".
 *
 * O erro espelhado era invisível: sem dobrar acento, "saúde" não casava "saude" —
 * falso negativo no termo que a lista existe para pegar. Um lado apagava o que devia
 * guardar; o outro guardava o que devia proteger.
 *
 * Por isso o teste cobre as DUAS direções. Uma rede que só provasse "não redige demais"
 * passaria com a lista vazia.
 */
final class OperatorLearningPrivacyWordBoundaryTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function palavrasQueContemTermoMasNaoSao(): array
    {
        return [
            'larga contém rg' => ['contra esse perfil a range fica larga'],
            'carga contém rg' => ['a carga do argumento nao se sustenta'],
            'energia contém rg' => ['energia urgente no fim da sessao'],
            'margem contém rg' => ['a margem de erro cabe no stack'],
            'target contém rg' => ['o target de organizacao mudou'],
            'monkey contém key' => ['monkey testing no teclado'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('palavrasQueContemTermoMasNaoSao')]
    public function substring_dentro_de_palavra_nao_redige(string $claim): void
    {
        $this->assertSame(
            'normal',
            OperatorLearningClassifySupport::inferPrivacy($claim),
            'substring dentro de palavra maior nao pode disparar redacao — o texto durável some'
        );
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function termosReaisQueDevemDisparar(): array
    {
        return [
            'rg isolado' => ['meu rg esta vencido', 'sensitive'],
            'saude com acento' => ['cuidar da saúde antes do grind', 'sensitive'],
            'familia com acento' => ['jantar com a família no domingo', 'sensitive'],
            'senha' => ['a senha do banco mudou', 'secret'],
            'token' => ['o token expirou ontem', 'secret'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('termosReaisQueDevemDisparar')]
    public function termo_real_continua_protegido(string $claim, string $esperado): void
    {
        $this->assertSame(
            $esperado,
            OperatorLearningClassifySupport::inferPrivacy($claim),
            'a protecao nao pode ser afrouxada pelo conserto do falso positivo'
        );
    }

    #[Test]
    public function acento_nao_esconde_o_termo(): void
    {
        // O par que prova a dobra: as duas formas do mesmo termo têm de decidir igual.
        $this->assertSame(
            OperatorLearningClassifySupport::inferPrivacy('minha saude'),
            OperatorLearningClassifySupport::inferPrivacy('minha saúde'),
            'acento nao pode mudar o veredito de privacidade'
        );
    }
}
