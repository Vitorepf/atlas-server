<?php

namespace App\Services\Ai\Kernel\Architecture;

final readonly class ProviderReleaseSource
{
    /**
     * @param  array<int,string>  $tracks
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $name,
        public string $url,
        public string $tier,
        public string $cadence,
        public array $tracks,
        public string $sourceType = 'official',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'name' => $this->name,
            'url' => $this->url,
            'tier' => $this->tier,
            'cadence' => $this->cadence,
            'tracks' => $this->tracks,
            'source_type' => $this->sourceType,
        ];
    }
}
