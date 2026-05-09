<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample;

class PersonalWorkedExamplePrivacyRedactor
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array{candidate:array<string,mixed>,redaction:array<string,mixed>}
     */
    public function redact(array $candidate): array
    {
        $redaction = [
            'schema_version' => 'atlas.cognitive.personal_worked_example_redaction.v1',
            'pii_removed' => 0,
            'secrets_removed' => 0,
            'project_names_obfuscated' => 0,
        ];

        foreach (['title', 'problem_context', 'content'] as $field) {
            $value = (string) ($candidate[$field] ?? '');
            [$value, $pii] = $this->replacePattern('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted_email]', $value);
            [$value, $secrets] = $this->replacePattern('/\b(?:(?:api[_-]?key|token|secret)\s*=\s*[A-Za-z0-9._-]{12,}|(?:sk|pk|api|secret|token)[-_]?[A-Za-z0-9]{12,})\b/i', '[redacted_secret]', $value);
            $candidate[$field] = $value;
            $redaction['pii_removed'] += $pii;
            $redaction['secrets_removed'] += $secrets;
        }

        $candidate['raw_steps'] = array_map(function (mixed $step) use (&$redaction): mixed {
            if (! is_array($step)) {
                return $step;
            }

            foreach (['action', 'reasoning', 'why_works'] as $field) {
                $value = (string) ($step[$field] ?? '');
                [$value, $pii] = $this->replacePattern('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted_email]', $value);
                [$value, $secrets] = $this->replacePattern('/\b(?:(?:api[_-]?key|token|secret)\s*=\s*[A-Za-z0-9._-]{12,}|(?:sk|pk|api|secret|token)[-_]?[A-Za-z0-9]{12,})\b/i', '[redacted_secret]', $value);
                $step[$field] = $value;
                $redaction['pii_removed'] += $pii;
                $redaction['secrets_removed'] += $secrets;
            }

            return $step;
        }, (array) ($candidate['raw_steps'] ?? []));

        return [
            'candidate' => $candidate,
            'redaction' => array_merge($redaction, [
                'provider_safe' => true,
                'applied_at' => now()->toIso8601String(),
            ]),
        ];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function replacePattern(string $pattern, string $replacement, string $value): array
    {
        $count = 0;
        $result = preg_replace($pattern, $replacement, $value, -1, $count);

        return [$result ?? $value, $count];
    }
}
