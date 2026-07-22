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
            [$candidate[$field], $counts] = $this->redactValue((string) ($candidate[$field] ?? ''));
            $redaction['pii_removed'] += $counts['pii'];
            $redaction['secrets_removed'] += $counts['secrets'];
        }

        $candidate['raw_steps'] = array_map(function (mixed $step) use (&$redaction): mixed {
            if (! is_array($step)) {
                return $step;
            }

            foreach (['action', 'reasoning', 'why_works'] as $field) {
                [$step[$field], $counts] = $this->redactValue((string) ($step[$field] ?? ''));
                $redaction['pii_removed'] += $counts['pii'];
                $redaction['secrets_removed'] += $counts['secrets'];
            }

            return $step;
        }, (array) ($candidate['raw_steps'] ?? []));

        [$candidate['source_metadata'], $metadataCounts] = $this->redactValue((array) ($candidate['source_metadata'] ?? []));
        $redaction['pii_removed'] += $metadataCounts['pii'];
        $redaction['secrets_removed'] += $metadataCounts['secrets'];

        [$candidate['quality_signals'], $qualityCounts] = $this->redactValue((array) ($candidate['quality_signals'] ?? []));
        $redaction['pii_removed'] += $qualityCounts['pii'];
        $redaction['secrets_removed'] += $qualityCounts['secrets'];

        return [
            'candidate' => $candidate,
            'redaction' => array_merge($redaction, [
                'provider_safe' => true,
                'applied_at' => now()->toIso8601String(),
            ]),
        ];
    }

    /**
     * @return array{0:mixed,1:array{pii:int,secrets:int}}
     */
    private function redactValue(mixed $value): array
    {
        if (is_array($value)) {
            $counts = ['pii' => 0, 'secrets' => 0];
            $redacted = [];

            foreach ($value as $key => $item) {
                [$redacted[$key], $itemCounts] = $this->redactValue($item);
                $counts['pii'] += $itemCounts['pii'];
                $counts['secrets'] += $itemCounts['secrets'];
            }

            return [$redacted, $counts];
        }

        if (! is_string($value)) {
            return [$value, ['pii' => 0, 'secrets' => 0]];
        }

        [$value, $pii] = $this->replacePattern('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted_email]', $value);
        [$value, $secrets] = $this->replacePattern('/\b(?:(?:api[_-]?key|token|secret)\s*=\s*[A-Za-z0-9._-]{12,}|(?:sk|pk|api|secret|token)[-_]?[A-Za-z0-9._-]{12,})\b/i', '[redacted_secret]', $value);

        return [$value, ['pii' => $pii, 'secrets' => $secrets]];
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
