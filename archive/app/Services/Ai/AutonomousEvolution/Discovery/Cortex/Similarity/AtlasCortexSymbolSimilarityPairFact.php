<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity;

use JsonSerializable;

final class AtlasCortexSymbolSimilarityPairFact implements JsonSerializable
{
    /**
     * @param  array<string, string>  $fingerprints
     */
    public function __construct(
        public readonly string $pairA,
        public readonly string $pairB,
        public readonly float $tokenOverlapRatio,
        public readonly float $astShapeOverlapRatio,
        public readonly int $methodNameOverlapCount,
        public readonly float $methodNameOverlapRatio,
        public readonly array $fingerprints,
    ) {
    }

    /**
     * @return array{
     *     pair_a:string,
     *     pair_b:string,
     *     token_overlap_ratio:float,
     *     ast_shape_overlap_ratio:float,
     *     method_name_overlap_count:int,
     *     method_name_overlap_ratio:float,
     *     fingerprints:array<string,string>
     * }
     */
    public function toArray(): array
    {
        $fingerprints = $this->fingerprints;
        ksort($fingerprints, SORT_STRING);

        return [
            'pair_a' => $this->pairA,
            'pair_b' => $this->pairB,
            'token_overlap_ratio' => $this->tokenOverlapRatio,
            'ast_shape_overlap_ratio' => $this->astShapeOverlapRatio,
            'method_name_overlap_count' => $this->methodNameOverlapCount,
            'method_name_overlap_ratio' => $this->methodNameOverlapRatio,
            'fingerprints' => $fingerprints,
        ];
    }

    /**
     * @return array{
     *     pair_a:string,
     *     pair_b:string,
     *     token_overlap_ratio:float,
     *     ast_shape_overlap_ratio:float,
     *     method_name_overlap_count:int,
     *     method_name_overlap_ratio:float,
     *     fingerprints:array<string,string>
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
