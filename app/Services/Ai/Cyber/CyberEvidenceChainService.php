<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiCyberEngagement;
use App\Models\AiCyberEvidenceChainEntry;
use Illuminate\Support\Str;

/**
 * Append-only evidence chain per engagement. Each entry hashes its payload
 * together with the previous entry's hash to form a tamper-evident chain.
 */
class CyberEvidenceChainService
{
    public const KIND_AUTHORIZATION = 'authorization';

    public const KIND_SCOPE = 'scope';

    public const KIND_ROE = 'roe';

    public const KIND_LEGAL_REVIEW = 'legal_review';

    public const KIND_PRIVACY_REVIEW = 'privacy_review';

    public const KIND_APPSEC_REVIEW = 'appsec_review';

    public const KIND_GRC_EVIDENCE = 'grc_evidence';

    public const KIND_DEFENSIVE_REVIEW = 'defensive_review';

    public const KIND_REMEDIATION = 'remediation';

    public const KIND_BUG_BOUNTY_INTAKE = 'bug_bounty_intake';

    public const KIND_OPERATOR_DECISION = 'operator_decision';

    public const ALLOWED_KINDS = [
        self::KIND_AUTHORIZATION,
        self::KIND_SCOPE,
        self::KIND_ROE,
        self::KIND_LEGAL_REVIEW,
        self::KIND_PRIVACY_REVIEW,
        self::KIND_APPSEC_REVIEW,
        self::KIND_GRC_EVIDENCE,
        self::KIND_DEFENSIVE_REVIEW,
        self::KIND_REMEDIATION,
        self::KIND_BUG_BOUNTY_INTAKE,
        self::KIND_OPERATOR_DECISION,
    ];

    /**
     * Append an entry to an engagement's evidence chain. The entry hash is a
     * SHA-256 over the canonical payload + previous_hash, forming a chain.
     *
     * @param  array<string,mixed>  $args
     */
    public function append(AiCyberEngagement $engagement, array $args): AiCyberEvidenceChainEntry
    {
        $kind = (string) ($args['entry_kind'] ?? '');
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw CyberDomainException::invalidValue('evidence_chain_entry', 'entry_kind',
                'must be one of ['.implode(',', self::ALLOWED_KINDS).']');
        }
        $actor = (string) ($args['actor'] ?? '');
        if ($actor === '') {
            throw CyberDomainException::missingField('evidence_chain_entry', 'actor');
        }
        $payload = (array) ($args['payload'] ?? []);
        if ($payload === []) {
            throw CyberDomainException::missingField('evidence_chain_entry', 'payload');
        }

        $previous = AiCyberEvidenceChainEntry::query()
            ->where('engagement_id', $engagement->id)
            ->latest('created_at')
            ->first();
        $previousHash = $previous?->entry_hash;

        $hashInput = [
            'engagement_id' => $engagement->id,
            'entry_kind' => $kind,
            'actor' => $actor,
            'payload' => $payload,
            'artifact_refs' => $args['artifact_refs'] ?? [],
            'parent_entry_id' => $previous?->id,
            'previous_hash' => $previousHash,
        ];

        return AiCyberEvidenceChainEntry::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $engagement->id,
            'parent_entry_id' => $previous?->id,
            'entry_kind' => $kind,
            'actor' => $actor,
            'payload' => $payload,
            'artifact_refs' => $args['artifact_refs'] ?? null,
            'previous_hash' => $previousHash,
            'entry_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * Verify chain integrity for a given engagement.
     *
     * @return array<string,mixed>
     */
    public function verify(AiCyberEngagement $engagement): array
    {
        $entries = AiCyberEvidenceChainEntry::query()
            ->where('engagement_id', $engagement->id)
            ->orderBy('created_at')
            ->get();

        $expectedPrev = null;
        $broken = [];
        foreach ($entries as $entry) {
            if ($entry->previous_hash !== $expectedPrev) {
                $broken[] = [
                    'entry_id' => $entry->id,
                    'expected_previous_hash' => $expectedPrev,
                    'actual_previous_hash' => $entry->previous_hash,
                ];
            }
            $expectedPrev = $entry->entry_hash;
        }

        return [
            'engagement_id' => $engagement->id,
            'entry_count' => $entries->count(),
            'broken_links' => $broken,
            'integrity_ok' => $broken === [],
        ];
    }
}
