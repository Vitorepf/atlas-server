<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class PostExecutionPhaseEmissionPlanBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.post_execution_phase_emission_plan.v1';

    private const PHASES = [
        'P10' => 'execution',
        'P11' => 'gates',
        'P12' => 'evidence',
        'P13' => 'delivery',
        'P14' => 'human_review',
        'P15' => 'certification',
        'P16' => 'learning',
    ];

    /**
     * @param  array<string,mixed>  $job
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function build(array $job, array $attempt, array $result, array $options = []): array
    {
        if (! $this->enabled($job, $options)) {
            return $this->emptyPlan(false);
        }

        try {
            return $this->buildEnabledPlan($job, $attempt, $result);
        } catch (\Throwable $exception) {
            return array_merge($this->emptyPlan(true), [
                'failed_phase_ids' => array_keys(self::PHASES),
                'exception_policy' => 'never_throw',
                'exception_class' => $exception::class,
            ]);
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $options */
    private function enabled(array $job, array $options): bool
    {
        if (array_key_exists('enabled', $options)) {
            return $options['enabled'] === true;
        }

        if (array_key_exists('post_execution_phase_emit', $options)) {
            return $options['post_execution_phase_emit'] === true;
        }

        return ($job['post_execution_phase_emit'] ?? $job['aaeos_post_execution_phase_emit'] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $job
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildEnabledPlan(array $job, array $attempt, array $result): array
    {
        $ok = ($result['ok'] ?? false) === true;
        $envelopes = [];
        $failedPhaseIds = [];

        foreach (self::PHASES as $phaseId => $phaseName) {
            $status = $this->phaseStatus($phaseId, $phaseName, $ok, $job, $result);
            if (in_array($status, ['failed', 'blocked'], true)) {
                $failedPhaseIds[] = $phaseId;
            }

            $envelopes[] = [
                'schema_version' => 'atlas.aaeos.phase.v1',
                'phase_id' => $phaseId,
                'phase_number' => (int) substr($phaseId, 1),
                'phase_name' => $phaseName,
                'status' => $status,
                'signals' => $this->phaseSignals($phaseId, $job, $attempt, $result, $ok),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => true,
            'envelopes' => $envelopes,
            'failed_phase_ids' => $failedPhaseIds,
            'dispatcher_marker_required' => $envelopes !== [],
            'exception_policy' => 'never_throw',
        ];
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $result */
    private function phaseStatus(string $phaseId, string $phaseName, bool $ok, array $job, array $result): string
    {
        if (! $ok) {
            return $phaseId === 'P10' ? 'failed' : 'blocked';
        }

        if ($phaseId === 'P11' && ($result['quality_ok'] ?? true) === false) {
            return 'failed';
        }

        if ($phaseName === 'human_review' && ! $this->missionJob($job)) {
            return 'skipped';
        }

        if ($phaseName === 'certification' && ($result['foundation_certification_status'] ?? $result['certification_status'] ?? 'ok') === 'failed') {
            return 'failed';
        }

        return 'ok';
    }

    /**
     * @param  array<string,mixed>  $job
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function phaseSignals(string $phaseId, array $job, array $attempt, array $result, bool $ok): array
    {
        $responseHash = $this->stringOrNull($result['response_hash'] ?? $attempt['response_hash'] ?? null);

        return match ($phaseId) {
            'P10' => [
                'execution_log_watchdog_ok' => $ok,
                'duration_ms' => $this->intOrNull($attempt['duration_ms'] ?? $result['duration_ms'] ?? null),
            ],
            'P11' => [
                'universal_15_gates_green_or_exception' => $ok && (($result['quality_ok'] ?? true) !== false),
            ],
            'P12' => [
                'evidence_hash' => $responseHash === null ? null : 'sha256:'.hash('sha256', $responseHash),
            ],
            'P13' => [
                'delivery_pack_hash_signed' => $ok,
            ],
            'P14' => [
                'canonical_skip_non_mission' => ! $this->missionJob($job),
            ],
            'P15' => [
                'foundation_certification_status' => $result['foundation_certification_status'] ?? $result['certification_status'] ?? ($ok ? 'ok' : 'blocked'),
                'certification_severity_acceptable' => $ok && (($result['foundation_certification_status'] ?? $result['certification_status'] ?? 'ok') !== 'failed'),
            ],
            'P16' => [
                'learning_capsule_registered_in_acos' => $ok,
                'ai_trace' => $responseHash,
            ],
            default => [],
        };
    }

    /** @param array<string,mixed> $job */
    private function missionJob(array $job): bool
    {
        return ($job['is_mission'] ?? $job['mission'] ?? false) === true;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function emptyPlan(bool $enabled): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => $enabled,
            'envelopes' => [],
            'failed_phase_ids' => [],
            'dispatcher_marker_required' => false,
            'exception_policy' => 'never_throw',
        ];
    }
}
