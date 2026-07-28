<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OperatorLearningSignal;
use App\Services\Ai\Learning\WorkedExample\WorkedExampleRepository;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Transforma decisao declarada do operador em worked example com fading real.
 *
 * POR QUE NAO USAR `atlas:worked-example author`: ele existe e grava, mas o conteudo que
 * produz e ENLATADO — `defaultSolution()` devolve os mesmos 5 passos genericos ("Defina o
 * resultado esperado para X", "Liste restricoes...") com o topico interpolado. Rodar 50
 * vezes daria 50 exemplos identicos e a tabela pareceria cheia. Um numero que sobe sem
 * que nada seja aprendido e exatamente o defeito que este corpus persegue; encher
 * `worked_examples` assim seria trabalho fake com metrica verde.
 *
 * O material verdadeiro ja existe e e do operador: `atlas:study:log` grava spot, decisao,
 * principio e o termo ancorado. Isso E um worked example — falta so a forma.
 *
 * O QUE DESAPARECE NO FADING, e essa e a decisao pedagogica que importa: some o
 * PRINCIPIO, nunca o spot. Fazer sumir o spot seria esconder a pergunta; fazer sumir o
 * principio obriga o operador a produzi-lo de novo, que e a unica coisa que prova que ele
 * o tem. No estagio 5 sobra so a situacao e a pergunta "o que voce faz, e por que".
 *
 * Chute (`confidence_kind = guess`) NAO vira exemplo. Um palpite que acertou nao e
 * principio, e devolve-lo como material de estudo ensinaria o operador a confiar na
 * propria sorte.
 */
class AtlasStudyExampleCommand extends Command
{
    protected $signature = 'atlas:study:example
        {--domain=poker : Dominio de estudo}
        {--limit=20 : Maximo de sinais a converter nesta passada}
        {--dry-run : Mostra o que seria criado, sem gravar}
        {--json : Saida canonica}';

    protected $description = 'Converte decisoes declaradas do operador em worked examples com fading por estagio.';

    public function handle(WorkedExampleRepository $examples): int
    {
        foreach (['operator_learning_signals', 'worked_examples'] as $tabela) {
            if (! DatabaseTableAvailability::has($tabela)) {
                return $this->responde(['ok' => false, 'reason' => 'missing_table', 'table' => $tabela]);
            }
        }

        $domain = (string) $this->option('domain');
        $dryRun = (bool) $this->option('dry-run');

        $sinais = OperatorLearningSignal::query()
            ->where('scope_type', 'domain')
            ->where('scope_id', $domain)
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')) * 4)
            ->get();

        $criados = 0;
        $pulados = ['ja_convertido' => 0, 'nao_declarado' => 0, 'chute' => 0, 'redigido' => 0, 'sem_spot' => 0];
        $amostra = [];

        foreach ($sinais as $sinal) {
            if ($criados >= (int) $this->option('limit')) {
                break;
            }

            $meta = (array) $sinal->metadata;
            if (($meta['operator_text_declared'] ?? false) !== true) {
                $pulados['nao_declarado']++;

                continue;
            }
            if (($meta['confidence_kind'] ?? 'principled') === 'guess') {
                $pulados['chute']++;

                continue;
            }

            // Claim redigido nao vira material de estudo. Quando a privacidade sobe, o
            // texto duravel e trocado por `[redacted:sensitive:<hash>]`; usar isso como
            // "o principio" produziria uma carta que ensina um hash — pior que nao ter
            // carta, porque ocupa o lugar dela e conta como cobertura.
            //
            // Existe um caso real deste tipo no corpus: o PRIMEIRO registro do operador
            // caiu no bug de `rg` casando dentro de "la(rg)a" e foi redigido antes do
            // conserto. O conteudo nao volta — o hash e de mao unica.
            if (($meta['redacted_at_rest'] ?? false) === true
                || str_starts_with((string) $sinal->normalized_claim, '[redacted:')) {
                $pulados['redigido']++;

                continue;
            }

            $spot = $this->refDe((array) $sinal->evidence_refs, 'spot:');
            $decisao = $this->refDe((array) $sinal->evidence_refs, 'decision:');
            if ($spot === null || $decisao === null) {
                $pulados['sem_spot']++;

                continue;
            }

            if ($this->jaConvertido((string) $sinal->id)) {
                $pulados['ja_convertido']++;

                continue;
            }

            $termo = (string) ($meta['vocabulary_term'] ?? '');
            $principio = (string) $sinal->normalized_claim;
            $passos = $this->passos($spot, $decisao, $principio, $termo);

            $amostra[] = ['signal' => (string) $sinal->id, 'termo' => $termo ?: null, 'spot' => $spot];

            if ($dryRun) {
                $criados++;

                continue;
            }

            $examples->create(
                topic: $termo !== '' ? $termo : $domain,
                domain: $domain,
                title: $termo !== '' ? $termo.' — '.mb_strimwidth($spot, 0, 60, '…') : mb_strimwidth($spot, 0, 80, '…'),
                problemContext: $spot,
                solutionFull: $passos,
                fadingLevels: self::FADING,
                source: 'operator_authored',
                authorEvidenceRefs: array_values(array_filter([
                    'signal:'.$sinal->id,
                    $termo !== '' ? 'term:'.$termo : null,
                ])),
            );
            $criados++;
        }

        return $this->responde([
            'ok' => true,
            'dry_run' => $dryRun,
            'domain' => $domain,
            'sinais_lidos' => $sinais->count(),
            'exemplos_criados' => $criados,
            'pulados' => $pulados,
            'amostra' => array_slice($amostra, 0, 5),
            'total_no_dominio' => DB::table('worked_examples')->where('domain', $domain)->count(),
        ]);
    }

    /**
     * O que cada estagio Dreyfus ainda VE. O passo 3 e o principio: ele sai primeiro e
     * nunca volta. O passo 1 (o spot) fica ate o fim, porque e a pergunta, nao a resposta.
     */
    private const FADING = [
        '1' => [1, 2, 3, 4, 5],
        '2' => [1, 2, 3, 4],
        '3' => [1, 2, 4],
        '4' => [1, 2],
        '5' => [1],
    ];

    /**
     * @return list<array<string,mixed>>
     */
    private function passos(string $spot, string $decisao, string $principio, string $termo): array
    {
        return [
            ['step' => 1, 'action' => 'A situacao: '.$spot, 'reasoning' => 'O spot e a pergunta — ele nunca some do exemplo.', 'why_works' => 'Sem a situacao nao ha o que decidir.'],
            ['step' => 2, 'action' => $termo !== '' ? 'O conceito em jogo: '.$termo : 'Nomeie o conceito em jogo.', 'reasoning' => 'Nomear o conceito e o que permite reconhece-lo num spot diferente.', 'why_works' => 'Conceito nomeado transfere; intuicao sem nome nao.'],
            ['step' => 3, 'action' => 'O principio: '.$principio, 'reasoning' => 'Escrito pelo operador ANTES de conhecer o resultado.', 'why_works' => 'E o primeiro a sumir no fading: reproduzi-lo e a prova de que foi aprendido.'],
            ['step' => 4, 'action' => 'A decisao: '.$decisao, 'reasoning' => 'O que ele de fato fez, dado o principio.', 'why_works' => 'Liga principio a acao — sem isso o principio vira slogan.'],
            ['step' => 5, 'action' => 'O que confirmaria ou refutaria esse principio num spot novo.', 'reasoning' => 'Acertar o spot anotado e recall; acertar um spot NOVO pelo mesmo principio e transferencia.', 'why_works' => 'So a transferencia conta como aprendizado.'],
        ];
    }

    /** @param array<int,mixed> $refs */
    private function refDe(array $refs, string $prefixo): ?string
    {
        foreach ($refs as $ref) {
            if (is_string($ref) && str_starts_with($ref, $prefixo)) {
                $valor = trim(substr($ref, strlen($prefixo)));

                return $valor !== '' ? $valor : null;
            }
        }

        return null;
    }

    /**
     * Idempotencia: rodar duas vezes nao pode dobrar o material de estudo. A marca e o id
     * do sinal dentro de `author_evidence_refs`, que ja e o campo de proveniencia.
     */
    private function jaConvertido(string $signalId): bool
    {
        return DB::table('worked_examples')
            ->where('author_evidence_refs', 'like', '%"signal:'.$signalId.'"%')
            ->exists();
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
