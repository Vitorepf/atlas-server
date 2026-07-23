<?php

namespace App\Services\Ai\Rivals\Core\EnterpriseReport;

use App\Services\Ai\Rivals\Core\EnterpriseReportBuilder;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Core\SkillMatrix;

/**
 * Habilidades, nota do braco Atlas, confiabilidade por suite e linha de familia de uplift. Extraido VERBATIM de EnterpriseReportBuilder (GOD-DEBULK).
 */
class EnterpriseReportUpliftSkillsSection
{
    public function __construct(private EnterpriseReportSupport $support) {}

    /**
     * @param  list<array<string,mixed>>  $capabilities
     * @return array<string,mixed>
     */
    /**
     * A lista fina, unida sobre os runs que ESTE relatório usou.
     *
     * Nasce dos mesmos runs das outras abas de propósito: skills lidas de runs
     * que o relatório excluiu mostrariam habilidade que o veredito não conta —
     * duas verdades na mesma tela, o defeito que o operador proibiu.
     *
     * Conta também quantas habilidades cada instrumento revelou, que é o
     * denominador honesto: "mmlu 3" avisa que 3 matérias foram medidas, não as
     * 57 que o MMLU tem.
     *
     * @param  list<string>  $runIds
     * @param  array<string,mixed>  $atlasUplift
     * @return array{rows:list<array<string,mixed>>, total:int, with_atlas:int,
     *     by_instrument:array<string,int>, atlas_arm_note:?string}
     */
    public function buildSkills(array $runIds, array $atlasUplift = []): array
    {
        $matrix = new SkillMatrix;
        $rows = [];
        foreach (array_unique($runIds) as $runId) {
            foreach ($matrix->forRun($runId) as $row) {
                $rows[$row['skill']] = $row;
            }
        }
        ksort($rows);

        $byInstrument = [];
        foreach ($rows as $row) {
            $byInstrument[$row['instrument']] = ($byInstrument[$row['instrument']] ?? 0) + 1;
        }
        ksort($byInstrument);

        $withAtlas = count(array_filter($rows, static fn (array $r): bool => $r['atlas'] !== null));

        return [
            'rows' => array_values($rows),
            'total' => count($rows),
            // Quantas dá para pintar de verde/vermelho: sem os dois braços a
            // comparação não existe, e a aba tem de dizer isso em número.
            'with_atlas' => $withAtlas,
            'by_instrument' => $byInstrument,
            'atlas_arm_note' => $this->atlasArmNote($withAtlas, $atlasUplift, $runIds),
        ];
    }

    /**
     * Por que NENHUMA linha tem cor — derivado dos motivos de recusa do uplift.
     *
     * "Não medido" sem motivo é tão opaco quanto o número falso que ele
     * substituiu: o leitor vê a coluna vazia e supõe que a bateria não rodou,
     * quando a verdade é outra e é grave — o braço rotulado "Atlas" executava
     * `hermes -z`, Hermes CLI puro, sem Atlas nenhum no laço. A frase sai dos
     * motivos que o próprio portão registrou, nunca escrita à mão.
     *
     * @param  array<string,mixed>  $atlasUplift
     */
    /**
     * POR QUE não há cor — derivado dos RECIBOS, nunca de rótulo que envelhece.
     *
     * Esta nota já mentiu: dizia "o braço não provou ter rodado o Atlas" lendo o
     * `reason` das famílias de uplift ANTIGAS. No dia em que o braço passou a
     * rodar de verdade, a frase continuou igual — e o motivo verdadeiro (o Atlas
     * roda e é bloqueado pela PRÓPRIA governança) ficou invisível. Rótulo de run
     * velho não é o estado de hoje.
     *
     * Agora conta o que os recibos dizem: quantas unidades PROVARAM o Atlas
     * (`atlas_runtime === true`) e o que bloqueou cada uma. A diferença importa
     * para o operador: "não rodou" é bug de bridge; "rodou e a governança
     * recusou" é decisão de arquitetura. As duas produzem tela sem cor e exigem
     * obras opostas.
     *
     * @param  list<string>  $runIds
     */
    public function atlasArmNote(int $withAtlas, array $atlasUplift, array $runIds = []): ?string
    {
        if ($withAtlas > 0) {
            return null;
        }

        $provadas = 0;
        $motivos = [];
        foreach (array_unique($runIds) as $runId) {
            foreach (RunReceipt::loadAll((string) $runId) as $receipt) {
                $bridge = (array) data_get($receipt->data, 'metadata.runtime_bridge', []);
                if (($bridge['atlas_runtime'] ?? null) !== true) {
                    continue;
                }
                $provadas++;
                foreach ((array) data_get($bridge, 'provider_call.error_codes', []) as $code) {
                    // Só a família do erro: o sufixo carrega nome de arquivo do
                    // caso e viraria ruído irrepetível na tela.
                    $familia = explode(':', (string) $code)[0];
                    $motivos[$familia] = ($motivos[$familia] ?? 0) + 1;
                }
            }
        }

        if ($provadas > 0) {
            arsort($motivos);
            $lista = [];
            foreach ($motivos as $familia => $n) {
                $lista[] = "{$n}× {$familia}";
            }

            return 'Nenhuma linha está verde ou vermelha — e desta vez não é porque o Atlas não '
                .'rodou: '.$provadas.' unidades PROVARAM ter rodado o Atlas ('
                .'atlas_runtime derivado do caminho executado). Elas foram bloqueadas pelo próprio '
                .'Atlas antes de terminar: '.implode(' · ', $lista).'. Isso não é o modelo falhando '
                .'nem falta de bateria — é o executor recusando a tarefa. Enquanto o braço não '
                .'CONCLUIR, não há par para comparar, e o relatório recusa o número. A coluna '
                .'"sem Atlas" continua medida e válida.';
        }

        $families = (array) ($atlasUplift['families'] ?? []);
        $missingProof = array_filter(
            $families,
            static fn (array $f): bool => str_contains((string) ($f['reason'] ?? ''), 'atlas_runtime_proof_missing'),
        );
        if ($missingProof === []) {
            return 'Nenhuma habilidade tem os dois braços: a comparação com Atlas ainda não foi medida.';
        }

        return 'Nenhuma linha está verde ou vermelha, e o motivo não é falta de bateria: em '
            .count($missingProof).' de '.count($families).' famílias o braço rotulado "com Atlas" '
            .'não provou ter rodado o Atlas (atlas_runtime_proof_missing). O relatório recusa o '
            .'número em vez de publicar um delta que compararia outra coisa com o nome do Atlas. '
            .'A coluna "modelo sozinho" continua medida e válida.';
    }

    /**
     * @param  list<array<string,mixed>>  $suiteRows
     * @return array<string,array{reliable:bool,reason:?string}>
     */
    public function suiteReliabilityMap(array $suiteRows): array
    {
        $map = [];
        foreach ($suiteRows as $row) {
            if (! is_array($row) || ! isset($row['suite_id'])) {
                continue;
            }
            $map[(string) $row['suite_id']] = [
                'reliable' => (bool) ($row['reliable'] ?? true),
                'reason' => $row['unreliable_reason'] ?? null,
            ];
        }

        return $map;
    }

    public function upliftFamilyRow(string $family, string $suiteId, array $runs, string $primaryModel = 'verboo_kimi_k2_7'): array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId && is_array($run['uplift'] ?? null),
        ));
        usort($candidates, function (array $a, array $b): int {
            $aValid = (($a['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            $bValid = (($b['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            if ($aValid !== $bValid) {
                return $bValid <=> $aValid;
            }

            return strcmp((string) $b['run_id'], (string) $a['run_id']);
        });
        foreach ($candidates as $run) {
            $uplift = $run['uplift'] ?? null;
            if (! is_array($uplift)) {
                continue;
            }
            $kind = (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $status = $kind === 'real_uplift' ? 'real_uplift' : (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $reason = isset($uplift['reason']) ? (string) $uplift['reason'] : null;

            $bareIntel = null;
            $atlasIntel = null;
            $delta = null;
            $primaryOutcome = 'artifact_status';
            $successSemantics = 'benchmark_success';
            $nativeScoreMetric = null;
            $bareNativeScore = null;
            $atlasNativeScore = null;
            $deltaNativeScore = null;
            $deltaNativeScoreCi = null;
            $outcome = null;
            $deltas = (array) ($uplift['deltas'] ?? []);
            if ($deltas !== [] && is_array($deltas[0] ?? null)) {
                $d0 = $deltas[0];
                $primaryOutcome = (string) ($d0['primary_outcome'] ?? 'artifact_status');
                $successSemantics = (string) ($d0['success_semantics'] ?? 'benchmark_success');
                $nativeScoreMetric = is_string($d0['native_score_metric'] ?? null)
                    ? $d0['native_score_metric']
                    : null;
                $bareNativeScore = is_numeric(data_get($d0, 'base.avg_native_score'))
                    ? (float) data_get($d0, 'base.avg_native_score')
                    : null;
                $atlasNativeScore = is_numeric(data_get($d0, 'atlas.avg_native_score'))
                    ? (float) data_get($d0, 'atlas.avg_native_score')
                    : null;
                $deltaNativeScore = is_numeric($d0['delta_native_score'] ?? null)
                    ? (float) $d0['delta_native_score']
                    : null;
                $deltaNativeScoreCi = is_array($d0['delta_native_score_ci_95'] ?? null)
                    ? $d0['delta_native_score_ci_95']
                    : null;
                $outcome = is_string($d0['outcome'] ?? null) ? $d0['outcome'] : null;
                if (isset($d0['base']['success_rate']) && is_numeric($d0['base']['success_rate'])) {
                    $bareIntel = round((float) $d0['base']['success_rate'], 4);
                }
                if (isset($d0['atlas']['success_rate']) && is_numeric($d0['atlas']['success_rate'])) {
                    $atlasIntel = round((float) $d0['atlas']['success_rate'], 4);
                }
                if (isset($d0['delta_success_rate']) && is_numeric($d0['delta_success_rate'])) {
                    $delta = round((float) $d0['delta_success_rate'], 4);
                }
                if ($primaryOutcome === 'native_score') {
                    // `status=success` means the native artifact was valid. It is
                    // not a 100% capability score for continuous benchmarks.
                    $bareIntel = null;
                    $atlasIntel = null;
                    $delta = null;
                }
            }

            if ($primaryOutcome !== 'native_score') {
                $armScores = $this->support->armScoresForSuite($suiteId, $primaryModel, [$run]);
                $bareIntel ??= $armScores['bare'];
                if ($status === 'real_uplift') {
                    $atlasIntel ??= $armScores['atlas'];
                    if ($delta === null && $bareIntel !== null && $atlasIntel !== null) {
                        $delta = round($atlasIntel - $bareIntel, 4);
                    }
                } else {
                    // Unsupported: never publish atlas score as comparable fact (avoids 0% falso).
                    $atlasIntel = null;
                    $delta = null;
                }
            }

            $excluded = array_values(array_map('strval', (array) ($uplift['excluded_pair_keys'] ?? [])));
            $provenPairCount = (int) ($uplift['proven_pair_count'] ?? 0);
            $diagnosticOnly = $status === 'real_uplift' && $excluded !== [];

            return [
                'family' => $family,
                'label' => EnterpriseReportBuilder::familyLabel($family),
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'status' => $status,
                'reason' => $reason,
                'uplift_supported' => (bool) ($uplift['uplift_supported'] ?? false),
                'comparable' => $status === 'real_uplift' && $bareIntel !== null && $atlasIntel !== null && ! $diagnosticOnly,
                'diagnostic_only' => $diagnosticOnly,
                'proven_pair_count' => $provenPairCount,
                'excluded_pair_keys' => $excluded,
                'primary_outcome' => $primaryOutcome,
                'success_semantics' => $successSemantics,
                'native_score_metric' => $nativeScoreMetric,
                'bare_native_score' => $bareNativeScore,
                'atlas_native_score' => $atlasNativeScore,
                'delta_native_score' => $deltaNativeScore,
                'delta_native_score_ci_95' => $deltaNativeScoreCi,
                'outcome' => $outcome,
                'bare_intelligence' => $bareIntel,
                'atlas_intelligence' => $atlasIntel,
                'delta_intelligence' => $delta,
                'internal_claim_allowed' => (bool) ($uplift['internal_claim_allowed'] ?? $uplift['claim_allowed'] ?? false),
                'stop_the_line' => (bool) ($uplift['stop_the_line'] ?? false),
            ];
        }

        return [
            'family' => $family,
            'label' => EnterpriseReportBuilder::familyLabel($family),
            'suite_id' => $suiteId,
            'run_id' => null,
            'status' => 'not_run',
            'reason' => 'not_run',
            'uplift_supported' => false,
            'comparable' => false,
            'diagnostic_only' => false,
            'proven_pair_count' => 0,
            'excluded_pair_keys' => [],
            'primary_outcome' => null,
            'success_semantics' => null,
            'native_score_metric' => null,
            'bare_native_score' => null,
            'atlas_native_score' => null,
            'delta_native_score' => null,
            'delta_native_score_ci_95' => null,
            'outcome' => null,
            'bare_intelligence' => null,
            'atlas_intelligence' => null,
            'delta_intelligence' => null,
            'internal_claim_allowed' => false,
            'stop_the_line' => false,
        ];
    }
}
