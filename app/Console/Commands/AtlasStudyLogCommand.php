<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A porta pela qual o operador declara as PRÓPRIAS palavras.
 *
 * Por que ela precisou existir: `OperatorLearningRuntimeCaptureService` exige
 * `payload.operator_text` e recusa tudo que não o traga — com recibo contável
 * (`operator_text_not_declared`), não em silêncio. Essa guarda é deliberada: antes
 * dela, o detector aprendeu o PREÂMBULO de um prompt de subagente como se fosse
 * padrão do operador, e as três primeiras `operator_skill_proposals` eram a máquina
 * se ouvindo. A lei que ficou é "não declarado = desconhecido, e desconhecido não se
 * aprende" — não aprender é recuperável, gravar a voz da máquina como regra dele não é.
 *
 * O efeito colateral da lei era o órgão inteiro ficar mudo, porque o único caller de
 * `captureFromTrace` é `AiGatewayService:668` — ou seja, a captura só acontecia dentro
 * de uma chamada de provider. Registrar uma decisão não pode custar tokens.
 *
 * Este comando escreve pela porta declarada (`OperatorSignalCaptureService::capture`),
 * sem gateway e sem provider, marcando `metadata.operator_text_declared` — que é o
 * campo exato que `OperatorPatternDetector:77` exige para minerar padrão.
 *
 * O que ele NÃO faz: julgar se a decisão foi boa. Ele registra o COMPROMISSO — o que
 * foi decidido, por quê, e com quanta certeza — ANTES de o resultado ser conhecido.
 * É essa ordem que separa aprendizado de racionalização: quem escreve o motivo depois
 * de ver o resultado está explicando a sorte, não o princípio.
 */
class AtlasStudyLogCommand extends Command
{
    protected $signature = 'atlas:study:log
        {--spot= : A situação — o que estava na mesa/tela quando você decidiu}
        {--decision= : O que você decidiu fazer}
        {--why= : O PRINCÍPIO por trás, não a descrição da jogada}
        {--confidence= : principled|guess — decidiu por princípio, ou chutou}
        {--term= : Termo do vocabulário que ancora (ex.: um verbete do Dicionário)}
        {--domain=poker : Domínio de estudo}
        {--operator= : Id do operador}
        {--import= : Caminho de um JSON [{spot,decision,why,confidence?,term?}] para registrar em lote}
        {--dry-run : Mostra o que seria gravado, sem gravar}
        {--json : Saída canônica}';

    protected $description = 'Registra uma decisão do operador ANTES do resultado — o compromisso que torna a prática deliberada.';

    public function handle(OperatorSignalCaptureService $capture): int
    {
        if ($this->option('import')) {
            return $this->importar($capture, (string) $this->option('import'));
        }

        $resultado = $this->registrar($capture, [
            'spot' => $this->option('spot'),
            'decision' => $this->option('decision'),
            'why' => $this->option('why'),
            'confidence' => $this->option('confidence'),
            'term' => $this->option('term'),
        ]);

        return $this->responde($resultado);
    }

    /**
     * Registra UMA decisao. Unico lugar onde as regras vivem — o lote passa por aqui
     * tambem, entao nao ha um segundo conjunto de guardas mais frouxo para o caminho
     * "conveniente". Toda vez que a validacao e duplicada para um atalho, e o atalho que
     * vira a porta de entrada do dado ruim.
     *
     * @param  array<string,mixed>  $entrada
     * @return array<string,mixed>
     */
    private function registrar(OperatorSignalCaptureService $capture, array $entrada): array
    {
        $spot = trim((string) ($entrada['spot'] ?? ''));
        $decision = trim((string) ($entrada['decision'] ?? ''));
        $why = trim((string) ($entrada['why'] ?? ''));
        $confidence = trim((string) ($entrada['confidence'] ?? '')) ?: 'principled';

        // Fail-closed nos três que carregam o aprendizado. `--term` é opcional porque
        // nem todo princípio tem verbete; os outros três não têm substituto: sem spot
        // não há transferência (é o que muda no teste futuro), sem decisão não há o que
        // julgar, e sem o porquê o registro vira diário, não estudo.
        $faltando = array_keys(array_filter([
            'spot' => $spot === '',
            'decision' => $decision === '',
            'why' => $why === '',
        ]));
        if ($faltando !== []) {
            return $this->recusa('missing_required', [
                'missing' => $faltando,
                'hint' => 'o porquê é o PRINCÍPIO ("contra esse perfil a range é polarizada"), não a jogada ("dei fold")',
            ]);
        }

        if (! in_array($confidence, ['principled', 'guess'], true)) {
            return $this->recusa('invalid_confidence', [
                'given' => $confidence,
                'allowed' => ['principled', 'guess'],
                'why_it_matters' => 'chutar e acertar não é aprender; sem esse campo os dois "acertou" viram o mesmo número',
            ]);
        }

        if (! DatabaseTableAvailability::has('operator_learning_signals')) {
            return $this->recusa('missing_operator_tables', ['table' => 'operator_learning_signals']);
        }

        $dominio = (string) $this->option('domain');
        $termo = trim((string) ($entrada['term'] ?? ''));
        $verbete = null;

        // ANCORA VERIFICADA. Ancorar num termo que ninguem definiu e decoracao: o registro
        // parece ligado ao vocabulario e nao esta, e a mentira so aparece meses depois,
        // quando o operador procura "todo principio sobre c-bet" e volta vazio.
        //
        // O campo `taxonomy_item_id` NAO serve para isto, e isso foi medido: ele pertence
        // ao registro de 170 itens sobre QUEM O OPERADOR E, e `normalizeTaxonomy` descarta
        // qualquer valor fora do formato SYS|OP|COL-NNN. Passar "ABI" ali devolvia
        // `OP-071` — "Seu jeito preferido de receber resposta". A ancora de dominio viaja
        // em metadata, onde e consultavel e onde nada a reescreve.
        if ($termo !== '') {
            if (! DatabaseTableAvailability::has('atlas_study_vocabulary')) {
                return $this->recusa('missing_vocabulary_table', ['table' => 'atlas_study_vocabulary']);
            }

            $verbete = DB::table('atlas_study_vocabulary')
                ->where('domain', $dominio)
                ->where('term_normalized', AtlasStudyVocabCommand::normalizar($termo))
                ->first();

            if ($verbete === null) {
                return $this->recusa('unknown_vocabulary_term', [
                    'term' => $termo,
                    'domain' => $dominio,
                    'hint' => 'importe o corpus com `atlas:study:vocab --import=` ou procure o termo certo com `--search=`',
                    'why_it_matters' => 'ancora nao verificada e decoracao: o principio parece ligado ao vocabulario e nao esta',
                ]);
            }
        }

        $result = $capture->capture([
            'operator_id' => $this->option('operator') ?: null,
            'claim' => $why,
            'raw_excerpt' => $spot."\n".$decision."\n".$why,
            'signal_kind' => 'operator_decision',
            'source_type' => 'operator_declared',
            'scope_type' => 'domain',
            'scope_id' => $dominio,
            'evidence_refs' => array_values(array_filter([
                'spot:'.$spot,
                'decision:'.$decision,
                $verbete !== null ? 'term:'.$verbete->term : null,
            ])),
            // O eixo que separa sorte de princípio. `guess` entra baixo de propósito:
            // um palpite que acertou não deve pesar como regra do operador.
            'confidence' => $confidence === 'principled' ? 0.9 : 0.25,
            'inference_type' => 'explicit',
            'dry_run' => (bool) $this->option('dry-run'),
            'metadata' => [
                // O campo que o detector exige. É a diferença entre "o operador disse"
                // e "o harness inferiu" — e é por isso que ele viaja explícito.
                'operator_text_declared' => true,
                'commitment_before_outcome' => true,
                'confidence_kind' => $confidence,
                'study_domain' => $dominio,
                // A ancora, ja resolvida contra o vocabulario. Guardar a forma NORMALIZADA
                // e o que torna a volta possivel: o operador digita "C-Bet", "c-bet" ou
                // "cbet" e as tres tem de achar o mesmo conjunto de principios.
                'vocabulary_term' => $verbete?->term,
                'vocabulary_term_normalized' => $verbete?->term_normalized,
            ],
        ]);

        return array_merge($result, [
            'schema_version' => 'atlas.study.log.v1',
            'confidence_kind' => $confidence,
            'vocabulary_term' => $verbete?->term,
        ]);
    }

    /**
     * Lote. O atrito de uma chamada de terminal por mao e o que mata o habito: quem joga
     * 40 SNGs nao para 40 vezes para digitar um comando. Aqui ele escreve as maos que
     * HESITOU num arquivo e registra tudo de uma vez.
     *
     * Cada linha passa pelo MESMO `registrar()`, com as mesmas guardas. Uma linha ruim
     * nao derruba o lote nem contamina as boas: ela e recusada com o indice e o motivo,
     * e o comando so devolve sucesso se TODAS entraram. Lote que engole recusa e pior que
     * lote nenhum — o operador acha que registrou 20 e registrou 17.
     */
    private function importar(OperatorSignalCaptureService $capture, string $caminho): int
    {
        if (! is_file($caminho)) {
            return $this->responde($this->recusa('file_not_found', ['path' => $caminho]));
        }

        $linhas = json_decode((string) file_get_contents($caminho), true);
        if (! is_array($linhas)) {
            return $this->responde($this->recusa('invalid_json', ['path' => $caminho]));
        }

        $gravados = 0;
        $recusados = [];

        foreach (array_values($linhas) as $i => $linha) {
            if (! is_array($linha)) {
                $recusados[] = ['linha' => $i, 'reason' => 'not_an_object'];

                continue;
            }

            $resultado = $this->registrar($capture, $linha);
            if (($resultado['ok'] ?? false) === true) {
                $gravados++;

                continue;
            }

            $recusados[] = array_merge(
                ['linha' => $i, 'spot' => mb_strimwidth((string) ($linha['spot'] ?? ''), 0, 60, '…')],
                array_intersect_key($resultado, array_flip(['reason', 'missing', 'term', 'given']))
            );
        }

        return $this->responde([
            'ok' => $recusados === [],
            'dry_run' => (bool) $this->option('dry-run'),
            'lidos' => count($linhas),
            'gravados' => $gravados,
            'recusados' => count($recusados),
            'detalhe_das_recusas' => $recusados,
            'hint' => $recusados === [] ? null : 'corrija as linhas recusadas e reimporte — as gravadas nao serao duplicadas se voce remover as ja aceitas',
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function recusa(string $reason, array $extra = []): array
    {
        return array_merge(['ok' => false, 'reason' => $reason], $extra);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function responde(array $payload): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
