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
                // Falha de AMBIENTE (caso quebrado, integração, proxy) não é nota
                // do modelo — não conta como falha de nenhum braço. O texto real
                // no recibo é `environment_failure`; o `=== 'environment'` antigo
                // NUNCA casava, então os artefatos viravam "0" e o app pintava o
                // braço com-Atlas como -10 catastrófico onde na verdade era NÃO
                // MEDIDO (inspect/tau2/lcb via proxy quebrado). Legacy incluído.
                if (in_array($receipt['failure_class'] ?? null, ['environment', 'environment_failure'], true)) {
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
                    continue;
                }
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
                    'walls' => [],
                    'rounds' => [],
                    'has_score' => false,
                    'fractional' => false,
                    'score_sum' => 0.0,
                    'score_sumsq' => 0.0,
                    'score_n' => 0,
                ];
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
                }
                if (isset($receipt['wall_ms']) && is_numeric($receipt['wall_ms'])) {
                    $groups[$key]['walls'][] = (float) $receipt['wall_ms'];
                }
                $at = $this->timestamp($receipt['finished_at'] ?? $receipt['ended_at'] ?? $receipt['started_at'] ?? null);
                if ($at !== null) {
                    $groups[$key]['rounds'][] = $at;
                }
            }

            foreach ($groups as $group) {
                $hasScore = (bool) $group['has_score'];
                $continuous = $hasScore && (bool) $group['fractional'];
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
                if ($total <= 0) {
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
                    // Carrega sum/sumsq/n p/ o perfil poolar média + IC contínuos.
                    'measurement_type' => $continuous ? 'continuous' : 'binary',
                    'score_sum' => $hasScore ? round((float) $group['score_sum'], 6) : null,
                    'score_sumsq' => $hasScore ? round((float) $group['score_sumsq'], 6) : null,
                    'score_n' => $hasScore ? $scoreN : 0,
                    'duration_avg_ms' => $walls === [] ? null : (int) round(array_sum($walls) / count($walls)),
                    'round_at' => $roundAt ?? now()->toIso8601String(),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['round_at'], $b['round_at']));

        return $rows;
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
