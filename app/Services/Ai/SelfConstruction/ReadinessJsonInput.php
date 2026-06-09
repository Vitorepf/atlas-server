<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Facades\Storage;

final class ReadinessJsonInput
{
    /** @return array<string, mixed> */
    public static function decodeOption(mixed $value): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
            if (! is_file($absolutePath)) {
                return [];
            }
            $raw = (string) file_get_contents($absolutePath);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function completionEvidenceSubmissionInput(
        array $options,
        string $payloadKey,
        string $jsonKey,
        string $canonicalPath,
        bool $canonicalLoadAllowed,
    ): array {
        $explicitPayload = $options[$payloadKey] ?? null;
        if (is_array($explicitPayload) && $explicitPayload !== []) {
            return [
                'source' => 'explicit_payload',
                'payload' => $explicitPayload,
                'canonical_path' => $canonicalPath,
                'canonical_load_allowed' => $canonicalLoadAllowed,
                'canonical_loaded' => false,
                'status' => 'loaded_from_explicit_payload',
                'violations' => [],
            ];
        }

        $jsonPayload = self::decodeOption($options[$jsonKey] ?? null);
        if ($jsonPayload !== []) {
            return [
                'source' => 'json_option',
                'payload' => $jsonPayload,
                'canonical_path' => $canonicalPath,
                'canonical_load_allowed' => $canonicalLoadAllowed,
                'canonical_loaded' => false,
                'status' => 'loaded_from_json_option',
                'violations' => [],
            ];
        }

        if (! $canonicalLoadAllowed) {
            return [
                'source' => 'none',
                'payload' => [],
                'canonical_path' => $canonicalPath,
                'canonical_load_allowed' => false,
                'canonical_loaded' => false,
                'status' => 'canonical_submission_not_loaded_without_explicit_persist_flag',
                'violations' => [],
            ];
        }

        if (! Storage::disk('local')->exists($canonicalPath)) {
            return [
                'source' => 'none',
                'payload' => [],
                'canonical_path' => $canonicalPath,
                'canonical_load_allowed' => true,
                'canonical_loaded' => false,
                'status' => 'canonical_submission_not_found',
                'violations' => [],
            ];
        }

        try {
            $decoded = json_decode(Storage::disk('local')->get($canonicalPath), true, flags: JSON_THROW_ON_ERROR);
            $payload = is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $payload = [];
        }

        if ($payload === []) {
            return [
                'source' => 'canonical_submission',
                'payload' => [],
                'canonical_path' => $canonicalPath,
                'canonical_load_allowed' => true,
                'canonical_loaded' => false,
                'status' => 'canonical_submission_invalid_json_or_empty',
                'violations' => ['canonical_submission_invalid_json_or_empty'],
            ];
        }

        return [
            'source' => 'canonical_submission',
            'payload' => $payload,
            'canonical_path' => $canonicalPath,
            'canonical_load_allowed' => true,
            'canonical_loaded' => true,
            'status' => 'loaded_from_canonical_submission',
            'violations' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function completionEvidenceSubmissionInputSummary(array $input): array
    {
        $payload = (array) ($input['payload'] ?? []);

        return [
            'source' => (string) ($input['source'] ?? 'none'),
            'status' => (string) ($input['status'] ?? 'unknown'),
            'canonical_path' => (string) ($input['canonical_path'] ?? ''),
            'canonical_load_allowed' => (bool) ($input['canonical_load_allowed'] ?? false),
            'canonical_loaded' => (bool) ($input['canonical_loaded'] ?? false),
            'payload_present' => $payload !== [],
            'payload_json_sha256' => $payload === []
                ? ''
                : hash('sha256', (string) json_encode(ReadinessHash::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'violations' => (array) ($input['violations'] ?? []),
            'non_execution_guarantees' => [
                'canonical_submission_input_loader_does_not_persist_without_explicit_flag',
                'canonical_submission_input_loader_does_not_sign_for_operator',
                'canonical_submission_input_loader_does_not_call_provider',
                'canonical_submission_input_loader_does_not_spend_tokens',
                'canonical_submission_input_loader_does_not_promote_completion',
            ],
        ];
    }
}
