<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for provider and model attribution per workcell lane.
 *
 * Step 1 of wiring attribution into {@see AreaFocusCycleRecorderService}:
 * shape only — no recorder wiring in this class.
 */
final class ProviderAndModelAttributionPerLaneContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.provider_and_model_attribution_per_lane.v1';

    /** @var list<string> */
    public const LANE_ROLES = [
        'context_scout',
        'architect',
        'implementer',
        'reviewer',
        'judge',
    ];

    /**
     * @param  array<string, array{provider: ?string, model: ?string}>  $lanes
     */
    private function __construct(
        public readonly array $lanes,
    ) {}

    public static function defaults(): self
    {
        $lanes = [];
        foreach (self::LANE_ROLES as $role) {
            $lanes[$role] = ['provider' => null, 'model' => null];
        }

        return new self($lanes);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $defaults = self::defaults()->lanes;
        $rawLanes = is_array($input['lanes'] ?? null) ? $input['lanes'] : [];

        foreach (self::LANE_ROLES as $role) {
            $entry = is_array($rawLanes[$role] ?? null) ? $rawLanes[$role] : [];
            $provider = $entry['provider'] ?? null;
            $model = $entry['model'] ?? null;

            $defaults[$role] = [
                'provider' => is_string($provider) && $provider !== '' ? $provider : null,
                'model' => is_string($model) && $model !== '' ? $model : null,
            ];
        }

        return new self($defaults);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'lanes' => $this->lanes,
        ];
    }
}
