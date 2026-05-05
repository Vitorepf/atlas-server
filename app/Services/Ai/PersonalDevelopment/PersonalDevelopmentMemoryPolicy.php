<?php

namespace App\Services\Ai\PersonalDevelopment;

class PersonalDevelopmentMemoryPolicy
{
    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function classify(array $entry): array
    {
        $redactionStatus = (string) ($entry['redaction_status'] ?? 'raw');
        $redactedBody = trim((string) ($entry['redacted_body'] ?? ''));
        $redactedSummary = trim((string) ($entry['redacted_summary'] ?? ''));
        $redactedTitle = trim((string) ($entry['redacted_title'] ?? ''));
        $providerSafe = $redactionStatus === 'redacted' && $redactedBody !== '' && $this->redactedValuesDoNotReuseRaw($entry);

        return [
            'schema_version' => 1,
            'domain' => 'personal_development',
            'privacy_class' => 'private',
            'provider_safe' => $providerSafe,
            'redaction_status' => $redactionStatus,
            'external_ai_allowed' => $providerSafe,
            'storage_policy' => [
                'default_visibility' => 'private',
                'raw_personal_data_leaves_device' => false,
                'provider_context_requires_redaction' => true,
            ],
            'provider_payload' => $providerSafe ? array_filter([
                'title' => $redactedTitle !== '' ? $redactedTitle : null,
                'summary' => $redactedSummary !== '' ? $redactedSummary : null,
                'body' => $redactedBody,
            ], fn (mixed $value): bool => $value !== null && $value !== '') : [],
            'review_required' => ! $providerSafe,
            'review_reasons' => $providerSafe ? [] : $this->reviewReasons($entry, $redactionStatus, $redactedBody),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<int,string>
     */
    private function reviewReasons(array $entry, string $redactionStatus, string $redactedBody): array
    {
        return array_values(array_filter([
            $redactionStatus !== 'redacted' ? 'redaction_required_before_provider_use' : null,
            $redactedBody === '' ? 'redacted_body_required' : null,
            ! $this->redactedValuesDoNotReuseRaw($entry) ? 'redacted_payload_reuses_raw_personal_text' : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function redactedValuesDoNotReuseRaw(array $entry): bool
    {
        foreach ([['title', 'redacted_title'], ['body', 'redacted_body'], ['summary', 'redacted_summary']] as [$rawKey, $redactedKey]) {
            $raw = trim((string) ($entry[$rawKey] ?? ''));
            $redacted = trim((string) ($entry[$redactedKey] ?? ''));

            if ($raw !== '' && $redacted !== '' && hash_equals($raw, $redacted)) {
                return false;
            }
        }

        return true;
    }
}
