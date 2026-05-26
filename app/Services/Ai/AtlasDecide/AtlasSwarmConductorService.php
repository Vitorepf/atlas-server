<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Swarm Conductor — Patamar 4 · 4.5.
 *
 * Composer que monta dispatch multi-arm (K=1..5) consumindo ADML para a
 * rota recomendada + runner-up, atravessando Constitutional Kernel +
 * Autonomy Admission, e emitindo envelope canônico SEM executar provider
 * e SEM claim de winner.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-swarm-conductor.md
 *
 * Invariantes:
 *   - Não duplica routing (delegate ADML);
 *   - Não claim winner/rivals/benchmark (hardcoded em claim_policy);
 *   - Kernel + Admission obrigatórios;
 *   - insufficient_evidence → effective_parallelism=0;
 *   - append-only JSONL local.
 */
final class AtlasSwarmConductorService
{
    public const ENVELOPE_SCHEMA = 'atlas.swarm_conductor.dispatch_envelope.v1';

    public const ARM_SCHEMA = 'atlas.swarm_conductor.arm.v1';

    public const MIN_PARALLELISM = 1;

    public const MAX_PARALLELISM = 5;

    public const ARM_ORIGIN_RECOMMENDED = 'recommended';

    public const ARM_ORIGIN_RUNNER_UP = 'runner_up';

    public const ARM_ORIGIN_LOCAL_FALLBACK = 'local_fallback';

    public const LOCAL_FALLBACK_PROVIDER = 'atlas_local';

    public const LOCAL_FALLBACK_MODEL = 'atlas_local_default';

    public const OUTCOME_SCHEMA = 'atlas.swarm_conductor.outcome.v1';

    public const OUTCOME_STATUS_SUCCESS = 'success';

    public const OUTCOME_STATUS_FAILURE = 'failure';

    public const OUTCOME_STATUS_TIMEOUT = 'timeout';

    public const OUTCOME_STATUS_HUMAN_OVERRIDE = 'human_override';

    public const VALID_OUTCOME_STATUSES = [
        self::OUTCOME_STATUS_SUCCESS,
        self::OUTCOME_STATUS_FAILURE,
        self::OUTCOME_STATUS_TIMEOUT,
        self::OUTCOME_STATUS_HUMAN_OVERRIDE,
    ];

    private ?string $dispatchesLogOverride = null;

    private ?string $outcomesLogOverride = null;

    public function __construct(
        private readonly AtlasDecideMetaLearningService $adml,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setDispatchesLogPathForTesting(?string $path): void
    {
        $this->dispatchesLogOverride = $path;
    }

    public function setOutcomesLogPathForTesting(?string $path): void
    {
        $this->outcomesLogOverride = $path;
    }

    public function dispatchesLogPath(): string
    {
        if ($this->dispatchesLogOverride !== null) {
            return $this->dispatchesLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/swarm')
            : sys_get_temp_dir().'/atlas/swarm';

        return $base.DIRECTORY_SEPARATOR.'dispatches.jsonl';
    }

    public function outcomesLogPath(): string
    {
        if ($this->outcomesLogOverride !== null) {
            return $this->outcomesLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/swarm')
            : sys_get_temp_dir().'/atlas/swarm';

        return $base.DIRECTORY_SEPARATOR.'outcomes.jsonl';
    }

    /**
     * Record an outcome for a previously emitted dispatch arm. Outcomes are
     * append-only and provider-safe — no aggregate claim, no winner declaration.
     * Consumer (gateway / runner) records what actually happened so the Swarm
     * can learn over time which arm/provider/role combos earn honest evidence.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordOutcome(array $input): array
    {
        $dispatchId = (string) ($input['dispatch_id'] ?? '');
        $armId = (string) ($input['arm_id'] ?? '');
        if ($dispatchId === '' || $armId === '') {
            throw new InvalidArgumentException('dispatch_id and arm_id are required.');
        }
        $status = (string) ($input['status'] ?? '');
        if (! in_array($status, self::VALID_OUTCOME_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown outcome status '{$status}'.");
        }
        $latencyMs = isset($input['latency_ms']) ? (int) $input['latency_ms'] : null;
        $rationale = (string) ($input['rationale'] ?? '');

        $envelope = [
            'schema_version' => self::OUTCOME_SCHEMA,
            'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'dispatch_id' => $dispatchId,
            'arm_id' => $armId,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'rationale' => $rationale,
            'claim_policy' => [
                'aggregate_winner_claim_allowed' => false,
                'rivals_claim_allowed' => false,
            ],
        ];
        $envelope['outcome_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::OUTCOME_SCHEMA,
            'dispatch_id' => $dispatchId,
            'arm_id' => $armId,
            'status' => $status,
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->outcomesLogPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOutcomes(): array
    {
        return $this->readJsonl($this->outcomesLogPath());
    }

    /**
     * Aggregate counts per (provider × status). Does NOT claim winner.
     *
     * @return array<string,mixed>
     */
    public function outcomeSummary(): array
    {
        $byProviderStatus = [];
        $dispatchesById = [];
        foreach ($this->listDispatches() as $d) {
            $dispatchesById[(string) ($d['dispatch_id'] ?? '')] = $d;
        }
        foreach ($this->listOutcomes() as $o) {
            $dispatch = $dispatchesById[(string) ($o['dispatch_id'] ?? '')] ?? null;
            if ($dispatch === null) {
                continue;
            }
            $provider = 'unknown';
            foreach ((array) ($dispatch['arms'] ?? []) as $arm) {
                if (($arm['arm_id'] ?? null) === ($o['arm_id'] ?? null)) {
                    $provider = (string) ($arm['provider'] ?? 'unknown');
                    break;
                }
            }
            $status = (string) ($o['status'] ?? 'unknown');
            $byProviderStatus[$provider] = $byProviderStatus[$provider] ?? array_fill_keys(self::VALID_OUTCOME_STATUSES, 0);
            if (isset($byProviderStatus[$provider][$status])) {
                $byProviderStatus[$provider][$status]++;
            }
        }

        return [
            'schema_version' => 'atlas.swarm_conductor.outcome_summary.v1',
            'total_outcomes' => count($this->listOutcomes()),
            'by_provider_status' => $byProviderStatus,
            'claim_policy' => [
                'aggregate_winner_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $work
     * @return array<string,mixed>
     */
    public function dispatch(array $work): array
    {
        $task = (string) ($work['task_category'] ?? '');
        $role = (string) ($work['role'] ?? '');
        if ($task === '' || $role === '') {
            throw new InvalidArgumentException('task_category and role are required.');
        }
        $framework = $work['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }
        $requested = $this->clampInt((int) ($work['parallelism'] ?? 2), self::MIN_PARALLELISM, self::MAX_PARALLELISM);
        $scope = (array) ($work['scope'] ?? []);
        $requestedAutonomy = (string) ($work['requested_autonomy'] ?? 'execute_with_approval');

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $dispatchId = 'swarm_'.substr(hash('sha256', $task.'|'.$role.'|'.($framework ?? '').'|'.$generatedAt), 0, 12);

        // 1. Constitutional Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'swarm_dispatch',
            'proposed_effect' => "dispatch K={$requested} arms for task={$task} role={$role}",
            'scope' => $scope,
            'actor' => 'SwarmConductor',
        ]);

        // 2. Autonomy admission.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'swarm_dispatch',
            'proposed_effect' => "dispatch K={$requested} arms for task={$task} role={$role}",
            'scope' => $scope,
            'actor' => 'SwarmConductor',
            'requested_autonomy' => $requestedAutonomy,
        ]);

        // Kernel block ⇒ empty dispatch.
        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            return $this->persistEnvelope($this->buildEnvelope(
                dispatchId: $dispatchId,
                generatedAt: $generatedAt,
                task: $task, role: $role, framework: $framework,
                requested: $requested, effective: 0,
                arms: [],
                kernelDecision: $kernelEnv['decision'],
                admissionDecision: AtlasAutonomyAdmissionService::DECISION_DENY,
            ));
        }

        // 3. ADML recommendation.
        $rec = $this->adml->recommend([
            'task_category' => $task,
            'role' => $role,
            'framework' => $framework,
        ]);

        $signal = (string) ($rec['signal'] ?? '');
        $recommendedProvider = $rec['recommended_provider'] ?? null;
        $recommendedModel = $rec['recommended_model'] ?? null;
        $runnerUpProvider = $rec['runner_up_provider'] ?? null;
        $runnerUpModel = $rec['runner_up_model'] ?? null;

        // insufficient_evidence ⇒ no dispatch.
        if ($signal === 'insufficient_evidence' || $recommendedProvider === null) {
            return $this->persistEnvelope($this->buildEnvelope(
                dispatchId: $dispatchId,
                generatedAt: $generatedAt,
                task: $task, role: $role, framework: $framework,
                requested: $requested, effective: 0,
                arms: [],
                kernelDecision: $kernelEnv['decision'],
                admissionDecision: AtlasAutonomyAdmissionService::DECISION_DENY,
            ));
        }

        // 4. Build arms.
        $arms = [];
        $arms[] = $this->buildArm(1, (string) $recommendedProvider, (string) ($recommendedModel ?? ''), self::ARM_ORIGIN_RECOMMENDED, $dispatchId);
        if ($requested >= 2 && $runnerUpProvider !== null) {
            $arms[] = $this->buildArm(2, (string) $runnerUpProvider, (string) ($runnerUpModel ?? ''), self::ARM_ORIGIN_RUNNER_UP, $dispatchId);
        }
        // Elastic invariant gate: operator can flip swarm_local_fallback_enabled=false
        // to suppress the atlas_local fallback arm. The dispatch still ships with
        // primary + runner_up but no local fallback.
        if ($requested >= 3 && $this->kernel->isElasticEnabled('swarm_local_fallback_enabled')) {
            $arms[] = $this->buildArm(count($arms) + 1, self::LOCAL_FALLBACK_PROVIDER, self::LOCAL_FALLBACK_MODEL, self::ARM_ORIGIN_LOCAL_FALLBACK, $dispatchId);
        }
        // K > 3: cap at what we have (no synthetic duplication).

        $effective = count($arms);

        return $this->persistEnvelope($this->buildEnvelope(
            dispatchId: $dispatchId,
            generatedAt: $generatedAt,
            task: $task, role: $role, framework: $framework,
            requested: $requested, effective: $effective,
            arms: $arms,
            kernelDecision: $kernelEnv['decision'],
            admissionDecision: $admissionEnv['decision'],
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listDispatches(): array
    {
        return $this->readJsonl($this->dispatchesLogPath());
    }

    public function lastDispatch(): ?array
    {
        $list = $this->listDispatches();

        return $list === [] ? null : $list[count($list) - 1];
    }

    // ---------- internals ----------

    private function buildArm(int $rank, string $provider, string $model, string $origin, string $dispatchId): array
    {
        $armId = 'arm_'.substr(hash('sha256', $dispatchId.'|'.$rank.'|'.$provider.'|'.$model), 0, 8);

        return [
            'schema_version' => self::ARM_SCHEMA,
            'arm_id' => $armId,
            'rank' => $rank,
            'provider' => $provider,
            'model' => $model,
            'origin' => $origin,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $arms
     * @return array<string,mixed>
     */
    private function buildEnvelope(
        string $dispatchId,
        string $generatedAt,
        string $task,
        string $role,
        ?string $framework,
        int $requested,
        int $effective,
        array $arms,
        string $kernelDecision,
        string $admissionDecision,
    ): array {
        $env = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'dispatch_id' => $dispatchId,
            'generated_at' => $generatedAt,
            'task_category' => $task,
            'role' => $role,
            'framework' => $framework,
            'requested_parallelism' => $requested,
            'effective_parallelism' => $effective,
            'arms' => $arms,
            'kernel_decision' => $kernelDecision,
            'admission_decision' => $admissionDecision,
            'claim_policy' => [
                'aggregate_winner_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $env['dispatch_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'task' => $task,
            'role' => $role,
            'framework' => $framework,
            'effective_parallelism' => $effective,
            'arms' => array_map(static fn ($a) => $a['arm_id'], $arms),
            'kernel_decision' => $kernelDecision,
            'admission_decision' => $admissionDecision,
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    private function persistEnvelope(array $env): array
    {
        $this->appendJsonl($this->dispatchesLogPath(), $env);

        return $env;
    }

    private function clampInt(int $v, int $min, int $max): int
    {
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }

        return $v;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
