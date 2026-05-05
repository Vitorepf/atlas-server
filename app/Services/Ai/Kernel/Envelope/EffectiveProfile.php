<?php

namespace App\Services\Ai\Kernel\Envelope;

final readonly class EffectiveProfile
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function __construct(
        public ?string $profileId,
        public ?string $policyProfileId,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            profileId: self::optionalString($payload['profile_id'] ?? null),
            policyProfileId: self::optionalString($payload['policy_profile_id'] ?? null),
            attributes: $payload,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->attributes, [
            'profile_id' => $this->profileId,
            'policy_profile_id' => $this->policyProfileId,
        ]);
    }

    private static function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
