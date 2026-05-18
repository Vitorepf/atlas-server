<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use InvalidArgumentException;

/**
 * One distilled fact from research, with bounded confidence and per-finding
 * source backing. Confidence in [0,1]; `supports` cites source indexes inside
 * the receipt so a reader can re-trace every claim.
 */
final class ResearchFinding
{
    public function __construct(
        public readonly string $findingId,
        public readonly string $claim,
        public readonly float $confidence,
        /** @var list<int> indexes into ResearchReceipt.sources */
        public readonly array $supports,
        public readonly ?string $caveat = null,
    ) {
        if (trim($this->findingId) === '') {
            throw new InvalidArgumentException('ResearchFinding.finding_id must not be empty.');
        }
        if (trim($this->claim) === '') {
            throw new InvalidArgumentException('ResearchFinding.claim must not be empty.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "ResearchFinding.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        if ($this->supports === []) {
            throw new InvalidArgumentException('ResearchFinding.supports must reference at least one source.');
        }
        foreach ($this->supports as $i => $s) {
            if (! is_int($s) || $s < 0) {
                throw new InvalidArgumentException("ResearchFinding.supports[{$i}] must be a non-negative integer source index.");
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'caveat' => $this->caveat,
            'claim' => $this->claim,
            'confidence' => $this->confidence,
            'finding_id' => $this->findingId,
            'supports' => array_values(array_map('intval', $this->supports)),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new InvalidArgumentException('ResearchFinding.confidence must be numeric.');
        }
        if (! array_key_exists('supports', $payload) || ! is_array($payload['supports'])) {
            throw new InvalidArgumentException("Field 'supports' must be a list of integer source indexes.");
        }
        $supports = [];
        foreach ($payload['supports'] as $i => $idx) {
            if (! is_int($idx)) {
                throw new InvalidArgumentException("Field 'supports[{$i}]' must be an integer.");
            }
            $supports[] = $idx;
        }

        return new self(
            findingId: AtlasDevSchemaArray::string($payload, 'finding_id'),
            claim: AtlasDevSchemaArray::string($payload, 'claim'),
            confidence: (float) $payload['confidence'],
            supports: $supports,
            caveat: AtlasDevSchemaArray::nullableString($payload, 'caveat'),
        );
    }
}
