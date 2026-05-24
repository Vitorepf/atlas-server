<?php

declare(strict_types=1);

final class ExtremeDifferentiator024Industrial026FlakinessRepeatFixture
{
    public const CASE_ID = 'extreme-differentiator-024-industrial-026-flakiness_repeat';
    public const TASK_TYPE = 'flakiness_repeat';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Investigar flakiness 026 com repeticao estatistica e isolamento de causa.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}