<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction;

/**
 * Immutable result of applying a redaction policy to one evidence payload.
 *
 *   payload         — post-redaction payload (same shape as the original: string or array)
 *   content_hash    — sha256(salt || canonical original) — deterministic over (kind,payload) but
 *                     never reveals the raw value (the salt is a fixed AAEL constant)
 *   rule_hits       — per-rule hit count map keyed by a deterministic rule key
 *   evidence_kind   — echoed for downstream auditors
 */
final class RedactedEvidence
{
    public const CONTENT_HASH_SALT = 'atlas.aael.evidence.redaction.v1';

    /**
     * @param  string|array<int|string,mixed>  $payload
     * @param  array<string,int>  $ruleHits
     */
    public function __construct(
        public readonly string|array $payload,
        public readonly string $contentHash,
        public readonly array $ruleHits,
        public readonly string $evidenceKind,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'content_hash' => $this->contentHash,
            'evidence_kind' => $this->evidenceKind,
            'payload' => $this->payload,
            'rule_hits' => $this->ruleHits,
        ];
    }
}
