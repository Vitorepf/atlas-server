<?php

namespace App\Services\Ai\VentureFoundry\Comprehension;

use App\Models\AiVentureComprehensionFinding;

/**
 * Capability-agnostic value object for a single comprehension finding.
 *
 * Every capability (business rules, problems, improvements, audience) produces
 * FindingDrafts; the {@see ComprehensionRecorder} persists them uniformly so
 * the whole subsystem shares one queryable shape with mandatory evidence.
 */
final class FindingDraft
{
    /**
     * @param  array<int,array<string,mixed>>|null  $evidenceRefs
     * @param  array<string,mixed>|null  $payload
     */
    public function __construct(
        public readonly string $capability,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $category = null,
        public readonly ?string $detail = null,
        public readonly ?string $severity = null,
        public readonly ?float $impactScore = null,
        public readonly ?float $effortScore = null,
        public readonly ?float $leverageScore = null,
        public readonly ?float $confidence = null,
        public readonly string $evidenceKind = AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
        public readonly ?string $evidencePath = null,
        public readonly ?int $evidenceLine = null,
        public readonly ?string $evidenceSnippet = null,
        public readonly ?array $evidenceRefs = null,
        public readonly ?string $recommendation = null,
        public readonly ?array $payload = null,
        public readonly string $source = 'deterministic',
    ) {}

    /**
     * Natural dedup key (within a run): what makes two findings "the same".
     */
    public function naturalKey(): string
    {
        return implode('|', [
            $this->capability,
            $this->kind,
            $this->category ?? '',
            $this->evidencePath ?? '',
            (string) ($this->evidenceLine ?? ''),
            mb_substr($this->title, 0, 160),
        ]);
    }
}
