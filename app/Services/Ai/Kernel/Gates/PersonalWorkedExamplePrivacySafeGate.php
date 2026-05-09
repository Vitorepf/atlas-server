<?php

namespace App\Services\Ai\Kernel\Gates;

class PersonalWorkedExamplePrivacySafeGate
{
    /**
     * @param  array<string,mixed>  $serializedExample
     * @return array<string,mixed>
     */
    public function evaluate(array $serializedExample): array
    {
        $haystack = strtolower(json_encode($serializedExample['solution_full'] ?? [], JSON_THROW_ON_ERROR).' '.($serializedExample['problem_context'] ?? ''));
        $redaction = (array) ($serializedExample['redaction_applied'] ?? []);
        $privacyClass = (int) ($serializedExample['privacy_class'] ?? 1);

        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $haystack)) {
            return $this->result('blocked', 'privacy_pii_detected_redaction_required');
        }

        if (preg_match('/\b(?:(?:api[_-]?key|token|secret)\s*=\s*[a-z0-9._-]{12,}|(?:sk|pk|api|secret|token)[-_]?[a-z0-9]{12,})\b/i', $haystack)) {
            return $this->result('blocked', 'privacy_secrets_detected');
        }

        if ($privacyClass >= 3 && $redaction === []) {
            return $this->result('blocked', 'privacy_class_3_requires_redaction');
        }

        return $this->result('passed', 'privacy_personal_worked_example_safe');
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason): array
    {
        return [
            'schema_version' => 'atlas.gate.personal_worked_example_privacy_safe.v1',
            'gate' => 'personal_worked_example_privacy_safe',
            'status' => $status,
            'reason' => $reason,
        ];
    }
}
