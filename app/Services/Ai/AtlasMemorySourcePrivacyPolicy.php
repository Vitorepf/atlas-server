<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryEntry;
use App\Support\AtlasSecurity;

class AtlasMemorySourcePrivacyPolicy
{
    public const POLICY_VERSION = 'atlas_source_privacy_v1';

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     policy_version:string,
     *     source_type:string,
     *     source_category:string,
     *     privacy_class:string,
     *     external_ai_allowed:bool,
     *     provider_safe:bool,
     *     redaction_status:string,
     *     review_required:bool,
     *     reason:string,
     *     fields:array{title:?string,summary:?string,body:?string}
     * }
     */
    public function project(string $sourceType, array $payload = []): array
    {
        $sourceType = $this->sourceType($sourceType);
        $category = $this->category($sourceType);
        $fields = $this->sourceFields($category, $payload);
        $redactedFields = $this->redactedFields($fields);
        $redactionStatus = $this->redactionStatus($fields, $redactedFields);
        $explicitPrivacy = $this->explicitPrivacyClass($payload);
        $privacyClass = $this->privacyClass($explicitPrivacy ?? $this->defaultPrivacyClass($sourceType, $category, $redactionStatus));
        $externalAiAllowed = $this->externalAiAllowed($privacyClass, $this->explicitExternalAiAllowed($payload));
        $reviewRequired = $privacyClass !== 'normal' || $redactionStatus === 'redacted' || ! $externalAiAllowed;

        return [
            'policy_version' => self::POLICY_VERSION,
            'source_type' => $sourceType,
            'source_category' => $category,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'provider_safe' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
            'review_required' => $reviewRequired,
            'reason' => $this->reason($privacyClass, $externalAiAllowed, $redactionStatus, $explicitPrivacy !== null),
            'fields' => $redactedFields,
        ];
    }

    private function sourceType(string $sourceType): string
    {
        $sourceType = strtolower(trim(str_replace('-', '_', $sourceType)));

        return match ($sourceType) {
            'trace', 'ai_trace', 'ai_interaction', 'interaction' => 'ai_trace',
            'note', 'semantic_note', 'semantic_vault', 'vault_note', 'vault' => 'semantic_note',
            'attachment', 'ai_attachment', 'document_attachment', 'image_attachment' => 'ai_attachment',
            'artifact', 'engineering_artifact', 'engineering_run_artifact', 'harness_artifact', 'patch_artifact', 'test_artifact' => 'engineering_artifact',
            'context_bundle', 'ai_context_bundle' => 'context_bundle',
            default => $sourceType !== '' ? $sourceType : 'unknown',
        };
    }

    private function category(string $sourceType): string
    {
        return match ($sourceType) {
            'ai_trace' => 'trace',
            'semantic_note' => 'semantic_vault',
            'ai_attachment' => 'attachment',
            'engineering_artifact' => 'artifact',
            'context_bundle' => 'context_bundle',
            default => 'source',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{title:?string,summary:?string,body:?string}
     */
    private function sourceFields(string $category, array $payload): array
    {
        return match ($category) {
            'trace' => [
                'title' => $this->stringValue($payload['title'] ?? $payload['trace_key'] ?? null),
                'summary' => $this->stringValue($payload['intent'] ?? $payload['status'] ?? null),
                'body' => $this->joinedFields([
                    'operator_input' => $payload['operator_input'] ?? null,
                    'response_text' => $payload['response_text'] ?? null,
                    'feedback_comment' => $payload['feedback_comment'] ?? null,
                ]),
            ],
            'semantic_vault' => [
                'title' => $this->stringValue($payload['title'] ?? $payload['path'] ?? null),
                'summary' => $this->stringValue($payload['summary'] ?? null),
                'body' => $this->stringValue($payload['body_excerpt'] ?? $payload['excerpt'] ?? $payload['body'] ?? null),
            ],
            'attachment' => [
                'title' => $this->stringValue($payload['title'] ?? $payload['source_name'] ?? $payload['name'] ?? null),
                'summary' => $this->stringValue($payload['visual_caption'] ?? $payload['mime_type'] ?? null),
                'body' => $this->stringValue($payload['excerpt'] ?? $payload['content'] ?? $payload['text'] ?? null),
            ],
            'artifact' => [
                'title' => $this->stringValue($payload['title'] ?? $payload['path'] ?? $payload['artifact_path'] ?? null),
                'summary' => $this->joinedFields([
                    'status' => $payload['status'] ?? null,
                    'risk_flags' => $payload['risk_flags'] ?? $payload['risk_flags_json'] ?? null,
                ]),
                'body' => $this->stringValue($payload['diff_excerpt'] ?? $payload['output_excerpt'] ?? $payload['content'] ?? $payload['excerpt'] ?? null),
            ],
            default => [
                'title' => $this->stringValue($payload['title'] ?? $payload['name'] ?? null),
                'summary' => $this->stringValue($payload['summary'] ?? $payload['description'] ?? null),
                'body' => $this->stringValue($payload['body'] ?? $payload['content'] ?? $payload['excerpt'] ?? null),
            ],
        };
    }

    /**
     * @param  array{title:?string,summary:?string,body:?string}  $fields
     * @return array{title:?string,summary:?string,body:?string}
     */
    private function redactedFields(array $fields): array
    {
        return [
            'title' => $this->redactedString($fields['title']),
            'summary' => $this->redactedString($fields['summary']),
            'body' => $this->redactedString($fields['body']),
        ];
    }

    private function redactedString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return AtlasSecurity::redactString(trim($value));
    }

    /**
     * @param  array{title:?string,summary:?string,body:?string}  $raw
     * @param  array{title:?string,summary:?string,body:?string}  $redacted
     */
    private function redactionStatus(array $raw, array $redacted): string
    {
        foreach (['title', 'summary', 'body'] as $field) {
            if (($raw[$field] ?? null) !== null && $raw[$field] !== $redacted[$field]) {
                return 'redacted';
            }
        }

        return 'clean';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function explicitPrivacyClass(array $payload): ?string
    {
        foreach ([
            $payload['privacy_class'] ?? null,
            $payload['privacy'] ?? null,
            $payload['sensitivity'] ?? null,
            data_get($payload, 'metadata.privacy.class'),
            data_get($payload, 'frontmatter.privacy_class'),
            data_get($payload, 'frontmatter.privacy'),
            data_get($payload, 'frontmatter.sensitivity'),
            data_get($payload, 'frontmatter.atlas.privacy_class'),
        ] as $candidate) {
            if (is_string($candidate) && in_array($candidate, AtlasMemoryEntry::PRIVACY_CLASSES, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function explicitExternalAiAllowed(array $payload): mixed
    {
        foreach ([
            $payload['external_ai_allowed'] ?? null,
            data_get($payload, 'metadata.privacy.external_ai_allowed'),
            data_get($payload, 'frontmatter.external_ai_allowed'),
            data_get($payload, 'frontmatter.atlas.external_ai_allowed'),
        ] as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function defaultPrivacyClass(string $sourceType, string $category, string $redactionStatus): string
    {
        if ($redactionStatus === 'redacted') {
            return 'secret';
        }

        $configured = config("atlas.privacy.non_registry_source_defaults.{$sourceType}");
        if (is_string($configured) && in_array($configured, AtlasMemoryEntry::PRIVACY_CLASSES, true)) {
            return $configured;
        }

        return match ($category) {
            'trace', 'attachment', 'artifact', 'context_bundle' => 'private',
            'semantic_vault' => 'normal',
            default => 'normal',
        };
    }

    private function privacyClass(mixed $privacyClass): string
    {
        $privacyClass = is_string($privacyClass) ? $privacyClass : 'normal';

        return in_array($privacyClass, AtlasMemoryEntry::PRIVACY_CLASSES, true) ? $privacyClass : 'normal';
    }

    private function externalAiAllowed(string $privacyClass, mixed $requested): bool
    {
        $blocked = array_values(array_unique(array_merge(
            (array) config('atlas.privacy.block_external_ai_for_sensitivity', []),
            ['secret'],
        )));

        if (in_array($privacyClass, $blocked, true)) {
            return false;
        }

        return $requested === null ? true : filter_var($requested, FILTER_VALIDATE_BOOL);
    }

    private function reason(string $privacyClass, bool $externalAiAllowed, string $redactionStatus, bool $explicitPrivacy): string
    {
        $reasons = [];
        $reasons[] = $explicitPrivacy ? 'explicit_privacy_class='.$privacyClass : 'default_privacy_class='.$privacyClass;
        if (! $externalAiAllowed) {
            $reasons[] = 'external_ai_blocked';
        }
        if ($redactionStatus === 'redacted') {
            $reasons[] = 'redacted_content';
        }

        return implode(', ', $reasons);
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        if (is_array($value)) {
            $encoded = json_encode(AtlasSecurity::redactArray($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) && $encoded !== '[]' ? $encoded : null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $fields
     */
    private function joinedFields(array $fields): ?string
    {
        $parts = [];
        foreach ($fields as $label => $value) {
            $string = $this->stringValue($value);
            if ($string !== null) {
                $parts[] = $label.': '.$string;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }
}
