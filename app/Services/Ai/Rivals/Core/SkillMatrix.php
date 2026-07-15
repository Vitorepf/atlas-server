<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;

/**
 * A lista fina: o que cada instrumento mede POR DENTRO.
 *
 * O relatório publica "Multilíngue 94%" tendo medido só bengali, e "Viés social
 * 100%" tendo medido só idade — nunca raça, gênero ou religião. O rótulo promete
 * o domínio; a amostra cobriu uma fatia. Um número assim não está errado, está
 * AMBÍGUO: sugere uma precisão que a medição não tem.
 *
 * Aqui a habilidade é o par (instrumento, discriminador do sample), e o
 * discriminador vem do METADATA do próprio sample. Só o NOME do eixo é
 * declarado abaixo; os VALORES são o que o dado disser. Lista escrita à mão
 * envelhece contra o dado que ela resume — foi assim que a frase de escopo
 * passou a dizer "ZERO medição" sobre cinco domínios medidos.
 *
 * Não pontua nada: reusa o recibo, que é a autoridade canônica de status
 * (inclusive o environment_failure de resposta cortada). Re-pontuar aqui criaria
 * uma segunda verdade capaz de divergir da primeira.
 */
final class SkillMatrix
{
    /**
     * Qual campo do metadata separa habilidades DENTRO de um instrumento.
     *
     * Instrumento ausente = o próprio instrumento é a habilidade (musr,
     * winogrande, truthfulqa e afins não fatiam). Ausente ≠ esquecido: o default
     * é a habilidade grossa, nunca inventar um eixo que o dado não tem.
     *
     * @var array<string,string>
     */
    private const SKILL_AXIS = [
        'mmlu_0_shot' => 'subject',
        'bbq' => 'category',
        'coconot' => 'category',
        'gpqa_diamond' => 'high_level_domain',
        'mgsm' => 'language',
        'writingbench' => 'domain1',
        'ifeval' => 'instruction_id_list',
    ];

    /**
     * Habilidades observadas no run, cada uma com os dois braços lado a lado.
     *
     * @return list<array{skill:string,instrument:string,axis:?string,bare:?float,
     *     bare_n:int,atlas:?float,atlas_n:int,delta:?float,verdict:string}>
     */
    public function forRun(string $runId): array
    {
        $skillByCase = $this->skillByCase($runId);
        $suiteId = (string) data_get(
            json_decode((string) @file_get_contents(RunPaths::nativeManifestPath($runId)), true) ?: [],
            'suite_id',
            '',
        );

        $excludedPairs = $this->excludedPairs($runId);

        /** @var array<string,array{instrument:string,axis:?string,bare:list<float>,atlas:list<float>}> $tally */
        $tally = [];
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            $caseId = (string) ($receipt->data['case_id'] ?? '');
            $skill = $skillByCase[$caseId] ?? $this->skillFromTaskType($suiteId, $receipt->data);
            if ($skill === null) {
                continue;
            }
            // Par que o uplift recusou por FALTA DE PROVA de que o Atlas rodou
            // mesmo. Contá-lo aqui atribuiria ao Atlas uma unidade que ninguém
            // provou ser Atlas — e foi assim que esta aba chegou a dizer
            // "terminal_bench: Atlas 50pp PIOR" sobre o tb_hello, que o uplift
            // tinha excluído. Excluir dos DOIS braços mantém a comparação no
            // mesmo conjunto e a aba idêntica ao uplift, por construção.
            if (isset($excludedPairs[$caseId.'|'.($receipt->data['repetition'] ?? '')])) {
                continue;
            }
            // Falha de ambiente NÃO é nota do modelo: é medição que não houve.
            // Contá-la como 0 publicaria "incapaz" sobre o que ninguém mediu —
            // o mesmo erro do falso-seguro, só que na direção da capacidade.
            if (($receipt->data['failure_class'] ?? null) === FailureClass::ENVIRONMENT) {
                continue;
            }
            $arm = str_ends_with((string) ($receipt->data['arm_id'] ?? ''), '@atlas_dev') ? 'atlas' : 'bare';
            $key = $skill['skill'];
            $tally[$key] ??= [
                'instrument' => $skill['instrument'],
                'axis' => $skill['axis'],
                'task_type' => (string) ($receipt->data['task_type'] ?? ''),
                'bare' => [],
                'atlas' => [],
            ];
            $tally[$key][$arm][] = ($receipt->data['status'] ?? null) === 'success' ? 1.0 : 0.0;
        }

        $rows = [];
        foreach ($tally as $skill => $t) {
            $bare = $t['bare'] === [] ? null : round(array_sum($t['bare']) / count($t['bare']), 4);
            $atlas = $t['atlas'] === [] ? null : round(array_sum($t['atlas']) / count($t['atlas']), 4);
            // A DISTÂNCIA entre os braços é sinal ou ruído? Wilson diz onde cada
            // braço está e cala sobre isso. Sem esta conta, 6/9 contra 9/9 sai
            // como "+33 pp" — e o intervalo real é [-3, +65], que contém o zero.
            // Com 9 tarefas por braço só se afirma acima de 44 pp; amostra menor
            // não afirma nada, nem 0%->100%.
            $ci = $bare === null || $atlas === null
                ? null
                : StatisticalPolicy::newcombeDiff(
                    (int) array_sum($t['atlas']), count($t['atlas']),
                    (int) array_sum($t['bare']), count($t['bare']),
                );
            // A capacidade é declarada por SUÍTE (`inspect_evals`), não pelo
            // instrumento fino (`mmlu_0_shot`) — este é a fatia, e vira
            // qualificador da linha, não o nome dela.
            // TETO DO INSTRUMENTO — o limite é o teste, não o Atlas.
            //
            // Se o modelo sozinho já faz 89% em 9 tarefas, o ganho máximo é 11 pp
            // — e com 9 tarefas só se afirma acima de 44 pp. Ali NENHUM ganho é
            // demonstrável, nem por um Atlas perfeito. Pintar essa linha de cinza
            // sem dizer isso deixa parecer que o Atlas não ajudou, quando na
            // verdade o instrumento não consegue mostrar ajuda nenhuma.
            //
            // Exato, não por limiar chutado: simula o melhor caso possível (o
            // Atlas acerta TUDO) e pergunta se o intervalo separaria do zero. Se
            // nem assim, o teto é do teste.
            $gainUndemonstrable = null;
            if ($bare !== null && $t['bare'] !== []) {
                $nb = count($t['bare']);
                $best = StatisticalPolicy::newcombeDiff($nb, $nb, (int) array_sum($t['bare']), $nb);
                $gainUndemonstrable = ! ($best['ci_low'] > 0.0);
            }
            $place = $this->placeOf($suiteId !== '' ? $suiteId : $t['instrument'], $t['task_type']);
            $rows[] = [
                'skill' => $skill,
                // O nome que o operador lê. O slug segue em `skill` para auditar.
                'label' => $place['label'],
                'domain' => $place['domain'],
                'domain_label' => $place['domain_label'],
                'instrument' => $t['instrument'],
                'axis' => $t['axis'],
                'bare' => $bare,
                'bare_n' => count($t['bare']),
                'atlas' => $atlas,
                'atlas_n' => count($t['atlas']),
                'delta' => $bare === null || $atlas === null ? null : round($atlas - $bare, 4),
                'delta_ci_low' => $ci['ci_low'] ?? null,
                'delta_ci_high' => $ci['ci_high'] ?? null,
                // `verdict` é o FATO BRUTO (o Atlas pontuou menos); `conclusive` é
                // se dá para AFIRMAR isso. São perguntas diferentes e vivem em
                // campos diferentes de propósito: a tela colore por conclusive,
                // o fato continua registrado no verdict.
                'conclusive' => $ci !== null && ($ci['ci_low'] > 0.0 || $ci['ci_high'] < 0.0),
                // Nem um Atlas perfeito provaria ganho aqui: o teto é do teste.
                'gain_undemonstrable' => $gainUndemonstrable,
                // A régua de dificuldade do operador (02/07), finalmente ligada.
                // Ela estava escrita em config/atlas_rivals.php:104 e implementada
                // no DifficultyCalibrator — e o relatório nunca a chamou uma única
                // vez (`grep -c DifficultyCalibrator EnterpriseReportBuilder` = 0),
                // publicando 13 linhas em 100% sem acusar nenhuma.
                //
                // Chamada com os CONTADORES, não com a taxa: sem o n a banda é um
                // dado de 5 faces (um instrumento de 30% real cai em too_easy,
                // elite_valid, borderline, hard OU frontier, só por sorte).
                'difficulty_band' => $bare === null
                    ? 'unknown'
                    : (new DifficultyCalibrator)->bandForCounts((int) array_sum($t['bare']), count($t['bare'])),
                'verdict' => $this->verdict($bare, $atlas),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['skill'] <=> $b['skill']);

        return $rows;
    }

    /**
     * Onde a habilidade mora e como ela se chama em português.
     *
     * A tela imprimia o slug cru (`bbq:Age`, `mmlu_0_shot:college_physics`) e
     * `domain: null` nas 30 linhas — o operador precisava de legenda para 28
     * delas, e nada agrupava. O mapa não precisa de taxonomia nova: o recibo já
     * traz `task_type`, que é a MESMA chave que CAPABILITIES usa em
     * `task_types`, e SUB_CAPABILITIES já tem o rótulo humano escrito.
     *
     * Derivar em vez de listar à mão é o mesmo motivo do docblock lá em cima:
     * lista escrita à mão envelhece contra o dado que ela resume.
     *
     * @return array{domain:?string,domain_label:string,label:string}
     */
    private function placeOf(string $suiteId, string $taskType): array
    {
        foreach (EnterpriseReportBuilder::CAPABILITIES as $id => $cap) {
            if (! in_array($suiteId, (array) ($cap['suites'] ?? []), true)) {
                continue;
            }
            // Capacidade fatiada por task_type (12 das 15) só reivindica a
            // habilidade se o tipo bater; as outras 3 são da suíte inteira.
            $hasTypes = isset($cap['task_types']);
            if ($hasTypes && ! in_array($taskType, (array) $cap['task_types'], true)) {
                continue;
            }
            $subKey = $hasTypes ? $suiteId.':'.$taskType : $suiteId;

            return [
                'domain' => $id,
                'domain_label' => (string) ($cap['label'] ?? $id),
                'label' => (string) (EnterpriseReportBuilder::SUB_CAPABILITIES[$subKey]['label'] ?? $suiteId),
            ];
        }

        return ['domain' => null, 'domain_label' => 'Sem domínio', 'label' => $suiteId];
    }

    /**
     * Verde/vermelho SÓ quando os dois braços existem e o Atlas foi provado
     * real. Sem braço Atlas o veredito é "não medido" — jamais verde por
     * omissão. Célula sem cor é lacuna declarada, não elogio silencioso.
     */
    private function verdict(?float $bare, ?float $atlas): string
    {
        return match (true) {
            $bare === null => 'sem_medicao',
            $atlas === null => 'atlas_nao_medido',
            $atlas > $bare => 'atlas_melhor',
            $atlas < $bare => 'atlas_pior',
            default => 'empate',
        };
    }

    /**
     * Pares que o uplift do run recusou, no formato `case_id|repetição`.
     *
     * O uplift só aceita par com prova de bridge real (runtime_bridge.
     * real_provider). Reusar a decisão dele é deliberado: re-derivar a prova
     * aqui criaria uma segunda autoridade sobre "isto é Atlas?", livre para
     * divergir da primeira. Run sem uplift.json não exclui nada — e também não
     * tem braço Atlas para comparar.
     *
     * @return array<string,true>
     */
    private function excludedPairs(string $runId): array
    {
        $uplift = json_decode(
            (string) @file_get_contents(RunPaths::runDir($runId).'/uplift.json'),
            true,
        );
        if (! is_array($uplift)) {
            return [];
        }

        $out = [];
        foreach ((array) ($uplift['excluded_pair_keys'] ?? []) as $key) {
            $out[(string) $key] = true;
        }

        return $out;
    }

    /**
     * Habilidade das suítes que não são de pergunta-e-resposta.
     *
     * Só o inspect entrega samples com metadata; as suítes de código (polyglot,
     * terminal, swe_live, hal, bfcl) entregam patch e log. Sem este caminho a aba
     * mostraria "0 habilidades com os dois braços" — enquanto o resto do
     * relatório mostra uplift real em 5 famílias. Duas verdades na mesma tela é
     * o defeito que esta aba existe para não cometer.
     *
     * O eixo é o task_type do recibo (coding_patch, bug_investigation,
     * feature_under_specified, long_horizon_engineering…), qualificado pela
     * suíte: coding_patch sozinho fundiria polyglot com swe_live num número só.
     *
     * @param  array<string,mixed>  $receipt
     * @return array{skill:string,instrument:string,axis:?string}|null
     */
    private function skillFromTaskType(string $suiteId, array $receipt): ?array
    {
        $taskType = (string) ($receipt['task_type'] ?? '');
        if ($suiteId === '' || $taskType === '') {
            return null;
        }

        return [
            'skill' => $suiteId.':'.$taskType,
            'instrument' => $suiteId,
            'axis' => 'task_type',
        ];
    }

    /**
     * case_id → habilidade, lido do metadata do sample na própria unidade.
     *
     * Cada caso roda com `--sample-id`, então caso ↔ sample ↔ habilidade. O
     * metadata é o que o benchmark declara sobre a própria pergunta; é a única
     * fonte que não envelhece quando o pacote de casos muda.
     *
     * @return array<string,array{skill:string,instrument:string,axis:?string}>
     */
    private function skillByCase(string $runId): array
    {
        $dir = RunPaths::nativeResultsDir($runId);
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            if (! str_ends_with((string) $file, '.json')) {
                continue;
            }
            $payload = json_decode((string) file_get_contents($dir.'/'.$file), true);
            if (! is_array($payload) || ! isset($payload['samples']) || ! is_array($payload['samples'])) {
                continue;
            }
            $instrument = (string) data_get($payload, 'eval.task_display_name', '');
            if ($instrument === '') {
                continue;
            }
            $axis = self::SKILL_AXIS[$instrument] ?? null;
            foreach ($payload['samples'] as $sample) {
                $caseId = (string) ($sample['id'] ?? '');
                if ($caseId === '') {
                    continue;
                }
                $out[$caseId] = [
                    'skill' => $this->skillName($instrument, $axis, (array) ($sample['metadata'] ?? [])),
                    'instrument' => $instrument,
                    'axis' => $axis,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function skillName(string $instrument, ?string $axis, array $metadata): string
    {
        if ($axis === null) {
            return $instrument;
        }
        $value = $metadata[$axis] ?? null;
        // ifeval traz uma LISTA de instruções por sample ("detectable_format:
        // json", "length_constraints:…"). A habilidade é a família antes do
        // dois-pontos; a primeira basta para nomear, e o eixo fica declarado.
        if (is_array($value)) {
            $value = $value === [] ? null : explode(':', (string) reset($value))[0];
        }
        $value = is_scalar($value) ? trim((string) $value) : '';

        // Eixo declarado mas ausente no sample = o instrumento mudou de forma.
        // Nomear "instrumento:" com valor vazio esconderia isso; o sufixo grita.
        return $value === '' ? $instrument.':(sem '.$axis.')' : $instrument.':'.$value;
    }
}
