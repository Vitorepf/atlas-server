<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;


use App\Services\Ai\SelfConstruction\Support\RecursivelyCanonicalizesArrays;
use JsonSerializable;

final class LivingSignalSnapshot implements JsonSerializable
{
    use RecursivelyCanonicalizesArrays;

    /**
     * @param  array{
     *   coherence:float|null,
     *   source_status:array<string,string>,
     *   sources:array<string,list<array<string,mixed>>>,
     *   window_end:string,
     *   window_start:string
     * }  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return array{
     *   coherence:float|null,
     *   content_hash:string,
     *   source_status:array<string,string>,
     *   sources:array<string,list<array<string,mixed>>>,
     *   window_end:string,
     *   window_start:string
     * }
     */
    public function toArray(): array
    {
        $canonical = $this->canonicalize($this->payload);
        $encoded = (string) json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $canonical['content_hash'] = hash('sha256', $encoded);

        return $canonical;
    }

    /**
     * @return array{
     *   coherence:float|null,
     *   content_hash:string,
     *   source_status:array<string,string>,
     *   sources:array<string,list<array<string,mixed>>>,
     *   window_end:string,
     *   window_start:string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
}
