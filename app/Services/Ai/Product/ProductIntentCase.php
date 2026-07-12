<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use InvalidArgumentException;

final readonly class ProductIntentCase
{
    private function __construct(public array $data, public string $caseHash) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['human_request', 'mode', 'risk_class'] as $key) {
            if (! is_string($data[$key] ?? null) || trim((string) $data[$key]) === '') {
                throw new InvalidArgumentException('product_intent_case_'.$key.'_required');
            }
        }
        if (! in_array($data['mode'], ['dev', 'forge', 'autonomos'], true)) {
            throw new InvalidArgumentException('product_intent_case_mode_invalid');
        }
        if (! in_array($data['risk_class'], ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'], true)) {
            throw new InvalidArgumentException('product_intent_case_risk_class_invalid');
        }

        $canonical = [
            'human_request' => trim($data['human_request']),
            'mode' => $data['mode'],
            'risk_class' => $data['risk_class'],
            'problem' => self::stringOrNull($data['problem'] ?? null),
            'user' => self::stringOrNull($data['user'] ?? null),
            'value' => self::stringOrNull($data['value'] ?? null),
            'metric' => self::stringOrNull($data['metric'] ?? null),
            'observation_window' => self::stringOrNull($data['observation_window'] ?? null),
            'source_refs' => self::strings($data['source_refs'] ?? []),
            'world_snapshot_hash' => self::stringOrNull($data['world_snapshot_hash'] ?? null),
            'world_snapshot_status' => self::stringOrNull($data['world_snapshot_status'] ?? null),
            'constraints' => self::list($data['constraints'] ?? []),
            'non_goals' => self::list($data['non_goals'] ?? []),
            'hypotheses' => self::list($data['hypotheses'] ?? []),
            'uncertainties' => self::list($data['uncertainties'] ?? []),
            'alternatives' => self::list($data['alternatives'] ?? []),
            'falsifiers' => self::list($data['falsifiers'] ?? []),
            'side_effects' => is_array($data['side_effects'] ?? null) ? array_values($data['side_effects']) : [],
            'acceptance' => self::list($data['acceptance'] ?? []),
            'release_policy' => is_array($data['release_policy'] ?? null) ? $data['release_policy'] : [],
            'outcome_policy' => is_array($data['outcome_policy'] ?? null) ? $data['outcome_policy'] : [],
        ];
        $hash = MissionCanonicalHash::sha256($canonical);

        return new self($canonical, $hash);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data + ['case_hash' => $this->caseHash];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter((array) $value, static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return array_values((array) $value);
    }
}
