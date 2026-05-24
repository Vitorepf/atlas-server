<?php

declare(strict_types=1);

final class MetaProviderStress044Industrial052FlakinessRepeatFixture
{
    public const CASE_ID = 'meta-provider-stress-044-industrial-052-flakiness_repeat';
    public const TASK_TYPE = 'flakiness_repeat';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Investigar flakiness 052 com repeticao estatistica e isolamento de causa.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}