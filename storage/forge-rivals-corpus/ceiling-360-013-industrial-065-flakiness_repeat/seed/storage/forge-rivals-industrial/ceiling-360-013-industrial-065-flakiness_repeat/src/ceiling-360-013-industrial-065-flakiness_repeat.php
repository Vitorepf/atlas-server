<?php

declare(strict_types=1);

final class Ceiling360013Industrial065FlakinessRepeatFixture
{
    public const CASE_ID = 'ceiling-360-013-industrial-065-flakiness_repeat';
    public const TASK_TYPE = 'flakiness_repeat';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Investigar flakiness 065 com repeticao estatistica e isolamento de causa.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}