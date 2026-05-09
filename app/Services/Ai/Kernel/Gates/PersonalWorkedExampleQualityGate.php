<?php

namespace App\Services\Ai\Kernel\Gates;

class PersonalWorkedExampleQualityGate
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function evaluate(array $candidate): array
    {
        $signals = (array) ($candidate['quality_signals'] ?? []);

        return match ((string) ($candidate['source_type'] ?? 'unknown')) {
            'programming_pr' => $this->programming($signals),
            'strategic_decision' => $this->strategic($signals),
            'feynman_session' => $this->feynman($signals),
            default => $this->result('blocked', 'quality_unknown_source_type'),
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function programming(array $signals): array
    {
        if (! (bool) ($signals['tests_passed'] ?? false)) {
            return $this->result('blocked', 'quality_pr_tests_failed');
        }

        if (! (bool) ($signals['no_regression_30d'] ?? false)) {
            return $this->result('blocked', 'quality_pr_regression_detected');
        }

        if (trim((string) ($signals['commit_explanation'] ?? '')) === '') {
            return $this->result('blocked', 'quality_pr_no_explanation');
        }

        return $this->result('passed', 'quality_pr_accepted');
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function strategic(array $signals): array
    {
        if (! in_array((string) ($signals['decision_outcome'] ?? 'unknown'), ['success', 'partial_success'], true)) {
            return $this->result('blocked', 'quality_decision_no_positive_outcome');
        }

        return $this->result('passed', 'quality_decision_accepted');
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function feynman(array $signals): array
    {
        if ((float) ($signals['feynman_score'] ?? 0) < 7.0) {
            return $this->result('blocked', 'quality_feynman_below_threshold');
        }

        return $this->result('passed', 'quality_feynman_accepted');
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason): array
    {
        return [
            'schema_version' => 'atlas.gate.personal_worked_example_quality.v1',
            'gate' => 'personal_worked_example_quality',
            'status' => $status,
            'reason' => $reason,
        ];
    }
}
