<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OperatorLearningSignal;
use App\Services\Ai\Learning\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Learning\WorkedExample\ProcessFadingScheduler;
use App\Services\Ai\Learning\WorkedExample\WorkedExampleRepository;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * O laco que separa RECALL de TRANSFERENCIA — e e a unica coisa aqui que mede aprendizado.
 *
 * A regra que governa tudo: acertar o spot ANOTADO nao promove ninguem. Se reler a propria
 * anotacao contasse como aprender, bastaria repetir a carta para "evoluir", e o numero
 * subiria enquanto o operador continuasse sem saber jogar. Recall e reconhecimento; so
 * aplicar o mesmo principio a um spot que ele NUNCA viu prova que o principio e dele.
 *
 * De onde sai o spot novo, sem inventar nada: dos proprios registros do operador. Se o
 * termo "C-bet" ancora dois sinais diferentes, o exemplo autorado a partir do sinal A e
 * entregue com o spot do sinal B, e o principio escondido. Ele tem de produzi-lo de novo,
 * num contexto diferente. Se so existe um sinal para o termo, a entrega e honestamente
 * marcada `recall` e NAO promove — a ausencia de material de transferencia e declarada,
 * nao disfarcada.
 *
 * O que cada resultado faz com o estagio:
 *   acertou em transfer  -> sobe (ate 5) — a unica porta de promocao
 *   acertou em recall    -> NAO sobe, so registra; reconhecer nao e saber
 *   errou                -> desce (ate 1); o fading volta a mostrar mais
 *
 * A repeticao espacada ja estava no schema e nunca foi escrita: `next_validation_at` no
 * overlay (14 dias, 30 a partir do estagio 4) decide quando a carta volta.
 */
class AtlasStudyReviewCommand extends Command
{
    protected $signature = 'atlas:study:review
        {--domain=poker : Dominio de estudo}
        {--grade= : Id do worked example sendo julgado (modo julgamento)}
        {--correct : O operador acertou}
        {--wrong : O operador errou}
        {--mode= : recall|transfer — o modo em que a carta foi entregue}
        {--force : Entrega mesmo que nenhuma carta esteja vencida}
        {--json : Saida canonica}';

    protected $description = 'Entrega uma carta de estudo com o principio escondido, e julga o resultado separando recall de transferencia.';

    public function handle(
        WorkedExampleRepository $examples,
        ProcessFadingScheduler $fading,
        DreyfusOverlayRepository $overlays,
    ): int {
        foreach (['worked_examples', 'dreyfus_overlays'] as $tabela) {
            if (! DatabaseTableAvailability::has($tabela)) {
                return $this->responde(['ok' => false, 'reason' => 'missing_table', 'table' => $tabela]);
            }
        }

        return $this->option('grade')
            ? $this->julgar($overlays, (string) $this->option('grade'))
            : $this->entregar($examples, $fading, $overlays);
    }

    private function entregar(WorkedExampleRepository $examples, ProcessFadingScheduler $fading, DreyfusOverlayRepository $overlays): int
    {
        $domain = (string) $this->option('domain');

        $carta = $this->cartaVencida($domain, (bool) $this->option('force'));
        if ($carta === null) {
            return $this->responde([
                'ok' => false,
                'reason' => 'nothing_due',
                'domain' => $domain,
                'total_cartas' => DB::table('worked_examples')->where('domain', $domain)->where('status', 'active')->count(),
                'hint' => 'use --force para entregar mesmo sem vencimento, ou registre mais decisoes com `atlas:study:log`',
            ]);
        }

        $overlay = $overlays->find((string) $carta->knowledge_node_id, $domain);
        $estagio = (int) ($overlay['current_level'] ?? 1);

        $passos = (array) json_decode((string) $carta->solution_full, true);
        $plano = $fading->schedule($estagio, (array) json_decode((string) $carta->fading_levels, true));
        $visiveis = array_map('intval', (array) ($plano['visible_steps'] ?? []));

        [$modo, $spot, $spotRef] = $this->escolherSpot($carta, $domain);

        $examples->markDelivered((int) $carta->id);

        return $this->responde([
            'ok' => true,
            'modo' => $modo,
            'domain' => $domain,
            'worked_example_id' => (int) $carta->id,
            'estagio_dreyfus' => $estagio,
            'entrega_numero' => (int) $carta->delivered_count + 1,
            'spot' => $spot,
            'spot_origem' => $spotRef,
            'pergunta' => $modo === 'transfer'
                ? 'Spot NOVO, mesmo conceito. O que voce faz aqui, e por que?'
                : 'Sem material de transferencia para este conceito ainda. O que voce faz aqui, e por que?',
            'passos_visiveis' => array_values(array_filter(
                $passos,
                static fn (array $p): bool => in_array((int) ($p['step'] ?? 0), $visiveis, true)
            )),
            'passos_escondidos' => array_values(array_map(
                static fn (array $p): int => (int) $p['step'],
                array_filter($passos, static fn (array $p): bool => ! in_array((int) ($p['step'] ?? 0), $visiveis, true))
            )),
            'como_julgar' => sprintf(
                'atlas:study:review --grade=%d --mode=%s --correct|--wrong',
                (int) $carta->id,
                $modo
            ),
            'aviso' => $modo === 'recall'
                ? 'acertar aqui NAO promove: reconhecer a propria anotacao nao e saber'
                : null,
        ]);
    }

    /**
     * A carta vencida. Nunca entregue vence na hora; entregue vence quando o overlay diz.
     * Sem overlay a carta vence — e o primeiro julgamento que cria o relogio.
     */
    private function cartaVencida(string $domain, bool $force): ?object
    {
        $cartas = DB::table('worked_examples')
            ->where('domain', $domain)
            ->where('status', 'active')
            ->orderBy('delivered_count')
            ->orderByRaw('last_delivered_at is null desc')
            ->orderBy('last_delivered_at')
            ->limit(50)
            ->get();

        foreach ($cartas as $carta) {
            if ($carta->last_delivered_at === null) {
                return $carta;
            }
            $proxima = DB::table('dreyfus_overlays')
                ->where('knowledge_node_id', $carta->knowledge_node_id)
                ->where('domain', $domain)
                ->value('next_validation_at');

            if ($proxima === null || now()->greaterThanOrEqualTo($proxima)) {
                return $carta;
            }
        }

        return $force ? ($cartas->first() ?: null) : null;
    }

    /**
     * Escolhe o spot da entrega. Transferencia exige um spot que NAO seja o que autorou o
     * exemplo — e ele sai de outro registro do proprio operador, ancorado no mesmo termo.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function escolherSpot(object $carta, string $domain): array
    {
        $refs = (array) json_decode((string) $carta->author_evidence_refs, true);
        $termo = null;
        $sinalAutor = null;
        foreach ($refs as $ref) {
            if (is_string($ref) && str_starts_with($ref, 'term:')) {
                $termo = substr($ref, 5);
            }
            if (is_string($ref) && str_starts_with($ref, 'signal:')) {
                $sinalAutor = substr($ref, 7);
            }
        }

        if ($termo !== null && DatabaseTableAvailability::has('operator_learning_signals')) {
            $outros = OperatorLearningSignal::query()
                ->where('scope_type', 'domain')
                ->where('scope_id', $domain)
                ->when($sinalAutor !== null, fn ($q) => $q->where('id', '!=', $sinalAutor))
                ->get()
                ->filter(fn (OperatorLearningSignal $s): bool => (string) data_get($s->metadata, 'vocabulary_term') === $termo);

            foreach ($outros as $outro) {
                foreach ((array) $outro->evidence_refs as $ref) {
                    if (is_string($ref) && str_starts_with($ref, 'spot:')) {
                        return ['transfer', trim(substr($ref, 5)), 'signal:'.$outro->id];
                    }
                }
            }
        }

        return ['recall', (string) $carta->problem_context, 'worked_example:'.$carta->id];
    }

    private function julgar(DreyfusOverlayRepository $overlays, string $id): int
    {
        $acertou = (bool) $this->option('correct');
        $errou = (bool) $this->option('wrong');
        if ($acertou === $errou) {
            return $this->responde(['ok' => false, 'reason' => 'need_exactly_one_of_correct_or_wrong']);
        }

        $modo = (string) $this->option('mode');
        if (! in_array($modo, ['recall', 'transfer'], true)) {
            return $this->responde([
                'ok' => false,
                'reason' => 'mode_required',
                'allowed' => ['recall', 'transfer'],
                'why_it_matters' => 'sem o modo, acertar a propria anotacao e acertar um spot novo viram o mesmo numero',
            ]);
        }

        $carta = DB::table('worked_examples')->where('id', (int) $id)->first();
        if ($carta === null) {
            return $this->responde(['ok' => false, 'reason' => 'worked_example_not_found', 'id' => $id]);
        }

        $domain = (string) $carta->domain;
        $overlay = $overlays->find((string) $carta->knowledge_node_id, $domain);
        $antes = (int) ($overlay['current_level'] ?? 1);

        // A REGRA. Recall que acertou registra e nao promove; so transferencia promove.
        $depois = match (true) {
            $errou => max(1, $antes - 1),
            $modo === 'transfer' => min(5, $antes + 1),
            default => $antes,
        };

        $confianca = match (true) {
            $errou => max(0.1, (float) ($overlay['confidence'] ?? 0.5) - 0.2),
            $modo === 'transfer' => min(1.0, (float) ($overlay['confidence'] ?? 0.5) + 0.2),
            default => (float) ($overlay['confidence'] ?? 0.5),
        };

        $refs = array_merge(
            (array) ($overlay['evidence_refs'] ?? []),
            [sprintf('%s:%s:worked_example:%s', $modo, $errou ? 'wrong' : 'correct', $id)]
        );

        $resultado = $overlays->upsert(
            knowledgeNodeId: (string) $carta->knowledge_node_id,
            domain: $domain,
            level: $depois,
            confidence: $confianca,
            evidenceRefs: array_values(array_filter($refs, 'is_string')),
            lastUpdatedVia: 'operator_study_review',
        );

        return $this->responde([
            'ok' => true,
            'worked_example_id' => (int) $id,
            'modo' => $modo,
            'resultado' => $errou ? 'errou' : 'acertou',
            'estagio_antes' => $antes,
            'estagio_depois' => $depois,
            'promoveu' => $depois > $antes,
            'proxima_revisao' => $resultado['next_validation_at'] ?? null,
            'veredito' => match (true) {
                $errou => 'errou — o fading volta a mostrar mais, e o conceito volta antes',
                $modo === 'transfer' => 'TRANSFERENCIA: aplicou o principio a um spot que nunca viu. Isto e aprendizado.',
                default => 'recall: reconheceu a propria anotacao. Registrado, mas nao promove.',
            },
        ]);
    }

    /**
     * A carta e a SUPERFICIE DE ENSINO — o operador vai LER isto enquanto estuda, nao
     * parsear. JSON cru e certo para maquina e errado para quem esta tentando lembrar um
     * principio: o spot fica espremido entre chaves, e o passo escondido — que e a
     * pergunta — some no meio do resto.
     *
     * `--json` continua sendo o contrato de maquina, byte a byte igual ao de antes.
     *
     * @param  array<string,mixed>  $p
     */
    private function carta(array $p): void
    {
        $regua = str_repeat('─', 68);

        $this->line('');
        $this->line('  '.mb_strtoupper((string) $p['modo']).'   ·   estagio '.$p['estagio_dreyfus'].'/5   ·   entrega #'.$p['entrega_numero']);
        $this->line('  '.$regua);
        $this->line('');
        $this->line('  '.wordwrap((string) $p['spot'], 64, "\n  "));
        $this->line('');
        $this->line('  '.$regua);

        // Visiveis e escondidos INTERCALADOS na ordem dos passos. Listar os escondidos no
        // fim faria a lacuna perder o lugar — e o lugar e a informacao: o operador precisa
        // ver que falta o passo 3 ENTRE o conceito e a decisao, nao que "sobrou um 3".
        $linhas = [];
        foreach ((array) $p['passos_visiveis'] as $passo) {
            $linhas[(int) $passo['step']] = wordwrap((string) $passo['action'], 62, "\n      ");
        }
        foreach ((array) $p['passos_escondidos'] as $n) {
            // A lacuna aparece como LACUNA, nao como ausencia. Sumir por completo faria a
            // carta parecer inteira, e ele nao saberia que ha algo a produzir.
            $linhas[(int) $n] = (int) $n === 3
                ? '████  ← o principio: reproduza antes de virar a carta'
                : '████';
        }
        ksort($linhas);
        foreach ($linhas as $n => $texto) {
            $this->line('   '.$n.'. '.$texto);
        }

        $this->line('  '.$regua);
        $this->line('');
        $this->line('  '.$p['pergunta']);
        if (($p['aviso'] ?? null) !== null) {
            $this->line('  ⚠  '.$p['aviso']);
        }
        $this->line('');
        $this->line('  quando responder:  '.$p['como_julgar']);
        $this->line('');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function responde(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif (($payload['ok'] ?? false) === true && isset($payload['passos_visiveis'])) {
            $this->carta($payload);
        } else {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
