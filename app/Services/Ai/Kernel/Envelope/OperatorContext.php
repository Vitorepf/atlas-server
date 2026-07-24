<?php

namespace App\Services\Ai\Kernel\Envelope;

final readonly class OperatorContext
{
    use EnvelopeStringHelper;

    /**
     * @param  array<string,mixed>  $preferences
     */
    public function __construct(
        public string $operatorId,
        public string $tenantId,
        public string $workspace,
        public string $defaultPrivacy,
        public array $preferences = [],
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $operatorId = self::string($input['operator_id'] ?? 'vitor');
        $tenantId = self::string($input['tenant_id'] ?? $operatorId);
        $workspace = self::string($input['workspace'] ?? base_path());
        $privacy = self::string($input['default_privacy'] ?? 'normal');

        return new self(
            operatorId: $operatorId !== '' ? $operatorId : 'vitor',
            tenantId: $tenantId !== '' ? $tenantId : 'vitor',
            workspace: $workspace !== '' ? $workspace : base_path(),
            defaultPrivacy: in_array($privacy, ['normal', 'private', 'sensitive', 'secret'], true) ? $privacy : 'normal',
            preferences: is_array($input['preferences'] ?? null) ? $input['preferences'] : [],
        );
    }
}
