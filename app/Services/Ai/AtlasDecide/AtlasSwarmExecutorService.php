<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use InvalidArgumentException;

/**
 * Atlas Swarm Executor Service — Patamar 4 fan-out/fan-in real.
 *
 * SwarmConductor (dispatch planner) já existe. Este service executa de fato
 * cada arm cross-provider DENTRO DE UM ÚNICO TURN lógico:
 *
 *   1. Recebe envelope swarm dispatch (com K arms)
 *   2. Fan-out: para cada arm, invoca o resolver injetável (operator
 *      wira AiProviderManager → AiProvider->run em produção; tests
 *      passam stub)
 *   3. Fan-in: agrega outcomes (success | failure | timeout | latency |
 *      quality_score) por arm
 *   4. Tie-break: success > failure; entre successes, maior quality_score;
 *      empate → menor latency; empate → menor arm_rank
 *   5. Merge: emite winner + reasons + ledger Live Outcome Feedback
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-swarm-executor.md
 *
 * Schemas:
 *   - atlas.swarm.execution_envelope.v1
 *   - atlas.swarm.arm_outcome.v1
 *
 * Invariants:
 *   - Cada arm registra outcome via Live Outcome Feedback ledger.
 *   - Tie-break é determinístico canon.
 *   - claim_policy provider-safe.
 *   - Append-only JSONL.
 */
final class AtlasSwarmExecutorService
{
    public const ENVELOPE_SCHEMA = 'atlas.swarm.execution_envelope.v1';

    public const OUTCOME_SCHEMA = 'atlas.swarm.arm_outcome.v1';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_NO_WINNER = 'no_winner';

    private ?string $logPathOverride = null;

    /**
     * Resolver: `function(array $arm, array $context): array` returning
     *   ['result' => success|failure|timeout, 'latency_ms' => int,
     *    'quality_score' => ?float, 'output' => string]
     * Operator wires real one via setResolver().
     *
     * @var Closure|null
     */
    private ?Closure $resolver = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasDecideLiveOutcomeFeedbackService $feedback,
    ) {}

    public function setResolver(?Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'swarm_executions.jsonl';
    }

    /**
     * Execute swarm dispatch envelope.
     *
     * @param  array<string,mixed>  $dispatchEnvelope    output de AtlasSwarmConductorService.dispatch()
     * @param  array<string,mixed>  $context             ['task_category', 'role', 'framework', 'privacy_class', 'input']
     * @return array<string,mixed>
     */
    public function execute(array $dispatchEnvelope, array $context = []): array
    {
        if ($this->resolver === null) {
            throw new InvalidArgumentException('resolver not wired — call setResolver() with a Closure(arm, ctx) before execute.');
        }
        $arms = (array) ($dispatchEnvelope['arms'] ?? []);
        if ($arms === []) {
            throw new InvalidArgumentException('dispatch envelope has no arms to execute.');
        }

        $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $taskCategory = (string) ($context['task_category'] ?? 'unspecified');
        $role = (string) ($context['role'] ?? 'primary');
        $framework = $context['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }

        $outcomes = [];
        foreach ($arms as $arm) {
            $armId = (string) ($arm['arm_id'] ?? '');
            $provider = (string) ($arm['provider'] ?? '');
            $model = (string) ($arm['model'] ?? '');
            $origin = (string) ($arm['origin'] ?? '');
            $rank = (int) ($arm['rank'] ?? 0);

            try {
                $invoked = ($this->resolver)($arm, $context);
                $result = (string) ($invoked['result'] ?? 'failure');
                $latency = isset($invoked['latency_ms']) ? max(0, (int) $invoked['latency_ms']) : null;
                $quality = isset($invoked['quality_score'])
                    ? max(0.0, min(1.0, (float) $invoked['quality_score']))
                    : null;
                $output = (string) ($invoked['output'] ?? '');
            } catch (\Throwable $e) {
                $result = self::STATUS_FAILURE;
                $latency = null;
                $quality = null;
                $output = 'resolver_error: '.substr($e->getMessage(), 0, 120);
            }

            // Record outcome to Live Feedback ledger (best-effort).
            try {
                $this->feedback->record([
                    'task_category' => $taskCategory,
                    'role' => $role,
                    'framework' => $framework,
                    'provider' => $provider,
                    'model' => $model,
                    'result' => $result,
                    'latency_ms' => $latency,
                    'quality_score' => $quality,
                    'actor' => 'swarm_executor',
                ]);
            } catch (\Throwable $e) {
                // Defensive — feedback failure must not break execution.
            }

            $outcomes[] = [
                'schema_version' => self::OUTCOME_SCHEMA,
                'arm_id' => $armId,
                'rank' => $rank,
                'origin' => $origin,
                'provider' => $provider,
                'model' => $model,
                'result' => $result,
                'latency_ms' => $latency,
                'quality_score' => $quality,
                'output_hash' => $output === '' ? null : 'sha256:'.hash('sha256', $output),
            ];
        }

        $winner = $this->tieBreak($outcomes);
        $envelope = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'started_at' => $startedAt,
            'dispatch_id' => $dispatchEnvelope['dispatch_id'] ?? null,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'arm_count' => count($outcomes),
            'outcomes' => $outcomes,
            'winner' => $winner,
            'kernel_hash' => $this->kernel->kernelHash(),
        ];
        $envelope['execution_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'started_at' => $startedAt,
            'dispatch_id' => $envelope['dispatch_id'],
            'winner_arm_id' => $winner['arm_id'] ?? null,
            'arm_count' => count($outcomes),
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * Canonical tie-break:
     *   1. Prefer outcomes with result=success
     *   2. Among successes, highest quality_score (null treated as 0)
     *   3. Tie → lowest latency_ms (null treated as +inf)
     *   4. Tie → lowest rank
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    public function tieBreak(array $outcomes): array
    {
        $successes = array_values(array_filter(
            $outcomes,
            static fn ($o): bool => ($o['result'] ?? '') === self::STATUS_SUCCESS
        ));

        if ($successes === []) {
            return [
                'arm_id' => null,
                'reason' => 'no_success_in_arms',
                'result' => self::STATUS_NO_WINNER,
            ];
        }

        usort($successes, static function ($a, $b): int {
            $qa = $a['quality_score'] ?? 0.0;
            $qb = $b['quality_score'] ?? 0.0;
            if ($qa !== $qb) {
                return $qb <=> $qa; // higher quality first
            }
            $la = $a['latency_ms'] ?? PHP_INT_MAX;
            $lb = $b['latency_ms'] ?? PHP_INT_MAX;
            if ($la !== $lb) {
                return $la <=> $lb; // lower latency first
            }

            return ($a['rank'] ?? 0) <=> ($b['rank'] ?? 0);
        });

        $top = $successes[0];

        return [
            'arm_id' => $top['arm_id'] ?? null,
            'provider' => $top['provider'] ?? null,
            'model' => $top['model'] ?? null,
            'rank' => $top['rank'] ?? null,
            'quality_score' => $top['quality_score'] ?? null,
            'latency_ms' => $top['latency_ms'] ?? null,
            'result' => self::STATUS_SUCCESS,
            'reason' => 'highest_quality_lowest_latency',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listExecutions(): array
    {
        return AppendOnlyJsonlStore::read($this->logPath());
    }

    public function lastExecution(): ?array
    {
        $list = $this->listExecutions();

        return $list === [] ? null : $list[count($list) - 1];
    }

    // ---------- internals ----------
}
