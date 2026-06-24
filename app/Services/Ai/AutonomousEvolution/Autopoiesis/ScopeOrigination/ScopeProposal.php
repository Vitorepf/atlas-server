<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

use JsonSerializable;

final class ScopeProposal implements JsonSerializable
{
    /**
     * @param  array{
     *   expected_leverage_signal:string,
     *   fact_refs:list<array{fact_id:string,source:string,snapshot_hash:string}>,
     *   objective_text:string,
     *   target_paths:list<string>
     * }  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return array{
     *   content_hash:string,
     *   expected_leverage_signal:string,
     *   fact_refs:list<array{fact_id:string,source:string,snapshot_hash:string}>,
     *   objective_text:string,
     *   target_paths:list<string>
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
     *   content_hash:string,
     *   expected_leverage_signal:string,
     *   fact_refs:list<array{fact_id:string,source:string,snapshot_hash:string}>,
     *   objective_text:string,
     *   target_paths:list<string>
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
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = $this->canonicalize($item);
                }
            }

            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
