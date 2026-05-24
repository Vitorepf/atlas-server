<?php

declare(strict_types=1);

final class ExtremeDifferentiator041Industrial104FlakinessRepeatFixture
{
    public const CASE_ID = 'extreme-differentiator-041-industrial-104-flakiness_repeat';
    public const TASK_TYPE = 'flakiness_repeat';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Investigar flakiness 104 com repeticao estatistica e isolamento de causa.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}