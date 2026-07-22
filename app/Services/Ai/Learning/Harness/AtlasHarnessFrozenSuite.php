<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Harness;

use Throwable;

/**
 * AP-819 Obra B (F4′) — a suite CONGELADA do harness (o oráculo do paper).
 *
 * Probes determinísticos, zero-spend, do COMPORTAMENTO do harness Atlas —
 * split held-in/held-out FIXADO ANTES de qualquer loop (pela ordem de
 * declaração: pares=held_in, ímpares=held_out; selado por suite_hash).
 *
 * Regra de promoção do paper (não-regressão dupla):
 *     Δ_in ≥ 0  ∧  Δ_ho ≥ 0  ∧  max(Δ_in, Δ_ho) > 0
 *
 * G1/G3 estruturais: esta suite NÃO pertence à Harness Surface (um
 * harness_config não pode editá-la) e os probes exercitam código real via o
 * container — um edit de config dentro dos bounds não consegue reescrever o
 * oráculo. O baseline é selado em arquivo com o suite_hash; um baseline de
 * outra versão da suite é REJEITADO na comparação (anti-gaming).
 */
class AtlasHarnessFrozenSuite
{
    public const SCHEMA_VERSION = 'atlas.cognitive.harness_frozen_suite.v1';

    private ?string $baselinePathOverride = null;

    public function setBaselinePathForTesting(?string $path): void
    {
        $this->baselinePathOverride = $path;
    }

    public function baselinePath(): string
    {
        if ($this->baselinePathOverride !== null) {
            return $this->baselinePathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'harness_suite_baseline.json';
    }

    /**
     * O conjunto CONGELADO de probes (ids estáveis; mudar isto muda o
     * suite_hash e invalida baselines antigos — de propósito).
     *
     * @return array<string,callable():bool>
     */
    private function probes(): array
    {
        $surface = app(AtlasHarnessSurface::class);

        return [
            'surface_accepts_in_bounds_edit' => fn (): bool => $surface->validate('runtime_control.timeout_seconds', 300)['valid'] === true,
            'surface_rejects_out_of_bounds' => fn (): bool => $surface->validate('runtime_control.timeout_seconds', 999999)['valid'] === false,
            'surface_rejects_unknown_key' => fn (): bool => $surface->validate('signal_pipeline.failure_classifier', 1)['valid'] === false,
            'surface_rejects_non_integer' => fn (): bool => $surface->validate('runtime_control.max_attempts', 'two')['valid'] === false,
            'classifier_clusters_identical_failures' => function (): bool {
                $classifier = app(\App\Services\Ai\Cognitive\Failure\FailureSignatureClassifier::class);
                $a = $classifier->classify(['domain' => 'engineering', 'event_type' => 'probe_failure', 'message' => 'probe timeout waiting provider']);
                $b = $classifier->classify(['domain' => 'engineering', 'event_type' => 'probe_failure', 'message' => 'probe timeout waiting provider']);

                return $a['signature_key'] === $b['signature_key'];
            },
            'classifier_separates_distinct_event_types' => function (): bool {
                $classifier = app(\App\Services\Ai\Cognitive\Failure\FailureSignatureClassifier::class);
                $a = $classifier->classify(['domain' => 'engineering', 'event_type' => 'probe_failure_a', 'message' => 'x']);
                $b = $classifier->classify(['domain' => 'engineering', 'event_type' => 'probe_failure_b', 'message' => 'x']);

                return $a['signature_key'] !== $b['signature_key'];
            },
            'capture_gate_rejects_meta_stub' => function (): bool {
                $gate = app(\App\Services\Ai\Compounding\AtlasCaptureQualityGate::class);

                return $gate->assess(['kind' => 'memory', 'claim' => 'Specialist flow x emitted a learning signal contract for future routing, retrieval and execution evaluation.'])['admit'] === false;
            },
            'capture_gate_admits_substantive_claim' => function (): bool {
                $gate = app(\App\Services\Ai\Compounding\AtlasCaptureQualityGate::class);

                return $gate->assess(['kind' => 'memory', 'claim' => 'Hermes decision receipts expire before provider start when dispatch queues back up; pre-issue receipts inside the dispatch transaction.'])['admit'] === true;
            },
            'cost_guard_hard_gate_math_fires' => function (): bool {
                $guard = app(\App\Services\Ai\Caching\AiCallCostGuard::class);
                $job = new \App\Models\AiJob;
                $job->kind = 'probe';
                $job->payload = [];
                $job->metadata = [];
                $evaluation = $guard->evaluate($job, str_repeat('a', 400000), 0.0, 0.0001);

                return $evaluation['hard_exceeded'] === true;
            },
            'cost_guard_at_threshold_proceeds' => function (): bool {
                $guard = app(\App\Services\Ai\Caching\AiCallCostGuard::class);
                $job = new \App\Models\AiJob;
                $job->kind = 'probe';
                $job->payload = [];
                $job->metadata = [];
                $evaluation = $guard->evaluate($job, 'tiny', 0.0, 999999.0);

                return $evaluation['hard_exceeded'] === false;
            },
            'applier_refuses_unapproved_proposal' => function (): bool {
                $applier = app(\App\Services\Ai\Compounding\AtlasLearningProposalApplier::class);
                $proposal = (new \App\Models\AiLearningProposal)->forceFill(['status' => 'proposed', 'kind' => 'harness_config']);

                return $applier->apply($proposal)['applied'] === false;
            },
            'harness_config_never_auto_applies' => function (): bool {
                return app(\App\Services\Ai\Compounding\AtlasLearningProposalApplier::class)
                    ->supportsAutoApply('harness_config') === false;
            },
            // Surface v2 — o espaço de busca de instruções é FINITO e fechado.
            'instruction_surface_accepts_declared_variant' => function (): bool {
                $s = app(AtlasHarnessInstructionSurface::class);
                $space = $s->searchSpace('worker.tool_error_recovery');

                return $space !== [] && $s->validate('worker.tool_error_recovery', $space[0])['valid'] === true;
            },
            'instruction_surface_rejects_freeform_text' => function (): bool {
                return app(AtlasHarnessInstructionSurface::class)
                    ->validate('worker.tool_error_recovery', 'texto arbitrário fora da biblioteca declarada')['valid'] === false;
            },
            'instruction_surface_rejects_unknown_section' => function (): bool {
                return app(AtlasHarnessInstructionSurface::class)
                    ->validate('signal_pipeline.bridge_mappings', 'x')['valid'] === false;
            },
            'plan_gate_rejects_cyclic_dag' => function (): bool {
                $gate = app(\App\Services\Ai\AtlasDecide\AtlasConductorPlanGate::class);
                $verdict = $gate->validate([
                    'nodes' => [
                        ['node_id' => 'a', 'task_category' => 'probe', 'role' => 'primary', 'depends_on' => ['b']],
                        ['node_id' => 'b', 'task_category' => 'probe', 'role' => 'primary', 'depends_on' => ['a']],
                    ],
                ], ['scope' => 'global']);

                return ($verdict['ok'] ?? true) === false && ($verdict['reason'] ?? '') === 'plan_has_cycle';
            },
            'plan_gate_accepts_valid_two_node_dag' => function (): bool {
                $gate = app(\App\Services\Ai\AtlasDecide\AtlasConductorPlanGate::class);
                $verdict = $gate->validate([
                    'nodes' => [
                        ['node_id' => 'a', 'task_category' => 'probe', 'role' => 'primary', 'depends_on' => []],
                        ['node_id' => 'b', 'task_category' => 'probe', 'role' => 'primary', 'depends_on' => ['a']],
                    ],
                ], ['scope' => 'global']);

                return ($verdict['ok'] ?? false) === true;
            },
        ];
    }

    public function suiteHash(): string
    {
        return hash('sha256', implode('|', array_keys($this->probes())));
    }

    /**
     * Roda a suite inteira. Um probe que LANÇA conta como fail (nunca derruba a
     * avaliação — o oráculo reporta, não explode).
     *
     * @return array<string,mixed>
     */
    public function evaluate(): array
    {
        $results = ['held_in' => [], 'held_out' => []];
        $index = 0;
        foreach ($this->probes() as $id => $probe) {
            $split = $index % 2 === 0 ? 'held_in' : 'held_out';
            try {
                $passed = $probe() === true;
            } catch (Throwable) {
                $passed = false;
            }
            $results[$split][$id] = $passed;
            $index++;
        }

        $rate = static function (array $split): float {
            $total = count($split);

            return $total > 0 ? round(count(array_filter($split)) / $total, 4) : 0.0;
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'suite_hash' => $this->suiteHash(),
            'held_in' => ['pass_rate' => $rate($results['held_in']), 'results' => $results['held_in']],
            'held_out' => ['pass_rate' => $rate($results['held_out']), 'results' => $results['held_out']],
            'evaluated_at' => now()->toJSON(),
        ];
    }

    /**
     * Sela o baseline (pré-loop). O suite_hash congela junto.
     *
     * @return array<string,mixed>
     */
    public function sealBaseline(): array
    {
        $evaluation = $this->evaluate();
        $path = $this->baselinePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($path, json_encode($evaluation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $evaluation;
    }

    /**
     * A regra de promoção do paper: Δ_in ≥ 0 ∧ Δ_ho ≥ 0 ∧ max > 0, contra o
     * baseline SELADO (mesmo suite_hash, senão a comparação é REJEITADA).
     *
     * @return array<string,mixed>
     */
    public function promotionVerdict(?array $current = null): array
    {
        $baseline = $this->readBaseline();
        if ($baseline === null) {
            return ['schema_version' => self::SCHEMA_VERSION, 'verdict' => 'no_baseline', 'promote' => false];
        }
        $current ??= $this->evaluate();
        if (($baseline['suite_hash'] ?? '') !== ($current['suite_hash'] ?? '__none__')) {
            return ['schema_version' => self::SCHEMA_VERSION, 'verdict' => 'suite_hash_mismatch', 'promote' => false];
        }

        $deltaIn = round((float) data_get($current, 'held_in.pass_rate') - (float) data_get($baseline, 'held_in.pass_rate'), 4);
        $deltaHo = round((float) data_get($current, 'held_out.pass_rate') - (float) data_get($baseline, 'held_out.pass_rate'), 4);
        $promote = $deltaIn >= 0.0 && $deltaHo >= 0.0 && max($deltaIn, $deltaHo) > 0.0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $promote ? 'promote' : ($deltaIn < 0.0 || $deltaHo < 0.0 ? 'regression' : 'no_gain'),
            'promote' => $promote,
            'delta_held_in' => $deltaIn,
            'delta_held_out' => $deltaHo,
            'rule' => 'delta_in>=0 AND delta_ho>=0 AND max(delta)>0',
            'baseline_evaluated_at' => $baseline['evaluated_at'] ?? null,
        ];
    }

    /**
     * Mesma regra, aplicável a pares before/after arbitrários (para testes da
     * regra em si, sem tocar o baseline em disco).
     *
     * @param  array{held_in:float,held_out:float}  $before
     * @param  array{held_in:float,held_out:float}  $after
     */
    public function ruleAllowsPromotion(array $before, array $after): bool
    {
        $deltaIn = $after['held_in'] - $before['held_in'];
        $deltaHo = $after['held_out'] - $before['held_out'];

        return $deltaIn >= 0.0 && $deltaHo >= 0.0 && max($deltaIn, $deltaHo) > 0.0;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readBaseline(): ?array
    {
        $path = $this->baselinePath();
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
