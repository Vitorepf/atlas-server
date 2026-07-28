<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AtlasPhpBinary;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * O gate de 17 segundos que teria poupado quatro dias de cegueira.
 *
 * EM 24/07 `AtlasEvidenceLedger::eventsForScope` ganhou um parametro. Duas classes de
 * teste que a estendem nao acompanharam. Incompatibilidade de assinatura em PHP e FATAL,
 * nao falha de teste: o processo MORRE e a suite para ali. De 24 a 28/07 `php artisan
 * test` nunca chegou ao fim — e ninguem viu, porque saida parcial parece suite passando
 * ate o ponto em que ela morre. Sinal ausente disfarcado de sinal parcial.
 *
 * O efeito nao foi um teste vermelho, foi PERDER O QUADRO INTEIRO. Nesta sessao
 * apareceram tres testes desatualizados em relacao ao codigo, dois deles ha semanas, e a
 * pergunta que faltava era "por que ninguem viu".
 *
 * POR QUE NENHUM GATE EXISTENTE PEGAVA, medido um a um:
 *   `atlas:pregate` roda phpstan, mas ESCOPADO NOS CAMINHOS dados. A mudanca foi na
 *     classe MAE em `app/`; as filhas em `tests/` nunca entraram no escopo.
 *   `phpstan.neon` NAO declara `paths:` — so analisa o que recebe na linha de comando.
 *   O committer vivo verifica os testes DA PROPRIA TASK, e tem ramos fail-open.
 *   A suite completa: 10+ minutos. `phpstan` sobre `tests/` inteiro: 625 segundos.
 *
 * E POR QUE A PRIMEIRA VERSAO DISTO FOI DESCARTADA: tentei `phpunit --list-tests`,
 * apostando que carregar toda classe revelaria o fatal. Medido: NAO revela. A classe
 * incompativel e ANONIMA, declarada dentro de um metodo — so passa a existir quando o
 * metodo roda, nunca na carga. O comando ficou pronto, foi testado contra o defeito real,
 * falhou em peg -lo, e nao foi commitado.
 *
 * O que funciona e cirurgico: `phpstan` sobre os ~100 arquivos que dublam uma classe
 * NAO-TestCase. 17 segundos, e a mensagem vem exata:
 *   "overrides method ...::eventsForScope() but misses parameter #4 $tenantId"
 *
 * O gate reporta SO erro de compatibilidade de override. Ruido de tipo nos mesmos
 * arquivos e outro assunto — um gate que reprova por tudo vira um gate que ninguem roda.
 */
class AtlasTestsDoublesCommand extends Command
{
    /**
     * Arquivo de teste que estende algo que NAO e TestCase — ou seja, que dubla uma classe
     * de producao e portanto tem de acompanhar a assinatura dela.
     */
    private const PADRAO_DUBLE = 'new class extends (?!TestCase)[A-Z]|^(?:final |abstract )?class \w+ extends (?!TestCase\b)[A-Z]\w*(?:\s|$)';

    protected $signature = 'atlas:tests:doubles
        {--json : Saida canonica}';

    protected $description = 'Verifica que todo dublê de teste continua compatível com a assinatura da classe que ele dubla — o fatal que aborta a suíte inteira.';

    public function handle(): int
    {
        $inicio = microtime(true);

        $busca = new Process(
            ['rg', '-l', '-P', self::PADRAO_DUBLE, 'tests/', '--no-ignore'],
            base_path(), null, null, 120.0
        );
        try {
            $busca->run();
        } catch (\Throwable $e) {
            // `rg` ausente do PATH e o modo de falha mais provavel de um job agendado: o
            // ambiente do scheduler nao e o do terminal. Sem este ramo, "binario nao
            // encontrado" e "repo limpo" produziriam a MESMA saida, e o operador iria
            // procurar dublê quebrado onde o problema e PATH.
            return $this->responde([
                'ok' => false,
                'reason' => 'busca_indisponivel',
                'erro' => $e->getMessage(),
                'hint' => 'o gate depende de `rg` no PATH — no scheduler o ambiente nao e o do terminal',
                'segundos' => round(microtime(true) - $inicio, 1),
            ]);
        }

        // `rg` sai com 1 quando nao acha nada (normal) e com 2 em erro de uso/regex. 127 e
        // "binario nao encontrado" — o Process NAO lanca nesse caso, devolve o codigo, e
        // por isso o ramo de excecao acima sozinho nao bastava (medido: a mutacao que tira
        // o `rg` do PATH cai AQUI, nao la).
        $codigo = $busca->getExitCode();
        if ($codigo !== null && $codigo > 1) {
            return $this->responde([
                'ok' => false,
                'reason' => $codigo === 127 ? 'busca_indisponivel' : 'busca_falhou',
                'exit_code' => $codigo,
                'erro' => trim($busca->getErrorOutput()),
                'hint' => $codigo === 127
                    ? 'o gate depende de `rg` no PATH — no scheduler o ambiente nao e o do terminal'
                    : 'o padrao de busca foi recusado pelo rg',
                'segundos' => round(microtime(true) - $inicio, 1),
            ]);
        }

        $arquivos = array_values(array_filter(
            preg_split('/\R/', trim($busca->getOutput())) ?: [],
            static fn (string $l): bool => $l !== ''
        ));

        if ($arquivos === []) {
            // Zero arquivos e SUSPEITO, nao limpo: este repo tem ~100. Ausencia de alvo
            // virando verde e o defeito que este gate existe para nao repetir.
            return $this->responde([
                'ok' => false,
                'reason' => 'nenhum duble encontrado',
                'hint' => 'o padrao de busca provavelmente quebrou — zero dublês num repo que tem ~100 nao e "limpo", e cego',
                'segundos' => round(microtime(true) - $inicio, 1),
            ]);
        }

        $stan = new Process(
            array_merge(
                [AtlasPhpBinary::path(), 'vendor/bin/phpstan', 'analyse'],
                $arquivos,
                ['--memory-limit=4G', '--no-progress', '--error-format=raw']
            ),
            base_path(), null, null, 600.0
        );
        $stan->run();

        $incompativeis = [];
        foreach (preg_split('/\R/', $stan->getOutput().$stan->getErrorOutput()) ?: [] as $linha) {
            if (preg_match('/(overrides method .* but |must be compatible with|parameter\.missing)/i', $linha) === 1) {
                $incompativeis[] = trim($linha);
            }
        }

        return $this->responde([
            'ok' => $incompativeis === [],
            'dubles_verificados' => count($arquivos),
            'incompatibilidades' => count($incompativeis),
            'detalhe' => array_slice($incompativeis, 0, 10),
            'segundos' => round(microtime(true) - $inicio, 1),
            'veredito' => $incompativeis === []
                ? 'todo dublê acompanha a assinatura da classe que dubla'
                : 'assinatura divergente: quando este arquivo rodar, o PHP mata o processo e a suíte para ali — tudo depois nunca roda',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function responde(array $payload): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($this->option('json') ? 0 : JSON_PRETTY_PRINT);
        $this->line((string) json_encode($payload, $flags));

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
