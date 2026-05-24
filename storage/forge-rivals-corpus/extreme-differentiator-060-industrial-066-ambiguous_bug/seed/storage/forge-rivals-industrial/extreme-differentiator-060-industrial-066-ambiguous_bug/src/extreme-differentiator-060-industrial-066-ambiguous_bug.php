<?php

declare(strict_types=1);

final class ExtremeDifferentiator060Industrial066AmbiguousBugFixture
{
    public const CASE_ID = 'extreme-differentiator-060-industrial-066-ambiguous_bug';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L1';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 066, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}