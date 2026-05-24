<?php

declare(strict_types=1);

final class Ceiling360114Industrial170AmbiguousBugV3Fixture
{
    public const CASE_ID = 'ceiling-360-114-industrial-170-ambiguous_bug-v3';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 170, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}