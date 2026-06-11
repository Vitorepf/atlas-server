<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Harness;

use App\Models\AiLearningProposal;
use App\Services\Ai\Cognitive\Failure\FailureRecurrenceMetricService;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * AP-819 — AUTOPILOT do Self-Harness (diretiva do operador 2026-06-11:
 * "automático, com base matemática e certezas" — sem aprovação manual por edit).
 *
 * O loop completo do paper, fechado por TRÊS gates matemáticos e auto-reverse:
 *
 *   1. ESTRUTURAL — o edit só pode existir dentro da Harness Surface v1
 *      (allowlist + bounds; fora ⇒ o applier recusa por construção).
 *   2. NÃO-REGRESSÃO DUPLA (a regra do paper) — suite congelada ANTES e DEPOIS
 *      do apply: qualquer regressão vs baseline (Δin<0 ∨ Δho<0) ⇒ REVERSE
 *      imediato no mesmo run. Baseline selado por suite_hash (anti-tamper).
 *   3. RECORRÊNCIA NO MUNDO REAL (G3) — após a janela de observação, o cluster
 *      de origem é re-contado no outcome CRU (ai_job_attempts re-classificado,
 *      caminho ineditável). Estritamente menor ⇒ CONFIRMED; senão ⇒ AUTO-REVERSE.
 *
 *   Disciplina experimental: 1 edit por run, 1 experimento ativo por chave,
 *   proposta já tentada nunca re-tenta (estado durável). Cada transição emite
 *   recibo no Evidence Ledger e fica visível em GET /ai/harness (o app).
 *
 * Honestidade estatística: contagens pequenas ⇒ a regra é dura e simétrica
 * (melhora estrita confirma; igual-ou-pior reverte). Sem p-valor fabricado —
 * com N baixo, o custo de reverter um edit reversível é ~zero, então o limiar
 * conservador é a escolha matemática correta.
 */
class AtlasHarnessAutopilot
{
    public const SCHEMA_VERSION = 'atlas.cognitive.harness_autopilot.v1';

    public const ACTOR = 'atlas_autopilot';

    private ?string $statePathOverride = null;

    public function __construct(
        private readonly AtlasHarnessSurface $surface,
        private readonly AtlasHarnessFrozenSuite $suite,
        private readonly AtlasLearningProposalApplier $applier,
        private readonly FailureRecurrenceMetricService $recurrence,
    ) {}

    public function setStatePathForTesting(?string $path): void
    {
        $this->statePathOverride = $path;
    }

    public function statePath(): string
    {
        if ($this->statePathOverride !== null) {
            return $this->statePathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'harness_autopilot_state.json';
    }

    public function enabled(): bool
    {
        return (bool) config('atlas.ai.harness_autopilot.enabled', false);
    }

    private function observationDays(): int
    {
        return max(1, (int) config('atlas.ai.harness_autopilot.observation_days', 7));
    }

    /**
     * Fase APPLY: pega a proposta harness_config `proposed` mais antiga ainda não
     * tentada e a aplica sob os gates 1+2. Cap estrutural: 1 por run.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        if (! $this->enabled()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'disabled', 'flag' => 'ATLAS_HARNESS_AUTOPILOT_ENABLED'];
        }
        if (! DatabaseTableAvailability::has('ai_learning_proposals')) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $state = $this->readState();
        $candidate = $this->nextCandidate($state);
        if ($candidate === null) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'nothing_to_apply'];
        }

        // GATE 2 (pré): a suite precisa de baseline selado E estar exatamente nele
        // antes do edit — senão não há referência matemática para julgar o depois.
        $pre = $this->suite->promotionVerdict();
        if (($pre['verdict'] ?? '') === 'no_baseline') {
            $this->suite->sealBaseline();
            $pre = $this->suite->promotionVerdict();
        }
        if (! in_array($pre['verdict'] ?? '', ['no_gain', 'promote'], true)) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'suite_unstable_pre_apply', 'pre' => $pre];
        }

        $key = (string) data_get($candidate->proposed_state, 'key');
        $cluster = (string) data_get($candidate->proposed_state, 'from_cluster', '');

        // Métrica do MUNDO antes do edit (G3, outcome cru), janela de observação.
        $days = $this->observationDays();
        $preCount = $cluster !== ''
            ? $this->recurrence->clusterCountBetween($cluster, now()->subDays($days), now())
            : null;

        try {
            app(AtlasLearningProposalService::class)->approve(
                $candidate,
                self::ACTOR,
                'autopilot: math-gated (surface bounds + frozen-suite double non-regression + raw-recurrence watch)',
            );
        } catch (Throwable $exception) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'approve_failed', 'reason' => mb_substr($exception->getMessage(), 0, 200)];
        }

        $applied = $this->applier->apply($candidate, self::ACTOR);
        if (($applied['applied'] ?? false) !== true) {
            $this->writeStateEntry($state, (string) $candidate->getKey(), [
                'key' => $key, 'cluster' => $cluster, 'outcome' => 'apply_refused',
                'reason' => $applied['reason'] ?? null, 'at' => now()->toJSON(),
            ]);

            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'apply_refused', 'reason' => $applied['reason'] ?? null];
        }

        // GATE 2 (pós): regressão na suite congelada ⇒ REVERSE imediato.
        $post = $this->suite->promotionVerdict();
        if (($post['delta_held_in'] ?? 0) < 0 || ($post['delta_held_out'] ?? 0) < 0 || ($post['verdict'] ?? '') === 'suite_hash_mismatch') {
            $this->applier->reverse($candidate, self::ACTOR);
            $this->writeStateEntry($state, (string) $candidate->getKey(), [
                'key' => $key, 'cluster' => $cluster, 'outcome' => 'auto_reversed_suite_regression',
                'post' => $post, 'at' => now()->toJSON(),
            ]);

            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'auto_reversed_suite_regression', 'post' => $post];
        }

        $this->writeStateEntry($state, (string) $candidate->getKey(), [
            'key' => $key,
            'cluster' => $cluster,
            'outcome' => 'applied_under_observation',
            'applied_at' => now()->toJSON(),
            'observation_days' => $days,
            'pre_count' => $preCount,
            'change' => $applied['change'] ?? null,
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'applied_under_observation',
            'proposal_id' => (string) $candidate->getKey(),
            'key' => $key,
            'cluster' => $cluster,
            'pre_count' => $preCount,
            'observation_days' => $days,
            'suite_post' => $post,
        ];
    }

    /**
     * Fase MONITOR (gate 3): para cada edit em observação cuja janela venceu,
     * re-conta o cluster no outcome cru. Estritamente menor ⇒ CONFIRMED;
     * igual-ou-pior ⇒ AUTO-REVERSE. Sem cluster rastreável ⇒ confirma pela
     * suite apenas, anotado honestamente como 'confirmed_suite_only'.
     *
     * @return array<string,mixed>
     */
    public function monitor(): array
    {
        if (! $this->enabled()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'disabled'];
        }

        $state = $this->readState();
        $report = ['schema_version' => self::SCHEMA_VERSION, 'status' => 'ok', 'checked' => 0, 'confirmed' => 0, 'auto_reversed' => 0, 'still_observing' => 0, 'details' => []];

        foreach ($state as $proposalId => $entry) {
            if (($entry['outcome'] ?? '') !== 'applied_under_observation') {
                continue;
            }
            $appliedAt = \Illuminate\Support\Carbon::parse((string) $entry['applied_at']);
            $days = max(1, (int) ($entry['observation_days'] ?? $this->observationDays()));
            if ($appliedAt->copy()->addDays($days)->isFuture()) {
                $report['still_observing']++;

                continue;
            }
            $report['checked']++;

            $cluster = (string) ($entry['cluster'] ?? '');
            $preCount = $entry['pre_count'] ?? null;

            if ($cluster === '' || $preCount === null) {
                $state[$proposalId]['outcome'] = 'confirmed_suite_only';
                $state[$proposalId]['confirmed_at'] = now()->toJSON();
                $report['confirmed']++;
                $report['details'][] = ['proposal_id' => $proposalId, 'verdict' => 'confirmed_suite_only'];

                continue;
            }

            $postCount = $this->recurrence->clusterCountBetween($cluster, $appliedAt, $appliedAt->copy()->addDays($days));
            $improved = $postCount < (int) $preCount;

            if ($improved) {
                $state[$proposalId]['outcome'] = 'confirmed';
                $state[$proposalId]['post_count'] = $postCount;
                $state[$proposalId]['confirmed_at'] = now()->toJSON();
                $report['confirmed']++;
                $report['details'][] = ['proposal_id' => $proposalId, 'verdict' => 'confirmed', 'pre_count' => $preCount, 'post_count' => $postCount];
            } else {
                $proposal = AiLearningProposal::query()->find($proposalId);
                if ($proposal !== null) {
                    $this->applier->reverse($proposal, self::ACTOR);
                }
                $state[$proposalId]['outcome'] = 'auto_reversed_no_cluster_improvement';
                $state[$proposalId]['post_count'] = $postCount;
                $state[$proposalId]['reversed_at'] = now()->toJSON();
                $report['auto_reversed']++;
                $report['details'][] = ['proposal_id' => $proposalId, 'verdict' => 'auto_reversed_no_cluster_improvement', 'pre_count' => $preCount, 'post_count' => $postCount];
            }
        }

        $this->writeState($state);
        $this->recordReceipt('autopilot_monitor', $report);

        return $report;
    }

    /**
     * Estado completo para o app (GET /ai/harness): experimentos ativos,
     * confirmados e revertidos — transparência substitui pré-aprovação.
     *
     * @return array<string,array<string,mixed>>
     */
    public function state(): array
    {
        return $this->readState();
    }

    private function nextCandidate(array $state): ?AiLearningProposal
    {
        $activeKeys = [];
        foreach ($state as $entry) {
            if (($entry['outcome'] ?? '') === 'applied_under_observation') {
                $activeKeys[] = (string) ($entry['key'] ?? '');
            }
        }

        foreach (AiLearningProposal::query()
            ->whereIn('kind', ['harness_config', 'harness_instruction'])
            ->where('status', 'proposed')
            ->orderBy('created_at')
            ->limit(20)
            ->get() as $proposal) {
            $id = (string) $proposal->getKey();
            $key = (string) data_get($proposal->proposed_state, 'key');
            if (isset($state[$id])) {
                continue; // já tentada — nunca re-tenta.
            }
            if (in_array($key, $activeKeys, true)) {
                continue; // 1 experimento ativo por chave.
            }

            return $proposal;
        }

        return null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function readState(): array
    {
        $path = $this->statePath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,array<string,mixed>>  $state
     * @param  array<string,mixed>  $entry
     */
    private function writeStateEntry(array $state, string $proposalId, array $entry): void
    {
        $state[$proposalId] = $entry;
        $this->writeState($state);
        $this->recordReceipt('autopilot_'.(string) ($entry['outcome'] ?? 'transition'), ['proposal_id' => $proposalId] + $entry);
    }

    /**
     * @param  array<string,array<string,mixed>>  $state
     */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordReceipt(string $decision, array $payload): void
    {
        try {
            app(\App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger::class)->record(
                \App\Services\Ai\Kernel\Evidence\LedgerEventType::DecisionIssued,
                ['schema_version' => self::SCHEMA_VERSION, 'decision' => $decision] + $payload,
                [
                    'operator_id' => self::ACTOR,
                    'emitter_stage' => 'atlas.cognitive.harness_autopilot',
                    'emitter_version' => 'harness-autopilot-v1',
                ],
            );
        } catch (Throwable) {
            // recibo é best-effort; os gates não dependem dele.
        }
    }
}
