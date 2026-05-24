<?php

declare(strict_types=1);

final class ExtremeDifferentiator031Industrial079AmbiguousBugFixture
{
    public const CASE_ID = 'extreme-differentiator-031-industrial-079-ambiguous_bug';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L5';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 079, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}