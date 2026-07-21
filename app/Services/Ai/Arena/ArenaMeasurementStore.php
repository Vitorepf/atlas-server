<?php

namespace App\Services\Ai\Arena;

use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;

final class ArenaMeasurementStore
{
    public function __construct(private readonly ModelRegistry $models = new ModelRegistry) {}

    /** Motores harness-only (mockllm etc.) nunca aparecem em payload público. */
    public function isPublicEngine(string $engine): bool
    {
        return $engine !== '' && ! $this->models->isHarnessOnly($engine);
    }

    /** @return list<string> */
    public function suites(): array
    {
        $configured = array_values(array_filter((array) config('atlas_arena.suites', []), 'is_string'));
        if ($configured !== []) {
            return $configured;
        }

        return array_keys($this->weights());
    }

    /** @return array<string, float> */
    public function weights(): array
    {
        $weights = [];
        foreach ((array) config('atlas_arena.weights', []) as $suite => $weight) {
            if (is_string($suite) && is_numeric($weight)) {
                $weights[$suite] = (float) $weight;
            }
        }

        return $weights;
    }

    public function assertWeightsValid(): void
    {
        $sum = array_sum($this->weights());
        if (abs($sum - 1.0) > 0.000001) {
            throw new RuntimeException('arena_weights_must_sum_to_one');
        }
    }

    /**
     * @return list<array{
     *   run_id_public:string,suite:string,engine:string,arm:string,score:float,
     *   cases_passed:int,cases_failed:int,cases_total:int,duration_avg_ms:?int,
     *   round_at:string
     * }>
     */
    public function measurements(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }

        $defectRuns = (array) config('atlas_arena.instrument_defect_runs', []);
        $rows = [];
        foreach ($this->runIds($runsDir) as $runId) {
            $suite = $this->suiteForRun($runId);
            if ($suite === null) {
                continue;
            }
            $receipts = $this->receipts($runId);
            if ($receipts === []) {
                continue;
            }

            /** @var array<string, array<string, mixed>> $groups */
            $groups = [];
            foreach ($receipts as $receipt) {
                // O braço é resolvido ANTES das exclusões porque cada unidade
                // descartada precisa ser CONTADA no braço dela. Sem esse contador,
                // excluir só as falhas de um braço produz 100% de sobrevivência —
                // provado em aider_polyglot: 30 contados (todos sucesso) e 45
                // excluídos (todas as falhas) viravam "Atlas +0.455", número falso
                // A FAVOR do Atlas, o espelho exato do zero falso.
                $arm = $this->publicArm((string) ($receipt['arm_id'] ?? ''));
                if ($arm === null) {
                    continue;
                }
                $key = $suite.'|'.$arm['engine'].'|'.$arm['arm'];
                $groups[$key] ??= [
                    'suite' => $suite,
                    'engine' => $arm['engine'],
                    'arm' => $arm['arm'],
                    'passed' => 0,
                    'failed' => 0,
                    'excluded' => 0,
                    'instrument_defect' => 0,
                    'walls' => [],
                    'win_walls' => [],
                    'tokens_out' => [],
                    'rounds' => [],
                    'has_score' => false,
                    'fractional' => false,
                    'declared_type' => null,
                    'case_ids' => [],
                    'score_sum' => 0.0,
                    'score_sumsq' => 0.0,
                    'score_n' => 0,
                ];

                // INSTRUMENTO DESCALIBRADO (denylist auditável em config):
                // o run inteiro deste braço mediu um defeito PROVADO do harness
                // (ex.: bfcl pré-c58ff1a7b2 — empacotador mangleava o nome da
                // função e o checker reprovava resposta perfeita). Invalidação
                // SIMÉTRICA: vitórias saem junto com derrotas — o oposto de
                // sobrevivência. Contador próprio, fora do guarda de seleção.
                $defect = $defectRuns[$runId] ?? null;
                if (is_array($defect) && $arm['arm'] === (string) ($defect['arm'] ?? 'with_atlas')) {
                    $groups[$key]['instrument_defect']++;
                    continue;
                }
                // Falha de AMBIENTE (caso quebrado, integração, proxy) não é nota
                // do modelo — não conta como falha de nenhum braço. O texto real
                // no recibo é `environment_failure`; o `=== 'environment'` antigo
                // NUNCA casava, então os artefatos viravam "0" e o app pintava o
                // braço com-Atlas como -10 catastrófico onde na verdade era NÃO
                // MEDIDO (inspect/tau2/lcb via proxy quebrado). Legacy incluído.
                if (in_array($receipt['failure_class'] ?? null, ['environment', 'environment_failure'], true)) {
                    $groups[$key]['excluded']++;
                    continue;
                }
                // LEI SUPREMA no dado: braço com-Atlas só MEDE quando a prova de
                // runtime confirma `execution=atlas_cli_dev_efficient`. Os recibos
                // da era-fraude (13-14/07) carregam um runtime_bridge v1 que
                // CONFESSA `execution=hermes_cli_oneshot` — hermes cru rotulado de
                // Atlas (memória 15/07, 107 recibos). Sem o marcador não há medição
                // DO ATLAS: nem vitória (aider 9/9 antigo ressuscitava como
                // "último par +8,9" no app), nem derrota (terminal_bench 0/51 da
                // mesma era). Genuínos pós-attacher = 100% com o marcador
                // (verificado 20/07: testeval 11/11 + bfcl 9/9 + cruxeval/reval
                // 12/12; único sem bloco era env_failure, já excluído acima).
                $bridgeExecution = (string) data_get($receipt, 'metadata.runtime_bridge.execution', '');
                if ($arm['arm'] === 'with_atlas' && $bridgeExecution !== 'atlas_cli_dev_efficient') {
                    $groups[$key]['excluded']++;
                    continue;
                }
                // `candidate_preparation_blocked`: o candidato do braço Atlas foi
                // BLOQUEADO antes de ser aplicado/testado (sandbox_apply_failed,
                // create_target_already_exists, git_clone_failed, provider_unavailable
                // etc.). O modelo respondeu, mas o artefato nunca chegou ao corretor
                // → não é falha de CAPACIDADE, é setup/runtime → NÃO MEDIDO, nunca 0
                // falso. O marcador nem sempre é projetado em `failure_reason` pelo
                // import: em 67 recibos históricos ele só existe em
                // `metadata.runtime_bridge.provider_call.error_codes` — ler os dois
                // lugares é o que fecha o vazamento (provado 2026-07-20).
                $reason = (string) ($receipt['failure_reason'] ?? '');
                $bridgeCodes = implode(' ', array_map('strval', (array) data_get(
                    $receipt, 'metadata.runtime_bridge.provider_call.error_codes', []
                )));
                if (($receipt['status'] ?? null) !== 'success'
                    && (str_contains($reason, 'candidate_preparation_blocked')
                        || str_contains($bridgeCodes, 'candidate_preparation_blocked'))) {
                    $groups[$key]['excluded']++;
                    continue;
                }
                // Bridge governado BLOQUEADO antes do artefato (task_ok=false +
                // completion_state=blocked; ex. governor_authority_absent): o
                // candidato nunca chegou ao corretor → NÃO MEDIDO, nunca derrota.
                // Provado em 20260720_142510: 9 units LCB atlas com code_len=0 e
                // blocked — 4 delas viravam 0/4 falso no perfil. Falha DEPOIS de
                // artefato real (completion_state != blocked) segue medida.
                //
                // EXCEÇÃO `model_*`: o bridge nomeia quem falhou no próprio código de
                // erro. `model_empty_patch_plan` = o modelo respondeu (provado: 11
                // unidades, todas com `real_provider=true`, `execution=atlas_cli_dev_
                // efficient` e 1.569 tokens de saída em média, nenhuma com out=0) e
                // MESMO ASSIM não produziu patch. Isso é falha de CAPACIDADE, não de
                // setup — pelo princípio do próprio bridge: erro antes da resposta é
                // ambiente, falha depois da resposta é resultado da tarefa. Excluir
                // aqui removeria derrota legítima do Atlas e inflaria a nota, a mesma
                // fraude espelhada que o guarda de seleção existe pra pegar.
                //
                // `patch_applied > 0` MANDA MAIS QUE `completion_state`. Se o patch foi
                // aplicado, o artefato CHEGOU ao corretor e o benchmark julgou — o
                // resultado é medição legítima, mesmo com o bridge dizendo `blocked`.
                // Provado em 291 unidades blocked do braço Atlas (20/07):
                //   patch_applied=1 + success  179 (61,5%)  ← passavam (regra exige !success)
                //   patch_applied=1 + failure   60 (20,6%)  ← eram EXCLUÍDAS
                //   patch_applied=2 + failure    3
                //   patch_applied=0 + failure   40          ← exclusão correta (sem artefato)
                // Ou seja: das 239 com patch aplicado, os 179 acertos entravam e as 63
                // derrotas saíam. Exclusão seletiva de falha no maior balde do perfil —
                // a fraude espelhada em escala. `bfcl` deixa isso gritante: 18 falhas e
                // 11 acertos com estado de bridge IDÊNTICO (task_ok=false, blocked,
                // patch_applied=1); quem separou os dois foi o corretor, não o bridge.
                $bridge = (array) data_get($receipt, 'metadata.runtime_bridge', []);
                $modelFault = preg_match('/(^|[^a-z_])model_[a-z_]+/', $bridgeCodes) === 1;
                $artifactJudged = ((int) ($bridge['patch_applied'] ?? 0)) > 0;
                if (($receipt['status'] ?? null) !== 'success'
                    && ! $modelFault
                    && ! $artifactJudged
                    && ($bridge['task_ok'] ?? null) === false
                    && ($bridge['completion_state'] ?? null) === 'blocked') {
                    $groups[$key]['excluded']++;
                    continue;
                }
                // Casos DISTINTOS: réplica do mesmo caso é ensaio correlacionado,
                // não problema novo — o piso de confiança do perfil conta casos
                // distintos (auditoria 20/07: packs de 3 casos viravam "measured"
                // com 3 problemas × réplicas; o claim gate do Rivals já exige 10
                // distintos e o perfil aceitava o que o gate rejeitaria).
                $caseKey = (string) ($receipt['case_id'] ?? '');
                if ($caseKey !== '') {
                    $groups[$key]['case_ids'][$caseKey] = true;
                }
                if (($receipt['status'] ?? null) === 'success') {
                    $groups[$key]['passed']++;
                } else {
                    $groups[$key]['failed']++;
                }
                // Suites com `native.score` (nativas de engenharia): a VERDADE é o
                // score, não o status. O driver pode marcar status=success com
                // score=0 (classeval fun_success no braço Atlas) — contar por status
                // viraria FALHA em ACERTO. Score fracionário (archbench rougeL) =>
                // contínuo (média + IC); score 0/1 => binário PELO SCORE. Suites
                // integradas (bfcl/lcb/aider) não trazem score => seguem no status.
                $native = (array) data_get($receipt, 'metadata.native', []);
                if (is_numeric($native['score'] ?? null)) {
                    $value = (float) $native['score'];
                    $groups[$key]['has_score'] = true;
                    $groups[$key]['score_sum'] += $value;
                    $groups[$key]['score_sumsq'] += $value * $value;
                    $groups[$key]['score_n']++;
                    if ($value > 0.0 && $value < 1.0) {
                        $groups[$key]['fractional'] = true;
                    }
                    // Tipo DECLARADO pelo instrumento manda; a forma do dado é só
                    // fallback. Auditoria 20/07: inferir pela fração fazia testeval
                    // (declara continuous, 62/68 valores 0/1) rotular `binary` numa
                    // rodada e flipar pra `mixed` quando a 1ª fração aparecia —
                    // o tipo da capacidade mudava por sorte amostral.
                    $declared = $native['measurement_type'] ?? null;
                    if (is_string($declared) && $declared !== '') {
                        $groups[$key]['declared_type'] = $declared;
                    }
                }
                // EFICIÊNCIA por unidade MEDIDA (spec anti-Goodhart 20/07): só
                // unidades que contaram acima chegam aqui, então wall/tokens nunca
                // misturam descarte de setup. Vitória = score 1 na suíte com score,
                // status success nas integradas — é o que gera o "custo por acerto".
                $isWin = is_numeric($native['score'] ?? null)
                    ? (float) $native['score'] >= 1.0
                    : ($receipt['status'] ?? null) === 'success';
                if (isset($receipt['wall_ms']) && is_numeric($receipt['wall_ms'])) {
                    $groups[$key]['walls'][] = (float) $receipt['wall_ms'];
                    if ($isWin) {
                        $groups[$key]['win_walls'][] = (float) $receipt['wall_ms'];
                    }
                }
                if (isset($receipt['tokens_out']) && is_numeric($receipt['tokens_out'])) {
                    $groups[$key]['tokens_out'][] = (float) $receipt['tokens_out'];
                }
                $at = $this->timestamp($receipt['finished_at'] ?? $receipt['ended_at'] ?? $receipt['started_at'] ?? null);
                if ($at !== null) {
                    $groups[$key]['rounds'][] = $at;
                }
            }

            foreach ($groups as $group) {
                $hasScore = (bool) $group['has_score'];
                // Tipo declarado pelo instrumento manda; forma fracionária é só
                // fallback para recibos antigos sem declaração.
                $declaredType = $group['declared_type'] ?? null;
                $continuous = $hasScore && ($declaredType !== null
                    ? $declaredType === 'continuous'
                    : (bool) $group['fractional']);
                $scoreN = (int) $group['score_n'];

                if ($hasScore) {
                    // Verdade pelo score: total = casos com score; passados = nº de 1s
                    // (soma, já que 0/1). Contínuo usa a média, não passados.
                    $total = $scoreN;
                    $passed = (int) round((float) $group['score_sum']);
                } else {
                    $total = (int) $group['passed'] + (int) $group['failed'];
                    $passed = (int) $group['passed'];
                }
                $excluded = (int) $group['excluded'];
                if ($total <= 0) {
                    // Grupo 100% descartado NÃO vira linha de medição — emitir um row
                    // com score 0 poluiria todo consumidor que lê `score` sem olhar o
                    // N. A contagem sobrevive em `exclusions()`, que o perfil usa pro
                    // guarda de seleção: "rodou e nada chegou ao corretor" ≠ "nunca
                    // rodou", e nenhuma das duas é derrota.
                    continue;
                }
                $walls = (array) $group['walls'];
                $rounds = (array) $group['rounds'];
                $roundAt = $rounds === []
                    ? $this->stateUpdatedAt($runId) ?? $this->timestampFromRunId($runId)
                    : max($rounds);
                $rows[] = [
                    'run_id_public' => $this->publicRunId($runId),
                    'suite' => (string) $group['suite'],
                    'engine' => (string) $group['engine'],
                    'arm' => (string) $group['arm'],
                    // Contínuo: nota = média do score; binário: taxa de acerto.
                    'score' => $continuous
                        ? round((float) $group['score_sum'] / $scoreN, 4)
                        : round($passed / $total, 4),
                    'cases_passed' => $continuous ? $total : $passed,
                    'cases_failed' => $continuous ? 0 : ($total - $passed),
                    'cases_total' => $total,
                    // Unidades DESCARTADAS deste braço (ambiente/setup/bridge blocked).
                    // O perfil usa isto pra detectar sobrevivência: se quase toda falha
                    // de um braço foi excluída, o que sobrou não é amostra, é seleção.
                    'cases_excluded' => $excluded,
                    // Unidades invalidadas por INSTRUMENTO descalibrado (denylist
                    // auditável, simétrica) — fora do guarda de seleção, visível.
                    'cases_instrument_defect' => (int) $group['instrument_defect'],
                    // Casos DISTINTOS medidos (réplica ≠ problema novo): o piso de
                    // confiança do perfil conta isto, não ensaios.
                    'case_ids' => array_keys((array) $group['case_ids']),
                    'distinct_cases' => count((array) $group['case_ids']),
                    // Carrega sum/sumsq/n p/ o perfil poolar média + IC contínuos.
                    'measurement_type' => $continuous ? 'continuous' : 'binary',
                    'score_sum' => $hasScore ? round((float) $group['score_sum'], 6) : null,
                    'score_sumsq' => $hasScore ? round((float) $group['score_sumsq'], 6) : null,
                    'score_n' => $hasScore ? $scoreN : 0,
                    'duration_avg_ms' => $walls === [] ? null : (int) round(array_sum($walls) / count($walls)),
                    // Valores crus por unidade MEDIDA p/ o perfil poolar MEDIANAS
                    // entre rodadas/suítes (mediana de medianas seria errada).
                    'wall_ms_values' => $walls,
                    'win_wall_ms_values' => (array) $group['win_walls'],
                    'tokens_out_values' => (array) $group['tokens_out'],
                    'round_at' => $roundAt ?? now()->toIso8601String(),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['round_at'], $b['round_at']));

        return $rows;
    }

    /**
     * Unidades DESCARTADAS por (suite, engine, braço) em grupos que não geraram
     * nenhuma linha de medição — o caso "rodou e nada chegou ao corretor".
     *
     * Vive fora de `measurements()` de propósito: um row de N=0 com score 0 mentiria
     * pra todo consumidor que lê `score` sem olhar o N. Aqui a contagem existe só
     * pro guarda de seleção do perfil, que precisa distinguir "nunca rodou" de
     * "rodou inteiro e foi tudo descartado".
     *
     * @return list<array{suite:string, engine:string, arm:string, cases_excluded:int}>
     */
    public function exclusions(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }

        $defectRuns = (array) config('atlas_arena.instrument_defect_runs', []);
        $out = [];
        foreach ($this->runIds($runsDir) as $runId) {
            $suite = $this->suiteForRun($runId);
            if ($suite === null) {
                continue;
            }
            $counted = [];
            $dropped = [];
            $defected = [];
            foreach ($this->receipts($runId) as $receipt) {
                $arm = $this->publicArm((string) ($receipt['arm_id'] ?? ''));
                if ($arm === null) {
                    continue;
                }
                $key = $arm['engine'].'|'.$arm['arm'];
                // Denylist de instrumento: braço do run invalidado por inteiro —
                // grupo sem linha de medição carrega o contador por aqui.
                $defect = $defectRuns[$runId] ?? null;
                if (is_array($defect) && $arm['arm'] === (string) ($defect['arm'] ?? 'with_atlas')) {
                    $defected[$key] = ($defected[$key] ?? 0) + 1;

                    continue;
                }
                if ($this->isExcludedReceipt($receipt)) {
                    $dropped[$key] = ($dropped[$key] ?? 0) + 1;
                } else {
                    $counted[$key] = true;
                }
            }
            foreach (array_unique(array_merge(array_keys($dropped), array_keys($defected))) as $key) {
                if (isset($counted[$key])) {
                    continue; // já contabilizado no `cases_excluded` da própria linha
                }
                [$engine, $arm] = explode('|', $key, 2);
                $out[] = [
                    'suite' => $suite,
                    'engine' => $engine,
                    'arm' => $arm,
                    'cases_excluded' => (int) ($dropped[$key] ?? 0),
                    'cases_instrument_defect' => (int) ($defected[$key] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $receipt */
    private function isExcludedReceipt(array $receipt): bool
    {
        if (in_array($receipt['failure_class'] ?? null, ['environment', 'environment_failure'], true)) {
            return true;
        }
        if (($receipt['status'] ?? null) === 'success') {
            return false;
        }
        $reason = (string) ($receipt['failure_reason'] ?? '');
        $bridgeCodes = implode(' ', array_map('strval', (array) data_get(
            $receipt, 'metadata.runtime_bridge.provider_call.error_codes', []
        )));
        if (str_contains($reason, 'candidate_preparation_blocked')
            || str_contains($bridgeCodes, 'candidate_preparation_blocked')) {
            return true;
        }
        // `model_*` = o bridge nomeando o modelo como causa → falha de capacidade,
        // medida. Espelha a regra de `measurements()`; divergir aqui faria o guarda
        // de seleção contar um descarte que não existe.
        if (preg_match('/(^|[^a-z_])model_[a-z_]+/', $bridgeCodes) === 1) {
            return false;
        }
        $bridge = (array) data_get($receipt, 'metadata.runtime_bridge', []);
        // Patch aplicado = artefato julgado pelo corretor → medição, não descarte.
        if (((int) ($bridge['patch_applied'] ?? 0)) > 0) {
            return false;
        }

        return ($bridge['task_ok'] ?? null) === false
            && ($bridge['completion_state'] ?? null) === 'blocked';
    }

    /** @return list<array<string, mixed>> */
    public function queuedRequests(): array
    {
        $path = $this->queuePath();
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(
            array_filter(array_map(
                fn (string $line): mixed => json_decode($line, true),
                array_filter(explode(PHP_EOL, (string) file_get_contents($path))),
            ), 'is_array'),
            fn (array $entry): bool => $this->isPublicEngine((string) ($entry['engine'] ?? ''))
        ));
    }

    /**
     * Transição de status da fila (worker de drenagem) — reescreve o JSONL
     * preservando as demais linhas cruas (inclusive as invisíveis ao público).
     *
     * @param  list<string>  $runIdsPublic
     * @param  array<string, mixed>  $updates
     */
    public function updateQueuedRequests(array $runIdsPublic, array $updates): void
    {
        if ($runIdsPublic === []) {
            return;
        }

        $this->mutateQueuedRequestsAtomically(
            static function (array $entries) use ($runIdsPublic, $updates): array {
                foreach ($entries as &$entry) {
                    if (in_array($entry['run_id_public'] ?? null, $runIdsPublic, true)) {
                        $entry = array_merge($entry, $updates);
                    }
                }
                unset($entry);

                return ['entries' => $entries, 'result' => null];
            }
        );
    }

    /**
     * @param  list<string>  $runIdsPublic
     * @param  list<string>  $fromStatuses
     * @param  array<string,mixed>  $updates
     * @return list<string>
     */
    public function transitionQueuedRequests(
        array $runIdsPublic,
        array $fromStatuses,
        array $updates
    ): array {
        if ($runIdsPublic === []) {
            return [];
        }

        return $this->mutateQueuedRequestsAtomically(
            static function (array $entries) use ($runIdsPublic, $fromStatuses, $updates): array {
                $transitioned = [];
                foreach ($entries as &$entry) {
                    $runId = (string) ($entry['run_id_public'] ?? '');
                    if (in_array($runId, $runIdsPublic, true)
                        && in_array((string) ($entry['status'] ?? ''), $fromStatuses, true)) {
                        $entry = array_merge($entry, $updates);
                        $transitioned[] = $runId;
                    }
                }
                unset($entry);

                return ['entries' => $entries, 'result' => $transitioned];
            }
        );
    }

    public function measurementHasStatus(string $measurementId, string $status): bool
    {
        foreach ($this->queuedRequests() as $entry) {
            if (($entry['measurement_id_public'] ?? null) === $measurementId
                && ($entry['status'] ?? null) === $status) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function acknowledgeMeasurementStop(string $measurementId, string $stoppedAt): array
    {
        return $this->mutateQueuedRequestsAtomically(
            static function (array $entries) use ($measurementId, $stoppedAt): array {
                $stopped = [];
                foreach ($entries as &$entry) {
                    if (($entry['measurement_id_public'] ?? null) !== $measurementId
                        || ($entry['status'] ?? null) !== 'stopping') {
                        continue;
                    }
                    $entry = array_merge($entry, [
                        'status' => 'stopped',
                        'stopped_at' => $stoppedAt,
                        'drained_at' => $stoppedAt,
                        'terminal_receipt_hash' => $entry['stop_receipt_hash'] ?? null,
                    ]);
                    $stopped[] = (string) ($entry['run_id_public'] ?? '');
                }
                unset($entry);

                return ['entries' => $entries, 'result' => $stopped];
            }
        );
    }

    public function appendQueuedRequest(array $entry): void
    {
        $this->withQueueLock(function () use ($entry): void {
            $path = $this->queuePath();
            $existing = is_file($path) ? rtrim((string) file_get_contents($path), PHP_EOL) : '';
            $contents = ($existing === '' ? '' : $existing.PHP_EOL)
                .json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL;
            AtomicWriter::write($path, $contents);
        });
    }

    /**
     * Linearizable read-modify-write for lifecycle transitions.
     *
     * The callback must preserve the number and order of decoded entries.
     * Malformed/raw lines remain byte-for-byte untouched.
     */
    public function mutateQueuedRequestsAtomically(callable $mutation): mixed
    {
        return $this->withQueueLock(function () use ($mutation): mixed {
            $path = $this->queuePath();
            $lines = is_file($path)
                ? array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($path))))
                : [];
            $entries = [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }

            $outcome = $mutation($entries);
            $nextEntries = is_array($outcome) ? ($outcome['entries'] ?? null) : null;
            if (! is_array($nextEntries) || count($nextEntries) !== count($entries)) {
                throw new RuntimeException('arena_queue_mutation_must_preserve_entries');
            }

            $entryIndex = 0;
            $rewritten = [];
            foreach ($lines as $line) {
                if (is_array(json_decode($line, true))) {
                    $line = json_encode($nextEntries[$entryIndex++], JSON_UNESCAPED_SLASHES);
                }
                $rewritten[] = $line;
            }
            if ($rewritten !== []) {
                AtomicWriter::write($path, implode(PHP_EOL, $rewritten).PHP_EOL);
            }

            return $outcome['result'] ?? null;
        });
    }

    public function queuePath(): string
    {
        return rtrim((string) config('atlas_rivals.storage_root'), '/').'/arena/queued_runs.jsonl';
    }

    public function publicRunId(string $runId): string
    {
        return 'ar_'.substr(hash('sha256', $runId), 0, 20);
    }

    private function withQueueLock(callable $operation): mixed
    {
        $path = $this->queuePath().'.lock';
        RunPaths::ensureDir(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('arena_queue_lock_failed');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function runIds(string $runsDir): array
    {
        $ids = array_values(array_filter(
            array_diff(scandir($runsDir) ?: [], ['.', '..']),
            fn (string $runId): bool => is_dir($runsDir.'/'.$runId)
        ));
        sort($ids);

        return $ids;
    }

    private function suiteForRun(string $runId): ?string
    {
        $manifest = $this->readJson(RunPaths::nativeManifestPath($runId));
        $suite = $manifest['suite_id'] ?? null;
        if (is_string($suite) && $suite !== '') {
            return $suite;
        }

        $plan = $this->readJson(RunPaths::planPath($runId));
        $suite = $plan['suite_id'] ?? null;

        return is_string($suite) && $suite !== '' ? $suite : null;
    }

    /** @return list<array<string, mixed>> */
    private function receipts(string $runId): array
    {
        $path = RunPaths::receiptsPath($runId);
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line): mixed => json_decode($line, true),
            array_filter(explode(PHP_EOL, (string) file_get_contents($path))),
        ), 'is_array'));
    }

    /** @return array{engine:string, arm:string}|null */
    private function publicArm(string $armId): ?array
    {
        if (! str_contains($armId, '@')) {
            return null;
        }
        [$engine, $runtime] = explode('@', $armId, 2);
        $arm = match ($runtime) {
            'bare', 'baseline' => 'baseline',
            'atlas_dev', 'with_atlas' => 'with_atlas',
            default => null,
        };
        if ($engine === '' || $arm === null || ! $this->isPublicEngine($engine)) {
            return null;
        }

        return ['engine' => $engine, 'arm' => $arm];
    }

    private function stateUpdatedAt(string $runId): ?string
    {
        $state = $this->readJson(RunPaths::runDir($runId).'/state.json');

        return $this->timestamp($state['updated_at'] ?? null);
    }

    private function timestampFromRunId(string $runId): ?string
    {
        if (preg_match('/^(\d{8})_(\d{6})_/', $runId, $m) !== 1) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Ymd His', $m[1].' '.$m[2], new \DateTimeZone('UTC'));

        return $dt === false ? null : $dt->format(DATE_ATOM);
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : gmdate(DATE_ATOM, $time);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
