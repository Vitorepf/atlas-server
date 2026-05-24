<?php

declare(strict_types=1);

final class ExtremeDifferentiator048Industrial053AmbiguousBugFixture
{
    public const CASE_ID = 'extreme-differentiator-048-industrial-053-ambiguous_bug';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L3';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 053, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}