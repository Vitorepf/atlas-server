<?php

declare(strict_types=1);

final class ExtremeDifferentiator071Industrial078FlakinessRepeatFixture
{
    public const CASE_ID = 'extreme-differentiator-071-industrial-078-flakiness_repeat';
    public const TASK_TYPE = 'flakiness_repeat';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Investigar flakiness 078 com repeticao estatistica e isolamento de causa.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}