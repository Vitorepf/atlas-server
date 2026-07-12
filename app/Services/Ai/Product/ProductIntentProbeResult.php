<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

final readonly class ProductIntentProbeResult
{
    public function __construct(
        public bool $available,
        public array $objections = [],
        public ?string $suggestedStatus = null,
        public string $inputHash = '',
        public string $outputHash = '',
    ) {}

    public static function unavailable(ProductIntentCase $case, string $reason = 'provider_unavailable'): self
    {
        return new self(false, [$reason], null, $case->caseHash, MissionCanonicalHash::sha256([$reason]));
    }

    /** @param list<string> $objections */
    public static function available(ProductIntentCase $case, array $objections = [], ?string $suggestedStatus = null): self
    {
        $objections = array_values(array_unique(array_filter(array_map('strval', $objections))));

        return new self(true, $objections, $suggestedStatus, $case->caseHash, MissionCanonicalHash::sha256([
            'objections' => $objections, 'suggested_status' => $suggestedStatus,
        ]));
    }
}
