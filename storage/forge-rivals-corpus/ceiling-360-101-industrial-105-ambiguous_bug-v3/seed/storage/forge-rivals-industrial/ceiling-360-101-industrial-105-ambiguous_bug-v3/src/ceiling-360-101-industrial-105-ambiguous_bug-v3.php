<?php

declare(strict_types=1);

final class Ceiling360101Industrial105AmbiguousBugV3Fixture
{
    public const CASE_ID = 'ceiling-360-101-industrial-105-ambiguous_bug-v3';
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