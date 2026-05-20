<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * TEOS-I5 · Final local release certification.
 *
 * Aggregates TEOS-I2/I3/I4 surfaces into one honest gate. It does not run
 * benchmarks or providers. If runtime data is missing (for example no world
 * model in the local DB), it reports partial/blockers instead of promoting a
 * false completion claim.
 */
class AtlasTeosFinalCertificationService
{
    public const SCHEMA_VERSION = 'atlas.teos.final_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasTeosIncrement2CertificationService $increment2,
        private readonly StrategicForgettingService $strategicForgetting,
        private readonly ObraReviewService $obraReview,
        private readonly OperatorAttentionQueueService $attentionQueue,
        private readonly TimeAwareWorldModelService $worldModel,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $checks = [
            $this->checkIncrement2(),
            $this->checkStrategicForgetting($now),
            $this->checkObraReview($now, $input['intake'] ?? null),
            $this->checkAttentionQueue($now),
            $this->checkTimeAwareWorldModel($now, $input['world_model_id'] ?? null),
            $this->checkNoExternalClaims(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($checks),
            'generated_at' => $now->toJSON(),
            'summary' => $this->summary($checks),
            'checks' => $checks,
            'blockers' => $this->findings($checks, 'fail'),
            'warnings' => $this->findings($checks, 'warn'),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'declares_teos_complete' => false,
                'scope' => 'teos_i2_i3_i4_local_release_gate',
            ],
            'provider_calls_made' => false,
            'writes' => false,
        ];
        $payload['certification_hash'] = $this->hashCertification($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkIncrement2(): array
    {
        try {
            $payload = $this->increment2->certify();
        } catch (Throwable $exception) {
            return $this->check('increment_2_release_gate', 'fail', 'P0', $exception->getMessage(), 'restore TEOS-I2 certification service');
        }

        return $this->check(
            'increment_2_release_gate',
            ($payload['status'] ?? null) === AtlasTeosIncrement2CertificationService::STATUS_READY ? 'pass' : 'fail',
            'P0',
            'TEOS-I2 release gate status: '.((string) ($payload['status'] ?? 'unknown')),
            'run php artisan atlas:teos:i2-certify --strict --json and fix blockers',
            ['certification_hash' => $payload['certification_hash'] ?? null],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkStrategicForgetting(CarbonImmutable $now): array
    {
        try {
            $payload = $this->strategicForgetting->plan(['now' => $now, 'limit' => 10]);
        } catch (Throwable $exception) {
            return $this->check('strategic_forgetting', 'fail', 'P0', $exception->getMessage(), 'restore strategic forgetting service');
        }

        return $this->check(
            'strategic_forgetting',
            ($payload['status'] ?? null) === StrategicForgettingService::STATUS_READY ? 'pass' : 'fail',
            'P0',
            'Strategic Forgetting status: '.((string) ($payload['status'] ?? 'unknown')),
            'ensure atlas_memory_entries table and strategic forgetting receipt are available',
            ['receipt_hash' => $payload['receipt_hash'] ?? null],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkObraReview(CarbonImmutable $now, mixed $intake): array
    {
        try {
            $payload = $this->obraReview->review(['now' => $now, 'intake' => is_string($intake) ? $intake : null]);
        } catch (Throwable $exception) {
            return $this->check('obra_review', 'fail', 'P0', $exception->getMessage(), 'restore Obra Review service');
        }

        $status = (string) ($payload['status'] ?? 'unknown');

        return $this->check(
            'obra_review',
            $status === ObraReviewService::STATUS_READY ? 'pass' : 'warn',
            'P1',
            'Obra Review status: '.$status,
            'create or select a Forge intake when reviewing live Obras',
            ['review_hash' => $payload['review_hash'] ?? null],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAttentionQueue(CarbonImmutable $now): array
    {
        try {
            $payload = $this->attentionQueue->build(['now' => $now, 'limit' => 20]);
        } catch (Throwable $exception) {
            return $this->check('operator_attention_queue', 'fail', 'P0', $exception->getMessage(), 'restore Operator Attention Queue service');
        }

        return $this->check(
            'operator_attention_queue',
            in_array($payload['status'] ?? null, [OperatorAttentionQueueService::STATUS_READY, OperatorAttentionQueueService::STATUS_WATCH, OperatorAttentionQueueService::STATUS_BLOCKED], true) ? 'pass' : 'fail',
            'P0',
            'Operator Attention Queue emitted status: '.((string) ($payload['status'] ?? 'unknown')),
            'fix operator attention queue payload emission',
            ['queue_hash' => $payload['queue_hash'] ?? null, 'items' => $payload['summary']['total'] ?? null],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTimeAwareWorldModel(CarbonImmutable $now, mixed $worldModelId): array
    {
        try {
            $payload = $this->worldModel->snapshot([
                'now' => $now,
                'world_model_id' => is_string($worldModelId) ? $worldModelId : null,
            ]);
        } catch (Throwable $exception) {
            return $this->check('time_aware_world_model', 'fail', 'P0', $exception->getMessage(), 'restore Time-Aware World Model service');
        }

        $status = (string) ($payload['status'] ?? 'unknown');

        return $this->check(
            'time_aware_world_model',
            $status === TimeAwareWorldModelService::STATUS_READY ? 'pass' : 'warn',
            'P1',
            'Time-Aware World Model status: '.$status,
            'build a codebase world model before declaring final TEOS runtime ready',
            ['snapshot_hash' => $payload['snapshot_hash'] ?? null, 'blockers' => $payload['blockers'] ?? []],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoExternalClaims(): array
    {
        return $this->check(
            'no_external_claims_or_benchmark',
            'pass',
            'P0',
            'No provider, rivals or benchmark execution is part of this certification',
            'operator must explicitly authorize benchmark/rivals before any external comparison',
            ['benchmark_not_run' => true, 'rivals_compared' => false],
        );
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, string $status, string $severity, string $summary, string $remediation, array $evidence = []): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'severity' => $severity,
            'summary' => $summary,
            'remediation' => $remediation,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function status(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) === 'fail' && ($check['severity'] ?? null) === 'P0') {
                return self::STATUS_BLOCKED;
            }
        }
        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== 'pass') {
                return self::STATUS_PARTIAL;
            }
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summary(array $checks): array
    {
        return [
            'total' => count($checks),
            'pass' => count(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'pass')),
            'warn' => count(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'warn')),
            'fail' => count(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $checks, string $status): array
    {
        return array_values(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === $status));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashCertification(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
    }
}
