<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class PredictiveFailureSafetyGate
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @param  array<string,mixed>  $insertion
     * @param  array<string,mixed>  $load
     * @return array<string,mixed>
     */
    public function evaluate(array $insertion, array $load = []): array
    {
        return $this->slo->measure('cognitive.predictive_failure.safety_gate', function () use ($insertion, $load): array {
            $level = (string) ($load['level'] ?? data_get($insertion, 'signals_used.cognitive_load.level', 'normal'));
            if (in_array($level, ['high', 'critical'], true)) {
                return $this->result('blocked', 'predictive_failure_blocked_high_cognitive_load', $level);
            }

            $privacyClass = (string) data_get($insertion, 'problem_payload.privacy_class', 'p1');
            if ($this->isSensitivePrivacyClass($privacyClass)) {
                return $this->result('blocked', 'predictive_failure_privacy_class_too_high', $level, $privacyClass);
            }

            if ((bool) ($load['high_stress'] ?? false)) {
                return $this->result('blocked', 'predictive_failure_blocked_stress_state', $level, $privacyClass);
            }

            return $this->result('passed', 'predictive_failure_safety_passed', $level, $privacyClass);
        }, [
            'domain' => (string) ($insertion['domain'] ?? 'learning'),
        ]);
    }

    private function isSensitivePrivacyClass(string $privacyClass): bool
    {
        $normalized = strtolower(trim($privacyClass));
        if (in_array($normalized, ['p3', 'p4', 'class_3', 'class_4', '3', '4'], true)) {
            return true;
        }

        if (preg_match('/\d+/', $normalized, $matches) !== 1) {
            return false;
        }

        return (int) $matches[0] >= 3;
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, string $loadLevel, string $privacyClass = 'p1'): array
    {
        return [
            'schema_version' => 'atlas.gate.predictive_failure_safety.v1',
            'gate' => 'predictive_failure_safety',
            'status' => $status,
            'reason' => $reason,
            'cognitive_load_level' => $loadLevel,
            'privacy_class' => $privacyClass,
        ];
    }
}
