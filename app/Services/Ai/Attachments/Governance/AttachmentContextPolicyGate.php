<?php

declare(strict_types=1);

namespace App\Services\Ai\Attachments\Governance;

/**
 * Attachments / multimodal — Context Policy Gate.
 *
 * Closes the matrix gap "Attachments / multimodal input: Garantir que
 * tudo entre por Atlas Input/context policy". Attachments already have
 * services (`AttachmentIndex`, chunked upload, tests). This gate is the
 * single chokepoint that says: an attachment enters AI context ONLY
 * through this gate, with declared:
 *
 *   - kind (image|document|audio|video|url|text_block|code)
 *   - mime_type (canonical, no wildcards)
 *   - size budget (bytes), respected against per-kind cap
 *   - privacy class (personal|operational|aggregate)
 *   - source_authority (operator_upload|tool_call|external_url)
 *   - content_hash (sha256 of payload)
 *
 * Refuses attachments that violate any invariant. NEVER drops payloads
 * silently — caller MUST receive the rejection envelope.
 */
final class AttachmentContextPolicyGate
{
    public const SCHEMA_VERSION = 'atlas.attachments.context_policy_gate.v1';

    public const ALLOWED_KINDS = ['image', 'document', 'audio', 'video', 'url', 'text_block', 'code'];

    public const ALLOWED_SOURCE_AUTHORITIES = ['operator_upload', 'tool_call', 'external_url', 'capture_pipeline'];

    public const ALLOWED_PRIVACY_CLASSES = ['personal', 'operational', 'aggregate'];

    /** Per-kind size cap in bytes. */
    public const SIZE_CAPS = [
        'image' => 25 * 1024 * 1024,        // 25 MB
        'document' => 50 * 1024 * 1024,     // 50 MB
        'audio' => 200 * 1024 * 1024,       // 200 MB
        'video' => 500 * 1024 * 1024,       // 500 MB
        'url' => 2 * 1024,                  // 2 KB (URL string)
        'text_block' => 200 * 1024,         // 200 KB
        'code' => 200 * 1024,               // 200 KB
    ];

    /**
     * @param  array<string,mixed>  $attachment
     * @return array{
     *   schema_version: string,
     *   attachment_id: ?string,
     *   gate_decision: string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $attachment): array
    {
        $passed = [];
        $failed = [];

        $kind = (string) ($attachment['kind'] ?? '');
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            $failed['kind'] = sprintf(
                'invalid "%s"; must be one of [%s]',
                $kind,
                implode(',', self::ALLOWED_KINDS),
            );
        } else {
            $passed[] = 'kind_valid';
        }

        $mime = (string) ($attachment['mime_type'] ?? '');
        if ($mime === '' || str_contains($mime, '*')) {
            $failed['mime_type'] = 'mime_type required, no wildcards allowed';
        } else {
            $passed[] = 'mime_type_canonical';
        }

        $size = $attachment['size_bytes'] ?? null;
        if (! is_int($size) || $size <= 0) {
            $failed['size_bytes'] = 'must be positive int';
        } else {
            $cap = self::SIZE_CAPS[$kind] ?? null;
            if ($cap !== null && $size > $cap) {
                $failed['size_bytes'] = sprintf(
                    'size %d bytes exceeds cap %d bytes for kind "%s"',
                    $size,
                    $cap,
                    $kind,
                );
            } else {
                $passed[] = 'size_within_cap';
            }
        }

        $privacy = (string) ($attachment['privacy_class'] ?? '');
        if (! in_array($privacy, self::ALLOWED_PRIVACY_CLASSES, true)) {
            $failed['privacy_class'] = sprintf(
                'invalid "%s"; must be one of [%s]',
                $privacy,
                implode(',', self::ALLOWED_PRIVACY_CLASSES),
            );
        } else {
            $passed[] = 'privacy_class_valid';
        }

        $authority = (string) ($attachment['source_authority'] ?? '');
        if (! in_array($authority, self::ALLOWED_SOURCE_AUTHORITIES, true)) {
            $failed['source_authority'] = sprintf(
                'invalid "%s"; must be one of [%s]',
                $authority,
                implode(',', self::ALLOWED_SOURCE_AUTHORITIES),
            );
        } else {
            $passed[] = 'source_authority_valid';
        }

        $hash = (string) ($attachment['content_hash'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $failed['content_hash'] = 'content_hash must be sha256 hex (64 chars)';
        } else {
            $passed[] = 'content_hash_canonical';
        }

        // External URL must declare specific anti-SSRF policy ack
        if ($authority === 'external_url' && ($attachment['anti_ssrf_acknowledged'] ?? null) !== true) {
            $failed['anti_ssrf_acknowledged'] = 'external_url attachments require anti_ssrf_acknowledged=true';
        }

        $decision = $failed === [] ? 'admitted_to_context' : 'rejected_at_gate';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'attachment_id' => isset($attachment['attachment_id']) && is_string($attachment['attachment_id'])
                ? $attachment['attachment_id']
                : null,
            'gate_decision' => $decision,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'detail' => $decision === 'admitted_to_context'
                ? sprintf('Attachment "%s" admitted to AI context.', $attachment['attachment_id'] ?? 'unknown')
                : sprintf('Attachment rejected: %d check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
