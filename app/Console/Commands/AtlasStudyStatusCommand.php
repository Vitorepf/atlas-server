<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "Onde eu estou" — o estado do aprendizado do operador, num lugar so.
 *
 * O ciclo mora em tres tabelas que nao se olham: `atlas_study_vocabulary` (o que existe
 * para aprender), `operator_learning_signals` (o que ele declarou) e `dreyfus_overlays`
 * (o que ele provou). Sem esta visao ele tem as pecas e nao tem o placar, e um sistema de
 * estudo que nao devolve o proprio estado nao e estudo — e arquivamento.
 *
 * A regra que este comando NAO relaxa: `recall_acertos` e reportado separado de
 * `transferencias`, e a taxa de dominio conta so a segunda. Somar os dois daria um numero
 * maior e mais bonito, e seria a mesma mentira que o laco de revisao existe para impedir —
 * reconhecer a propria anotacao nao e saber.
 *
 * `perdidos_por_redacao` aparece SEMPRE, mesmo em zero. Registro que existe mas nao pode
 * ser estudado (claim trocado por hash quando a privacidade subiu) e uma lacuna real; se
 * so aparecesse quando maior que zero, o operador nunca saberia que ela pode existir.
 */
class AtlasStudyStatusCommand extends Command
{
    protected $signature = 'atlas:study:status
        {--domain=poker : Dominio de estudo}
        {--json : Saida canonica}';

    protected $description = 'Mostra o estado do aprendizado do operador: o que ele registrou, o que virou material, e o que ele PROVOU.';

    public function handle(): int
    {
        $domain = (string) $this->option('domain');

        foreach (['atlas_study_vocabulary', 'operator_learning_signals'] as $tabela) {
            if (! DatabaseTableAvailability::has($tabela)) {
                return $this->responde(['ok' => false, 'reason' => 'missing_table', 'table' => $tabela]);
            }
        }

        $sinais = DB::table('operator_learning_signals')
            ->where('scope_type', 'domain')->where('scope_id', $domain)->get();

        $declarados = $sinais->filter(fn ($s): bool => (bool) data_get(json_decode((string) $s->metadata, true), 'operator_text_declared'));
        $redigidos = $sinais->filter(fn ($s): bool => str_starts_with((string) $s->normalized_claim, '[redacted:'));
        $chutes = $declarados->filter(fn ($s): bool => data_get(json_decode((string) $s->metadata, true), 'confidence_kind') === 'guess');
        $ancorados = $declarados->filter(fn ($s): bool => data_get(json_decode((string) $s->metadata, true), 'vocabulary_term') !== null);

        $exemplos = DatabaseTableAvailability::has('worked_examples')
            ? DB::table('worked_examples')->where('domain', $domain)->where('status', 'active')->get()
            : collect();

        [$estagios, $placar, $fracos] = $this->overlays($domain, $exemplos);

        return $this->responde([
            'ok' => true,
            'domain' => $domain,
            'vocabulario' => [
                'termos' => DB::table('atlas_study_vocabulary')->where('domain', $domain)->count(),
                'termos_ancorados_em_principio' => $ancorados->map(fn ($s): string => (string) data_get(json_decode((string) $s->metadata, true), 'vocabulary_term'))->unique()->count(),
            ],
            'decisoes_registradas' => [
                'total' => $sinais->count(),
                'declaradas_pelo_operador' => $declarados->count(),
                'por_principio' => $declarados->count() - $chutes->count(),
                'chutes' => $chutes->count(),
                // Sempre visivel, mesmo em zero: lacuna que so aparece quando existe e
                // uma lacuna que o operador descobre tarde.
                'perdidos_por_redacao' => $redigidos->count(),
            ],
            'material_de_estudo' => [
                'cartas_ativas' => $exemplos->count(),
                'nunca_entregues' => $exemplos->filter(fn ($e): bool => $e->last_delivered_at === null)->count(),
                'entregues_mais_de_uma_vez' => $exemplos->filter(fn ($e): bool => (int) $e->delivered_count > 1)->count(),
            ],
            'estagios_dreyfus' => $estagios,
            'placar_de_aprendizado' => $placar,
            'conceitos_mais_fracos' => $fracos,
            // "Utilizavel" e o que de fato consegue virar carta: declarado, por principio,
            // e com o texto intacto. Basear o conselho em `declaradas` mandaria o operador
            // rodar um comando que produz zero — conselho que nao confere com o dado e o
            // mesmo defeito de um numero que nao mede nada.
            'proximo_passo' => $this->proximoPasso(
                $domain,
                $sinais->count(),
                $declarados->count(),
                $declarados->count() - $chutes->count() - $redigidos->count(),
                $exemplos,
                $placar
            ),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,object>  $exemplos
     * @return array{0:array<string,int>,1:array<string,mixed>,2:list<array<string,mixed>>}
     */
    private function overlays(string $domain, $exemplos): array
    {
        $estagios = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
        $placar = ['transferencias' => 0, 'recall_acertos' => 0, 'erros' => 0, 'taxa_de_dominio' => null];
        $fracos = [];

        if (! DatabaseTableAvailability::has('dreyfus_overlays')) {
            return [$estagios, $placar, $fracos];
        }

        $linhas = DB::table('dreyfus_overlays')->where('domain', $domain)->get();
        $titulos = $exemplos->keyBy(fn ($e): string => (string) $e->knowledge_node_id);

        foreach ($linhas as $linha) {
            $nivel = (string) max(1, min(5, (int) $linha->current_level));
            $estagios[$nivel]++;

            $refs = (array) json_decode((string) $linha->evidence_refs, true);
            $transfer = 0;
            $recall = 0;
            $erro = 0;
            foreach ($refs as $ref) {
                if (! is_string($ref)) {
                    continue;
                }
                if (str_starts_with($ref, 'transfer:correct:')) {
                    $transfer++;
                } elseif (str_starts_with($ref, 'recall:correct:')) {
                    $recall++;
                } elseif (str_contains($ref, ':wrong:')) {
                    $erro++;
                }
            }
            $placar['transferencias'] += $transfer;
            $placar['recall_acertos'] += $recall;
            $placar['erros'] += $erro;

            if ((int) $linha->current_level <= 2) {
                $fracos[] = [
                    'conceito' => (string) ($titulos[(string) $linha->knowledge_node_id]->title ?? $linha->knowledge_node_id),
                    'estagio' => (int) $linha->current_level,
                    'erros' => $erro,
                    'transferencias' => $transfer,
                ];
            }
        }

        // A taxa conta SO transferencia. Somar recall daria numero maior e seria a mesma
        // mentira que o laco de revisao existe para impedir.
        $tentativas = $placar['transferencias'] + $placar['erros'];
        $placar['taxa_de_dominio'] = $tentativas > 0 ? round($placar['transferencias'] / $tentativas, 2) : null;
        $placar['nota'] = 'taxa_de_dominio conta so transferencia; recall_acertos fica de fora de proposito';

        usort($fracos, static fn (array $a, array $b): int => [$a['estagio'], -$a['erros']] <=> [$b['estagio'], -$b['erros']]);

        return [$estagios, $placar, array_slice($fracos, 0, 5)];
    }

    /**
     * O que fazer AGORA. Um painel que so mostra numero deixa o operador decidir o que
     * fazer com eles; o gargalo real e sempre um so, e nomea-lo vale mais que a tabela.
     *
     * @param  \Illuminate\Support\Collection<int,object>  $exemplos
     * @param  array<string,mixed>  $placar
     */
    private function proximoPasso(string $domain, int $sinais, int $declarados, int $utilizaveis, $exemplos, array $placar): string
    {
        if ($declarados === 0) {
            return $sinais > 0
                ? 'ha registro no banco mas nenhum declarado por voce — registre uma decisao com `atlas:study:log`'
                : 'registre a primeira decisao: `atlas:study:log --spot=... --decision=... --why=... --term=...`';
        }
        if ($utilizaveis <= 0 && $exemplos->isEmpty()) {
            return 'todas as decisoes registradas estao inutilizaveis (redigidas ou chute) e nenhuma pode virar carta. '
                .'O conteudo redigido nao volta — registre uma decisao nova com `atlas:study:log`';
        }
        if ($exemplos->isEmpty()) {
            return 'ha decisoes registradas e nenhuma virou material: rode `atlas:study:example --domain='.$domain.'`';
        }
        if ($exemplos->filter(fn ($e): bool => $e->last_delivered_at === null)->isNotEmpty()) {
            return 'ha carta nunca entregue: rode `atlas:study:review --domain='.$domain.'`';
        }
        if (($placar['transferencias'] ?? 0) === 0) {
            return 'nenhuma transferencia provada ainda. Registre uma SEGUNDA decisao no mesmo conceito — '
                .'sem dois spots no mesmo termo o sistema so consegue testar recall, e recall nao promove';
        }

        return 'em dia: rode `atlas:study:review --domain='.$domain.'` quando houver carta vencida';
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
