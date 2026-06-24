<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity;

use JsonSerializable;

final class AtlasCortexSymbolSimilarityClusterFact implements JsonSerializable
{
    /**
     * @param  list<string>  $members
     * @param  array{min:float,max:float,mean:float}  $tokenOverlap
     * @param  array{min:float,max:float,mean:float}  $astShapeOverlap
     * @param  array{min:float,max:float,mean:float}  $methodNameOverlap
     */
    public function __construct(
        public readonly string $clusterId,
        public readonly array $members,
        public readonly int $edgeCount,
        public readonly array $tokenOverlap,
        public readonly array $astShapeOverlap,
        public readonly array $methodNameOverlap,
    ) {
    }

    /**
     * @return array{
     *     cluster_id:string,
     *     members:list<string>,
     *     edge_count:int,
     *     token_overlap:array{min:float,max:float,mean:float},
     *     ast_shape_overlap:array{min:float,max:float,mean:float},
     *     method_name_overlap:array{min:float,max:float,mean:float}
     * }
     */
    public function toArray(): array
    {
        return [
            'cluster_id' => $this->clusterId,
            'members' => $this->members,
            'edge_count' => $this->edgeCount,
            'token_overlap' => $this->tokenOverlap,
            'ast_shape_overlap' => $this->astShapeOverlap,
            'method_name_overlap' => $this->methodNameOverlap,
        ];
    }

    /**
     * @return array{
     *     cluster_id:string,
     *     members:list<string>,
     *     edge_count:int,
     *     token_overlap:array{min:float,max:float,mean:float},
     *     ast_shape_overlap:array{min:float,max:float,mean:float},
     *     method_name_overlap:array{min:float,max:float,mean:float}
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
