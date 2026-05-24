<?php

declare(strict_types=1);

final class Ceiling360061Industrial105AmbiguousBugV2Fixture
{
    public const CASE_ID = 'ceiling-360-061-industrial-105-ambiguous_bug-v2';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 105, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}