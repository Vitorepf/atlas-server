<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use InvalidArgumentException;

final readonly class CapabilityMarketRequest
{
    private function __construct(public array $data, public string $requestHash) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['order_hash','snapshot_hash','authority_hash','risk_class','required_capabilities','topology'] as $key) if (! array_key_exists($key, $data)) throw new InvalidArgumentException('capability_market_'.$key.'_required');
        foreach (['order_hash','snapshot_hash','authority_hash'] as $key) if (preg_match('/^[a-f0-9]{64}$/', (string) $data[$key]) !== 1) throw new InvalidArgumentException('capability_market_'.$key.'_invalid');
        if (! in_array($data['risk_class'], ['R0','R1','R2','R3','R4','R5'], true)) throw new InvalidArgumentException('capability_market_risk_invalid');
        if (! is_array($data['required_capabilities']) || $data['required_capabilities'] === []) throw new InvalidArgumentException('capability_market_capabilities_required');
        if (! in_array($data['topology'], ['single','candidate_set','workcell','DAG','portfolio'], true)) throw new InvalidArgumentException('capability_market_topology_invalid');
        $canonical = $data; ksort($canonical); $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return new self($canonical, $hash);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data + ['request_hash' => $this->requestHash]; }
}
