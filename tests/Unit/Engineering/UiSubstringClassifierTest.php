<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\EngineeringPhasePlannerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `ui` como substring em texto portugues.
 *
 * Medido antes do conserto, com criterios de aceite realistas: 8 de 9 casavam por
 * acidente — aqui · muito · construir · seguir · cuidado · requisito · gratuito ·
 * circuito — e a UNICA frase que era mesmo de interface, "ajustar a tela de login", NAO
 * casava. O classificador estava invertido na pratica.
 *
 * E o custo nao e um rotulo errado: `manual_qa` num sistema com zero humano no loop
 * significa NUNCA VERIFICADO. O acidente de substring convertia criterio testavel em
 * criterio que ninguem confere, em silencio — a forma mais cara de erro que este corpus
 * persegue, porque produz verde sem medir.
 */
final class UiSubstringClassifierTest extends TestCase
{
    private function metodo(string $texto): string
    {
        $m = new ReflectionMethod(EngineeringPhasePlannerService::class, 'verificationMethod');
        $m->setAccessible(true);

        return (string) $m->invoke(app(EngineeringPhasePlannerService::class), $texto);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function criteriosEmPortuguesQueNaoSaoDeInterface(): array
    {
        return [
            'aqui' => ['corrigir o bug que aparece aqui no parser'],
            'muito' => ['isso e muito importante para o release'],
            'construir' => ['construir o indice de codigo incremental'],
            'seguir' => ['seguir o padrao que ja existe no repositorio'],
            'cuidado' => ['precisa de cuidado com a ordem das chamadas'],
            'requisito' => ['o requisito e nao quebrar o gate'],
            'gratuito' => ['gratuito para o operador'],
            'circuito' => ['reduzir o circuito de retry'],
        ];
    }

    #[Test]
    #[DataProvider('criteriosEmPortuguesQueNaoSaoDeInterface')]
    public function portugues_comum_nao_vira_verificacao_manual(string $criterio): void
    {
        $this->assertSame(
            'test',
            $this->metodo($criterio),
            'acidente de substring nao pode tirar o criterio da verificacao automatica — '
            .'manual_qa sem humano no loop e o mesmo que nao verificar'
        );
    }

    #[Test]
    public function trabalho_de_interface_de_verdade_continua_indo_para_qa_manual(): void
    {
        // O outro lado da rede. Sem ele, esvaziar a lista faria os testes acima passarem.
        $this->assertSame('manual_qa', $this->metodo('ajustar a tela de login'));
        $this->assertSame('manual_qa', $this->metodo('revisar a UI do painel'));
        $this->assertSame('manual_qa', $this->metodo('conferir o visual em mobile'));
        $this->assertSame('manual_qa', $this->metodo('anexar screenshot da correcao'));
    }

    #[Test]
    public function o_ramo_de_banco_continua_ganhando_do_de_interface(): void
    {
        // Precedencia preservada: migration decide antes, mesmo com "tela" na frase.
        $this->assertSame('database_review', $this->metodo('rodar a migration e conferir a tela depois'));
    }
}
