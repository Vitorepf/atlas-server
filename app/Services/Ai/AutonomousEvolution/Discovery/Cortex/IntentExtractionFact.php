<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use JsonSerializable;

final class IntentExtractionFact implements JsonSerializable
{
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    public function __construct(
        public readonly string $fqcn,
        public readonly ?string $docblockPurpose,
        public readonly ?string $docblockDesign,
        public readonly ?string $docPath,
        public readonly ?string $docExcerpt,
        public readonly string $confidence,
    ) {
    }

    /**
     * @return array{fqcn:string, docblock_purpose:?string, docblock_design:?string, doc_path:?string, doc_excerpt:?string, confidence:string}
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'docblock_purpose' => $this->docblockPurpose,
            'docblock_design' => $this->docblockDesign,
            'doc_path' => $this->docPath,
            'doc_excerpt' => $this->docExcerpt,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * @return array{fqcn:string, docblock_purpose:?string, docblock_design:?string, doc_path:?string, doc_excerpt:?string, confidence:string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
