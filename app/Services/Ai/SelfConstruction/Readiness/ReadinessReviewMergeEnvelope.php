<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * The one constructor of the review-merge read-only envelope.
 *
 * 52 sites across 8 files hand-wrote this same 30-line block. They looked like 52
 * different shapes because the slug is enormous and appears 15 times in each one —
 * but measured key-set by key-set, all 52 carry exactly the same 14 keys plus a
 * payload and its hash, and all 52 list exactly the same 11 non-execution verbs.
 * Everything else is derived from ONE slug.
 *
 * So a caller supplies the slug, the payload and whether it is ready, and this
 * derives the rest — instead of retyping the slug fifteen times and hoping the
 * fifteen agree. They did not always: a typo in one of the eleven guarantee
 * strings is invisible to review and silently weakens the assertion, because
 * nothing compares the guarantee text to the schema it belongs to.
 *
 * Byte-identical to what the hand-written blocks produced, including key order.
 */
final class ReadinessReviewMergeEnvelope
{
    /**
     * The non-execution guarantees every review-merge envelope asserts, in the
     * order the hand-written blocks listed them. Suffixes only — each is prefixed
     * with the envelope's own slug.
     */
    private const GUARANTEE_VERBS = [
        'does_not_claim_packets',
        'does_not_complete_packets',
        'does_not_create_writer_file',
        'does_not_accept_signature',
        'does_not_validate_signature',
        'does_not_write_ledger',
        'does_not_persist_receipt',
        'does_not_record_decision',
        'does_not_approve_code',
        'does_not_merge',
        'does_not_dispatch_work',
    ];

    /**
     * @param  string  $slug  schema, mode and guarantee identity
     * @param  string  $statusSlug  the (usually shorter) identity `status` reports under
     * @param  string  $payloadKey  the key the payload sits under, e.g. 'observability_contract'
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function project(
        string $slug,
        string $statusSlug,
        bool $ready,
        string $payloadKey,
        array $payload,
        string $humanSummary,
    ): array {
        return [
            'schema_version' => "atlas.self_construction_{$slug}.v1",
            'status' => $ready ? "{$statusSlug}_ready" : "{$statusSlug}_blocked",
            'mode' => "read_only_provider_neutral_{$slug}",
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            $payloadKey => $payload,
            "{$payloadKey}_hash" => ReadinessHash::stable($payload),
            'non_execution_guarantees' => self::guarantees($slug),
            'human_summary' => $humanSummary,
        ];
    }

    /**
     * @return list<string>
     */
    public static function guarantees(string $slug): array
    {
        return array_map(
            static fn (string $verb): string => "{$slug}_{$verb}",
            self::GUARANTEE_VERBS,
        );
    }
}
