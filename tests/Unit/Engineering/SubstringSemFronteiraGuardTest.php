<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda contra a REINTRODUCAO do defeito, nao contra uma instancia dele.
 *
 * O padrao ja apareceu quatro vezes neste corpus, sempre com o mesmo formato e sempre
 * caro de achar:
 *   `rg` casando dentro de "la(rg)a"      -> apagava o principio do operador no banco
 *   `tom` dentro de "(tom)ar"/"bot(tom)"  -> decisao de poquer virava preferencia de texto
 *   `ui`  dentro de "aq(ui)"/"m(ui)to"    -> criterio testavel virava manual_qa
 *   `api` dentro de "r(api)da"            -> frase comum exigia disciplina de api_first
 *
 * Consertar as instancias nao impede a quinta. Esta guarda le o PROPRIO fonte e reprova
 * quando `str_contains` volta a ser usado com agulha de 2-3 letras — o tamanho em que o
 * acidente e praticamente certo em portugues.
 *
 * A allowlist existe porque nem todo caso e defeito: quando o palheiro e um SLUG ou um
 * caminho (`$surface`, `$sourceType`, nome de arquivo), substring e a semantica correta e
 * a fronteira de palavra e que estaria errada. Cada isencao e nominal, para que adicionar
 * uma seja uma decisao visivel em vez de um afrouxamento geral.
 */
final class SubstringSemFronteiraGuardTest extends TestCase
{
    /** Agulhas curtas que sao substring de palavra portuguesa/inglesa comum. */
    private const AGULHAS_PERIGOSAS = ['ui', 'api', 'rg', 'tom', 'key', 'app', 'ux'];

    /**
     * Palheiros onde substring e CORRETO: slug, caminho, comando, identificador.
     * Formato: 'caminho/relativo.php:numero_da_linha'.
     */
    private const ISENCOES = [
        // `$surface` e slug de superficie ("atlas_cli", "api"), nunca frase.
        'app/Services/Ai/Decide/KernelContractSection.php',
        // `app` e pedido de aplicativo: "aplicativo" e "app" sao a mesma coisa.
        'app/Services/Engineering/EngineeringProjectBlueprintService.php',
    ];

    #[Test]
    public function nenhum_classificador_novo_usa_agulha_curta_sem_fronteira(): void
    {
        $suspeitos = [];
        $raiz = base_path('app');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
        foreach ($iterator as $arquivo) {
            if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
                continue;
            }
            $relativo = str_replace(base_path().'/', '', $arquivo->getPathname());
            if (in_array($relativo, self::ISENCOES, true)) {
                continue;
            }

            foreach (file($arquivo->getPathname()) ?: [] as $n => $linha) {
                foreach (self::AGULHAS_PERIGOSAS as $agulha) {
                    if (preg_match('/str_contains\([^,]+,\s*\''.$agulha.'\'\)/', $linha) === 1) {
                        $suspeitos[] = $relativo.':'.($n + 1).'  agulha='.$agulha;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $suspeitos,
            "str_contains com agulha de 2-3 letras casa DENTRO de palavra comum e ja custou "
            ."quatro defeitos neste corpus. Use `preg_match('/\\bagulha\\b/u', ...)`, ou "
            ."adicione o arquivo a ISENCOES se o palheiro for slug/caminho — com o motivo.\n  "
            .implode("\n  ", $suspeitos)
        );
    }
}
