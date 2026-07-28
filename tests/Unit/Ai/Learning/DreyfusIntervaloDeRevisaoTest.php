<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Learning;

use App\Services\Ai\Learning\Dreyfus\DreyfusOverlayRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A curva da repeticao espacada.
 *
 * Era `$level >= 4 ? 30 : 14` — plana nos tres primeiros estagios. Um conceito recem-
 * errado voltava no MESMO prazo de um que o operador quase domina, o que inverte o ponto
 * inteiro da tecnica: catorze dias sobre um conceito recem-errado nao e revisao, e
 * reapresentacao. Ele chega la sem nada para lembrar, erra de novo, e o sistema passa a
 * medir esquecimento em vez de retencao.
 */
final class DreyfusIntervaloDeRevisaoTest extends TestCase
{
    #[Test]
    public function o_intervalo_cresce_a_cada_estagio_sem_nenhum_platô(): void
    {
        $intervalos = array_map(
            static fn (int $n): int => DreyfusOverlayRepository::intervaloDeRevisao($n),
            [1, 2, 3, 4, 5]
        );

        // A assercao e sobre a FORMA, nao sobre os numeros: estritamente crescente. Assim
        // o teste continua valendo se a curva for recalibrada, e reprova se alguem voltar
        // a achatar dois estagios no mesmo prazo.
        $ordenado = $intervalos;
        sort($ordenado);
        $this->assertSame($ordenado, $intervalos, 'a curva tem de ser crescente');
        $this->assertSame(count($intervalos), count(array_unique($intervalos)), 'nenhum par de estagios pode dividir o mesmo intervalo');
    }

    #[Test]
    public function conceito_recem_errado_volta_em_dias_nao_em_semanas(): void
    {
        // Errar puxa o estagio para 1. Se o retorno continuasse em 14 dias, errar sairia
        // barato demais: o conceito fraco ficaria fora de vista pelo mesmo tempo que o
        // forte, e a fila de estudo pararia de refletir o que ele nao sabe.
        $this->assertLessThanOrEqual(2, DreyfusOverlayRepository::intervaloDeRevisao(1));
    }

    #[Test]
    public function conceito_dominado_nao_ocupa_a_fila_toda_semana(): void
    {
        $this->assertGreaterThanOrEqual(30, DreyfusOverlayRepository::intervaloDeRevisao(5));
    }

    #[Test]
    public function estagio_fora_da_faixa_e_grampeado_em_vez_de_explodir(): void
    {
        $this->assertSame(DreyfusOverlayRepository::intervaloDeRevisao(1), DreyfusOverlayRepository::intervaloDeRevisao(-3));
        $this->assertSame(DreyfusOverlayRepository::intervaloDeRevisao(5), DreyfusOverlayRepository::intervaloDeRevisao(99));
    }
}
